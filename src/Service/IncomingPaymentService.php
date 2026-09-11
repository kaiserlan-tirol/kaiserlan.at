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
    private readonly PaymentProcessingService $paymentProcessingService;

    public function __construct(
        IncomingPaymentRepository $paymentRepository,
        EntityManagerInterface $em,
        IdmManager $idmManager,
        LoggerInterface $logger,
        PaymentMatchingService $paymentMatchingService,
        PaymentProcessingService $paymentProcessingService
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->em = $em;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->logger = $logger;
        $this->paymentMatchingService = $paymentMatchingService;
        $this->paymentProcessingService = $paymentProcessingService;
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

    public function processPayment(IncomingPayment $payment, ?UuidInterface $processedBy = null): array
    {
        if ($payment->isProcessed()) {
            throw new \InvalidArgumentException('Payment is already processed');
        }

        if (!$payment->getMatchedUser()) {
            throw new \InvalidArgumentException('Payment must be matched to a user before processing');
        }

        // Use the dedicated payment processing service
        $result = $this->paymentProcessingService->processPayment($payment);

        // Nothing was booked - keep it matched so it can be reassigned and retried
        if (!empty($result['skipped_without_ticket'])) {
            $this->paymentRepository->save($payment);

            return $result;
        }

        // Update payment status
        $payment->setStatus(IncomingPayment::STATUS_PROCESSED);
        $payment->setProcessedBy($processedBy);
        $payment->setProcessedAt(new DateTimeImmutable());

        $this->paymentRepository->save($payment);

        $this->logger->info('Payment processed', [
            'payment_id' => $payment->getId(),
            'user_id' => $payment->getMatchedUser()->toString(),
            'processed_by' => $processedBy?->toString(),
            'result' => $result
        ]);
        
        return $result;
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
     * Get matched but unprocessed payments with pagination
     */
    public function getMatchedUnprocessedPayments(int $page = 1, int $limit = 50): array
    {
        return $this->paymentRepository->findMatchedUnprocessedPaginated($page, $limit);
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
        string $currency,
        DateTimeImmutable $timestamp,
        string $source,
        array $metadata = []
    ): IncomingPayment {
        return $this->createIncomingPayment(
            $amount,
            $currency,
            $timestamp,
            $source,
            $metadata
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

            // Step 2: If matched to user with high confidence, process the payment automatically
            if ($payment->getMatchConfidence() === IncomingPayment::CONFIDENCE_HIGH) {
                // Process the payment through the main processing method (sets status to processed)
                $this->processPayment($payment);
                
                $this->logger->info('Payment automatically processed', [
                    'payment_id' => $payment->getId(),
                    'user_id' => $payment->getMatchedUser()->toString(),
                    'confidence' => $payment->getMatchConfidence()
                ]);
            } else {
                // Medium/low confidence - keep as matched but require manual review
                $this->logger->info('Payment matched with lower confidence - requires manual review', [
                    'payment_id' => $payment->getId(),
                    'user_id' => $payment->getMatchedUser()->toString(),
                    'confidence' => $payment->getMatchConfidence()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error during automatic payment matching', [
                'payment_id' => $payment->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if a payment with the given external ID already exists
     */
    public function paymentExistsByExternalId(string $externalId): bool
    {
        return $this->paymentRepository->findOneBy(['externalId' => $externalId]) !== null;
    }
}
