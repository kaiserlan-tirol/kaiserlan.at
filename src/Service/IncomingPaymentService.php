<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use App\Entity\User;
use App\Repository\IncomingPaymentRepository;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

class IncomingPaymentService
{
    private readonly IncomingPaymentRepository $paymentRepository;
    private readonly EntityManagerInterface $em;
    private readonly IdmRepository $userRepo;
    private readonly LoggerInterface $logger;
    private readonly PaymentMatchingService $paymentMatchingService;
    private readonly ShopService $shopService;
    private readonly CateringService $cateringService;

    public function __construct(
        IncomingPaymentRepository $paymentRepository,
        EntityManagerInterface $em,
        IdmManager $idmManager,
        LoggerInterface $logger,
        PaymentMatchingService $paymentMatchingService,
        ShopService $shopService,
        CateringService $cateringService
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->em = $em;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->logger = $logger;
        $this->paymentMatchingService = $paymentMatchingService;
        $this->shopService = $shopService;
        $this->cateringService = $cateringService;
    }

    public function createIncomingPayment(
        float $amount,
        string $currency,
        DateTimeImmutable $timestamp,
        string $source,
        array $metadata = []
    ): IncomingPayment {
        
        // Check for duplicates based on external ID
        if (isset($metadata['externalId'])) {
            $existing = $this->paymentRepository->findByExternalId($metadata['externalId'], $source);
            if ($existing) {
                $this->logger->info('Duplicate payment detected', [
                    'external_id' => $metadata['externalId'],
                    'source' => $source,
                    'existing_payment_id' => $existing->getId()
                ]);
                return $existing;
            }
        }

        $payment = new IncomingPayment();
        $payment->setAmount(number_format($amount, 2, '.', ''))
                ->setCurrency($currency)
                ->setTimestamp($timestamp)
                ->setSource($source)
                ->setMetadata($metadata);

        // Set fields from metadata
        if (isset($metadata['externalId'])) {
            $payment->setExternalId($metadata['externalId']);
        }
        if (isset($metadata['reference'])) {
            $payment->setReference($metadata['reference']);
        }
        if (isset($metadata['payerEmail'])) {
            $payment->setPayerEmail($metadata['payerEmail']);
        }
        if (isset($metadata['payerName'])) {
            $payment->setPayerName($metadata['payerName']);
        }
        if (isset($metadata['payerAccount'])) {
            $payment->setPayerAccount($metadata['payerAccount']);
        }

        // Generate description
        $payment->setDescription($this->generateDescription($payment));

        // Try to automatically match the payment
        $this->tryAutoMatch($payment);

        $this->paymentRepository->save($payment);

        $this->logger->info('New incoming payment created', [
            'payment_id' => $payment->getId(),
            'amount' => $amount,
            'source' => $source,
            'status' => $payment->getStatus(),
            'matched_user' => $payment->getMatchedUser()?->toString()
        ]);

        return $payment;
    }

    public function processPayment(IncomingPayment $payment, ?UuidInterface $processedBy = null): void
    {
        if ($payment->isProcessed()) {
            throw new \InvalidArgumentException('Payment is already processed');
        }

        if (!$payment->getMatchedUser()) {
            throw new \InvalidArgumentException('Payment must be matched to a user before processing');
        }

        $payment->setStatus(IncomingPayment::STATUS_PROCESSED);
        $payment->setProcessedBy($processedBy);
        $payment->setProcessedAt(new DateTimeImmutable());

        $this->paymentRepository->save($payment);

        $this->logger->info('Payment processed', [
            'payment_id' => $payment->getId(),
            'user_id' => $payment->getMatchedUser()->toString(),
            'processed_by' => $processedBy?->toString()
        ]);
    }

