<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use App\Entity\ShopOrderStatus;
use App\Entity\User;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\ShopOrderRepository;
use Psr\Log\LoggerInterface;

class PaymentProcessingService
{
    private readonly CateringService $cateringService;
    private readonly ShopService $shopService;
    private readonly IdmRepository $userRepo;
    private readonly ShopOrderRepository $shopOrderRepository;
    private readonly LoggerInterface $logger;
    private readonly TransactionService $transactionService;

    public function __construct(
        CateringService $cateringService,
        ShopService $shopService,
        IdmManager $idmManager,
        ShopOrderRepository $shopOrderRepository,
        TransactionService $transactionService,
        LoggerInterface $logger
    ) {
        $this->cateringService = $cateringService;
        $this->shopService = $shopService;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->shopOrderRepository = $shopOrderRepository;
        $this->transactionService = $transactionService;
        $this->logger = $logger;
    }

    public function processPayment(IncomingPayment $payment): array
    {
        if (!$payment->getMatchedUser()) {
            throw new \InvalidArgumentException('Payment must be matched to a user before processing');
        }

        $user = $this->userRepo->findOneById($payment->getMatchedUser());
        if (!$user) {
            throw new \InvalidArgumentException('Matched user not found');
        }

        // Check if this payment might be a duplicate of a recently paid order
        if ($this->transactionService->isDuplicatePayment($user->getUuid(), $payment->getAmountInCents())) {
            $payment->setStatus(IncomingPayment::STATUS_PROCESSED);
            $payment->setProcessingNotes('Payment skipped - duplicate of recently paid order (already_assigned)');
            
            $this->logger->info('Payment skipped as duplicate', [
                'payment_id' => $payment->getId(),
                'user_id' => $user->getUuid()->toString(),
                'amount' => $payment->getAmountInCents(),
                'reason' => 'already_assigned'
            ]);
            
            return [
                'shop_orders_processed' => 0,
                'shop_amount_used' => 0,
                'catering_orders_processed' => 0,
                'catering_amount_used' => 0,
                'credit_added' => 0,
                'total_amount' => $payment->getAmountInCents(),
                'processing_notes' => ['Payment skipped - already assigned to recent order'],
                'skipped_as_duplicate' => true
            ];
        }

        $amountInCents = $payment->getAmountInCents();
        $processingNotes = [];

        // First, try to pay for open shop orders (tickets, addons)
        $shopOrdersProcessed = $this->processShopOrders($user, $amountInCents, $processingNotes);
        $amountInCents -= $shopOrdersProcessed['amount_used'];

        // Then, try to pay for open catering orders
        $cateringOrdersProcessed = $this->processCateringOrders($user, $amountInCents, $processingNotes);
        // The catering service already books any leftover as credit itself, so both
        // the used and the credited part are gone from the available amount.
        $amountInCents -= $cateringOrdersProcessed['amount_used'] + $cateringOrdersProcessed['amount_credited'];

        // Add any remaining amount as catering credit
        $creditAdded = $cateringOrdersProcessed['amount_credited'];
        if ($amountInCents > 0) {
            $this->cateringService->addUserCredit(
                $user,
                $amountInCents,
                sprintf('Incoming payment #%d - remaining amount', $payment->getId())
            );
            $creditAdded += $amountInCents;
            $processingNotes[] = sprintf('Added %.2f € as catering credit', $amountInCents / 100);
        }

        // Update payment processing notes
        $payment->setProcessingNotes(implode("\n", $processingNotes));

        $result = [
            'shop_orders_processed' => $shopOrdersProcessed['orders_processed'],
            'shop_amount_used' => $shopOrdersProcessed['amount_used'],
            'catering_orders_processed' => $cateringOrdersProcessed['orders_processed'],
            'catering_amount_used' => $cateringOrdersProcessed['amount_used'],
            'credit_added' => $creditAdded,
            'total_amount' => $payment->getAmountInCents(),
            'processing_notes' => $processingNotes
        ];

        $this->logger->info('Payment processed successfully', [
            'payment_id' => $payment->getId(),
            'user_id' => $user->getUuid()->toString(),
            'result' => $result
        ]);

        return $result;
    }

    private function processShopOrders(User $user, int $availableAmount, array &$processingNotes): array
    {
        if ($availableAmount <= 0) {
            return ['orders_processed' => 0, 'amount_used' => 0];
        }

        // Get open shop orders for the user
        $openOrders = $this->shopService->getOrderByUser($user, \App\Entity\ShopOrderStatus::Created);
        
        if (empty($openOrders)) {
            return ['orders_processed' => 0, 'amount_used' => 0];
        }

        $ordersProcessed = 0;
        $amountUsed = 0;

        // Sort orders by creation date (oldest first)
        usort($openOrders, fn($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        foreach ($openOrders as $order) {
            $orderTotal = $order->calculateTotal();
            
            if ($availableAmount >= $orderTotal) {
                // We can pay for this entire order
                $this->shopService->setOrderPaid($order);
                $availableAmount -= $orderTotal;
                $amountUsed += $orderTotal;
                $ordersProcessed++;
                
                $processingNotes[] = sprintf('Paid shop order #%d (%.2f €)', $order->getId(), $orderTotal / 100);
                
                $this->logger->info('Shop order paid from incoming payment', [
                    'order_id' => $order->getId(),
                    'amount' => $orderTotal,
                    'user_id' => $user->getUuid()->toString()
                ]);
            } else {
                // Not enough money left for this order
                break;
            }
        }

        return ['orders_processed' => $ordersProcessed, 'amount_used' => $amountUsed];
    }

    private function processCateringOrders(User $user, int $availableAmount, array &$processingNotes): array
    {
        if ($availableAmount <= 0) {
            return ['orders_processed' => 0, 'amount_used' => 0, 'amount_credited' => 0];
        }

        // Use the existing catering service payment processing
        $result = $this->cateringService->processPayment(
            $user,
            $availableAmount,
            'Incoming payment processing'
        );

        if ($result['orders_processed'] > 0) {
            $processingNotes[] = sprintf(
                'Paid %d catering order(s) (%.2f €)',
                $result['orders_processed'],
                $result['amount_used'] / 100
            );
        }

        if ($result['amount_credited'] > 0) {
            $processingNotes[] = sprintf('Added %.2f € as catering credit', $result['amount_credited'] / 100);
        }

        return [
            'orders_processed' => $result['orders_processed'],
            'amount_used' => $result['amount_used'],
            'amount_credited' => $result['amount_credited']
        ];
    }

    public function canProcessPayment(IncomingPayment $payment): bool
    {
        return $payment->getMatchedUser() !== null && !$payment->isProcessed();
    }

    public function getProcessingSummary(User $user): array
    {
        // Get open orders for this user to show what would be paid
        $shopOrders = $this->shopService->getOrderByUser($user, \App\Entity\ShopOrderStatus::Created);
        $cateringOrders = $this->cateringService->getOrderByUser($user, \App\Entity\CateringOrderStatus::Created);
        
        $shopTotal = 0;
        foreach ($shopOrders as $order) {
            $shopTotal += $order->calculateTotal();
        }

        $cateringTotal = 0;
        foreach ($cateringOrders as $order) {
            $cateringTotal += $order->calculateTotal();
        }

        return [
            'shop_orders' => [
                'count' => count($shopOrders),
                'total' => $shopTotal,
                'orders' => $shopOrders
            ],
            'catering_orders' => [
                'count' => count($cateringOrders),
                'total' => $cateringTotal,
                'orders' => $cateringOrders
            ],
            'total_open_amount' => $shopTotal + $cateringTotal
        ];
    }

}