    public function matchPaymentToUser(IncomingPayment $payment, UuidInterface $userId, string $confidence = IncomingPayment::CONFIDENCE_MANUAL, ?string $notes = null): void
    {
        $user = $this->userRepo->findOneById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        $payment->setMatchedUser($userId);
        $payment->setMatchConfidence($confidence);
        $payment->setStatus(IncomingPayment::STATUS_MATCHED);
        
        if ($notes) {
            $existingNotes = $payment->getProcessingNotes();
            $newNotes = $existingNotes ? $existingNotes . "\n" . $notes : $notes;
            $payment->setProcessingNotes($newNotes);
        }

        $this->paymentRepository->save($payment);

        $this->logger->info('Payment manually matched to user', [
            'payment_id' => $payment->getId(),
            'user_id' => $userId->toString(),
            'confidence' => $confidence
        ]);
    }

    public function ignorePayment(IncomingPayment $payment, string $reason = 'Manually ignored', ?UuidInterface $ignoredBy = null): void
    {
        $payment->setStatus(IncomingPayment::STATUS_IGNORED);
        
        $notes = "IGNORED: " . $reason;
        $existingNotes = $payment->getProcessingNotes();
        $newNotes = $existingNotes ? $existingNotes . "\n" . $notes : $notes;
        $payment->setProcessingNotes($newNotes);

        $this->paymentRepository->save($payment);

        $this->logger->info('Payment ignored', [
            'payment_id' => $payment->getId(),
            'reason' => $reason,
            'ignored_by' => $ignoredBy?->toString()
        ]);
    }

    /**
     * Get all payments with pagination
     */
    public function getAllPayments(int $page = 1, int $limit = 50): array
    {
        return $this->paymentRepository->findAllPaginated($page, $limit);
    }

    /**
     * Get pending payments with pagination
     */
    public function getPendingPayments(int $page = 1, int $limit = 50): array
    {
        return $this->paymentRepository->findByStatusPaginated('pending', $page, $limit);
    }

    /**
     * Get unmatched payments with pagination
     */
    public function getUnmatchedPayments(int $page = 1, int $limit = 50): array
    {
        return $this->paymentRepository->findUnmatchedPaginated($page, $limit);
    }

    /**
     * Get processed payments with pagination
     */
    public function getProcessedPayments(int $page = 1, int $limit = 50): array
    {
        return $this->paymentRepository->findByStatusPaginated('processed', $page, $limit);
    }

    /**
     * Get ignored payments with pagination
     */
    public function getIgnoredPayments(int $page = 1, int $limit = 50): array
    {
        return $this->paymentRepository->findByStatusPaginated('ignored', $page, $limit);
    }

    /**
     * Get payments by status with pagination
     */
    public function getPaymentsByStatus(string $status, int $page = 1, int $limit = 50): array
    {
        return match($status) {
            'pending' => $this->getPendingPayments($page, $limit),
            'unmatched' => $this->getUnmatchedPayments($page, $limit),
            'processed' => $this->getProcessedPayments($page, $limit),
            'ignored' => $this->getIgnoredPayments($page, $limit),
            default => $this->getAllPayments($page, $limit),
        };
    }

    /**
     * Create a new payment
     */
    public function createPayment(
        float $amount,
        string $source,
        string $reference = '',
        string $senderInfo = '',
        array $metadata = []
    ): IncomingPayment {
        return $this->createIncomingPayment(
            $amount,
            'EUR',
            new DateTimeImmutable(),
            $source,
            array_merge($metadata, [
                'reference' => $reference,
                'senderInfo' => $senderInfo
            ])
        );
    }

    /**
     * Reset payment to pending status
     */
    public function resetPayment(IncomingPayment $payment): void
    {
        $payment->setStatus('pending');
        $payment->setMatchedUser(null);
        $payment->setMatchingConfidence(null);
        $payment->setProcessedAt(null);
        $payment->setProcessedBy(null);
        $payment->setIgnoredAt(null);
        $payment->setIgnoredBy(null);
        $payment->setAdminNotes('');
        $payment->setUpdatedAt(new DateTimeImmutable());

        $this->em->persist($payment);
        $this->em->flush();

        $this->logger->info('Payment reset to pending', [
            'payment_id' => $payment->getId(),
            'amount' => $payment->getAmount(),
            'source' => $payment->getSource()
        ]);
    }

    public function getPaymentStatistics(): array
    {
        return $this->paymentRepository->getStatistics();
    }

    public function findDuplicatePayments(): array
    {
        return $this->paymentRepository->findDuplicatePayments();
    }

    private function generateDescription(IncomingPayment $payment): string
    {
        $parts = [];
        
        $parts[] = sprintf('%.2f %s', $payment->getAmountAsFloat(), $payment->getCurrency());
        $parts[] = 'via ' . $payment->getSourceLabel();
        
        if ($payment->getPayerName()) {
            $parts[] = 'from ' . $payment->getPayerName();
        } elseif ($payment->getPayerEmail()) {
            $parts[] = 'from ' . $payment->getPayerEmail();
        }

        if ($payment->getReference()) {
            $parts[] = 'ref: ' . substr($payment->getReference(), 0, 50);
        }

        return implode(' ', $parts);
    }

    /**
     * Try to automatically match a payment to orders and users
     */
    private function tryAutoMatch(IncomingPayment $payment): void
    {
        try {
            // Step 1: Try to match to user first
            $matched = $this->paymentMatchingService->autoMatchPayment($payment);
            
            if (!$matched) {
                $this->logger->debug('Payment could not be automatically matched to any user', [
                    'payment_id' => $payment->getId(),
                    'amount' => $payment->getAmount(),
                    'reference' => $payment->getReference()
                ]);
                return;
            }

            // Step 2: If matched to user, try to match to specific orders
            $user = $this->userRepo->findOneById($payment->getMatchedUser());
            if (!$user) {
                $this->logger->warning('Matched user not found', [
                    'payment_id' => $payment->getId(),
                    'user_id' => $payment->getMatchedUser()->toString()
                ]);
                return;
            }

            $this->tryMatchToOrders($payment, $user);

        } catch (\Exception $e) {
            $this->logger->error('Error during automatic payment matching', [
                'payment_id' => $payment->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Try to match payment to specific orders based on amount and order IDs
     */
    private function tryMatchToOrders(IncomingPayment $payment, User $user): void
    {
        $paymentAmount = $payment->getAmountAsFloat(); // As float
        $reference = $payment->getReference() ?? '';
        
        // Try to find order ID in reference
        $orderMatched = false;
        
        // Look for shop order ID patterns in reference
        if (preg_match('/(?:order|bestellung|#)\s*(\d+)/i', $reference, $matches)) {
            $orderId = (int) $matches[1];
            $orderMatched = $this->tryMatchToShopOrder($payment, $user, $orderId, $paymentAmount);
        }
        
        // Look for catering order patterns
        if (!$orderMatched && preg_match('/(?:catering|food|essen)\s*(\d+)/i', $reference, $matches)) {
            $orderId = (int) $matches[1];
            $orderMatched = $this->tryMatchToCateringOrder($payment, $user, $orderId, $paymentAmount);
        }
        
        // If no specific order found, try to match by amount
        if (!$orderMatched) {
            $this->tryMatchByAmount($payment, $user, $paymentAmount);
        }
    }

    /**
     * Try to match payment to a specific shop order
     */
    private function tryMatchToShopOrder(IncomingPayment $payment, User $user, int $orderId, float $paymentAmount): bool
    {
        try {
            // Find the specific shop order
            $shopOrders = $this->shopService->getOrderByUser($user, \App\Entity\ShopOrderStatus::Created);
            $targetOrder = null;
            
            foreach ($shopOrders as $order) {
                if ($order->getId() === $orderId) {
                    $targetOrder = $order;
                    break;
                }
            }
            
            if (!$targetOrder) {
                $this->logger->debug('Shop order not found or not open', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                    'user_id' => $user->getUuid()->toString()
                ]);
                return false;
            }
            
            $orderTotal = $targetOrder->calculateTotal();
            
            // Check if amount matches exactly or is close enough (within 5%)
            $amountDifference = abs($paymentAmount - $orderTotal);
            $tolerance = max(50, $orderTotal * 0.05); // 50 cents or 5%, whichever is larger
            
            if ($amountDifference <= $tolerance) {
                // Mark the order as paid
                $this->shopService->setOrderPaid($targetOrder);
                
                $this->addProcessingNote($payment, sprintf(
                    'Auto-matched to shop order #%d (%.2f € vs %.2f €) - Order marked as PAID',
                    $orderId,
                    $paymentAmount / 100,
                    $orderTotal / 100
                ));
                
                $payment->setStatus(IncomingPayment::STATUS_PROCESSED);
                $payment->setProcessedAt(new DateTimeImmutable());
                
                $this->logger->info('Payment auto-matched to specific shop order and marked as paid', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                    'payment_amount' => $paymentAmount,
                    'order_amount' => $orderTotal
                ]);
                
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error matching to shop order', [
                'payment_id' => $payment->getId(),
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }

    /**
     * Try to match payment to a specific catering order
     */
    private function tryMatchToCateringOrder(IncomingPayment $payment, User $user, int $orderId, float $paymentAmount): bool
    {
        try {
            // Find the specific catering order
            $cateringOrders = $this->cateringService->getOrderByUser($user, \App\Entity\CateringOrderStatus::Created);
            $targetOrder = null;
            
            foreach ($cateringOrders as $order) {
                if ($order->getId() === $orderId) {
                    $targetOrder = $order;
                    break;
                }
            }
            
            if (!$targetOrder) {
                $this->logger->debug('Catering order not found or not open', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                    'user_id' => $user->getUuid()->toString()
                ]);
                return false;
            }
            
            $orderTotal = $targetOrder->calculateTotal();
            
            // Check if amount matches exactly or is close enough (within 5%)
            $amountDifference = abs($paymentAmount - $orderTotal);
            $tolerance = max(50, $orderTotal * 0.05); // 50 cents or 5%, whichever is larger
            
            if ($amountDifference <= $tolerance) {
                // Mark the order as paid
                $this->cateringService->setOrderPaid($targetOrder);
                
                $this->addProcessingNote($payment, sprintf(
                    'Auto-matched to catering order #%d (%.2f € vs %.2f €) - Order marked as PAID',
                    $orderId,
                    $paymentAmount / 100,
                    $orderTotal / 100
                ));
                
                $payment->setStatus(IncomingPayment::STATUS_PROCESSED);
                $payment->setProcessedAt(new DateTimeImmutable());
                
                $this->logger->info('Payment auto-matched to specific catering order and marked as paid', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $orderId,
                    'payment_amount' => $paymentAmount,
                    'order_amount' => $orderTotal
                ]);
                
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error matching to catering order', [
                'payment_id' => $payment->getId(),
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }

    /**
     * Try to match payment by amount to open orders
     */
    private function tryMatchByAmount(IncomingPayment $payment, User $user, float $paymentAmount): void
    {
        try {
            // Get all open orders for the user
            $shopOrders = $this->shopService->getOrderByUser($user, \App\Entity\ShopOrderStatus::Created);
            $cateringOrders = $this->cateringService->getOrderByUser($user, \App\Entity\CateringOrderStatus::Created);
            $cateringPaymentSentOrders = $this->cateringService->getOrderByUser($user, \App\Entity\CateringOrderStatus::PaymentSent);
            
            $allOrders = [];
            
            // Add shop orders
            foreach ($shopOrders as $order) {
                $allOrders[] = [
                    'type' => 'shop',
                    'order' => $order,
                    'amount' => $order->calculateTotal(),
                    'id' => $order->getId()
                ];
            }
            
            // Add catering orders (both Created and PaymentSent)
            foreach (array_merge($cateringOrders, $cateringPaymentSentOrders) as $order) {
                $allOrders[] = [
                    'type' => 'catering',
                    'order' => $order,
                    'amount' => $order->calculateTotal(),
                    'id' => $order->getId()
                ];
            }
            
            if (empty($allOrders)) {
                // No orders found - add as catering credit
                $this->addCateringCredit($payment, $user, $paymentAmount);
                return;
            }
            
            // Sort by amount difference (closest match first)
            usort($allOrders, function($a, $b) use ($paymentAmount) {
                $diffA = abs($a['amount'] - $paymentAmount);
                $diffB = abs($b['amount'] - $paymentAmount);
                return $diffA <=> $diffB;
            });
            
            $bestMatch = $allOrders[0];
            $amountDifference = abs($bestMatch['amount'] - $paymentAmount);
            $tolerance = max(100, $bestMatch['amount'] * 0.1); // 1 € or 10%, whichever is larger
            
            if ($amountDifference <= $tolerance) {
                // Good match found - mark the order as paid
                if ($bestMatch['type'] === 'shop') {
                    $this->shopService->setOrderPaid($bestMatch['order']);
                } else {
                    $this->cateringService->setOrderPaid($bestMatch['order']);
                }
                
                $this->addProcessingNote($payment, sprintf(
                    'Auto-matched to %s order #%d by amount (%.2f € vs %.2f €, diff: %.2f €) - Order marked as PAID',
                    $bestMatch['type'],
                    $bestMatch['id'],
                    $paymentAmount / 100,
                    $bestMatch['amount'] / 100,
                    $amountDifference / 100
                ));
                
                $payment->setStatus(IncomingPayment::STATUS_PROCESSED);
                $payment->setProcessedAt(new DateTimeImmutable());
                
                $this->logger->info('Payment auto-matched to order by amount and marked as paid', [
                    'payment_id' => $payment->getId(),
                    'order_type' => $bestMatch['type'],
                    'order_id' => $bestMatch['id'],
                    'payment_amount' => $paymentAmount,
                    'order_amount' => $bestMatch['amount']
                ]);
            } else {
                // Calculate total of all orders
                $totalOpenAmount = array_sum(array_column($allOrders, 'amount'));
                
                if ($paymentAmount >= $totalOpenAmount * 0.8) { // If payment covers at least 80% of all orders
                    $orderList = implode(', ', array_map(fn($o) => $o['type'] . ' #' . $o['id'], array_slice($allOrders, 0, 3)));
                    if (count($allOrders) > 3) {
                        $orderList .= ' + ' . (count($allOrders) - 3) . ' more';
                    }
                    
                    $this->addProcessingNote($payment, sprintf(
                        'Likely payment for multiple orders: %s (total: %.2f €) - Requires manual review',
                        $orderList,
                        $totalOpenAmount / 100
                    ));
                } else {
                    // Amount doesn't match any specific order - add as catering credit
                    $this->addCateringCredit($payment, $user, $paymentAmount);
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error matching payment by amount', [
                'payment_id' => $payment->getId(),
                'user_id' => $user->getUuid()->toString(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Add a processing note to the payment
     */
    private function addProcessingNote(IncomingPayment $payment, string $note): void
    {
        $existingNotes = $payment->getProcessingNotes();
        $newNotes = $existingNotes ? $existingNotes . "\n" . $note : $note;
        $payment->setProcessingNotes($newNotes);
    }

    /**
     * Add payment as catering credit to the user
     */
    private function addCateringCredit(IncomingPayment $payment, User $user, int $paymentAmount): void
    {
        try {
            // Add credit to user's catering account
            $this->cateringService->addUserCredit(
                $user,
                $paymentAmount,
                sprintf(
                    'Zahlung erhalten: %.2f € (Payment ID: %s)',
                    $paymentAmount / 100,
                    $payment->getId()
                )
            );
            
            $this->addProcessingNote($payment, sprintf(
                'Added %.2f € as catering credit - No matching order found',
                $paymentAmount / 100
            ));
            
            $payment->setStatus(IncomingPayment::STATUS_PROCESSED);
            $payment->setProcessedAt(new DateTimeImmutable());
            
            $this->logger->info('Payment added as catering credit', [
                'payment_id' => $payment->getId(),
                'user_id' => $user->getUuid()->toString(),
                'amount' => $paymentAmount
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error adding catering credit', [
                'payment_id' => $payment->getId(),
                'user_id' => $user->getUuid()->toString(),
                'amount' => $paymentAmount,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to manual review
            $this->addProcessingNote($payment, sprintf(
                'ERROR: Could not add %.2f € as catering credit - Requires manual review. Error: %s',
                $paymentAmount / 100,
                $e->getMessage()
            ));
        }
    }
}
