<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use App\Entity\User;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use Psr\Log\LoggerInterface;

class PaymentProcessingService
{
    private readonly CateringService $cateringService;
    private readonly ShopService $shopService;
    private readonly IdmRepository $userRepo;
    private readonly LoggerInterface $logger;

    public function __construct(
        CateringService $cateringService,
        ShopService $shopService,
        IdmManager $idmManager,
        LoggerInterface $logger
    ) {
        $this->cateringService = $cateringService;
        $this->shopService = $shopService;
        $this->userRepo = $idmManager->getRepository(User::class);
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

        $amountInCents = $payment->getAmount();
        $processingNotes = [];

        // First, try to pay for open shop orders (tickets, addons)
        $shopOrdersProcessed = $this->processShopOrders($user, $amountInCents, $processingNotes);
        $amountInCents -= $shopOrdersProcessed['amount_used'];

        // Then, try to pay for open catering orders
        $cateringOrdersProcessed = $this->processCateringOrders($user, $amountInCents, $processingNotes);
        $amountInCents -= $cateringOrdersProcessed['amount_used'];

        // Add any remaining amount as catering credit
        $creditAdded = 0;
        if ($amountInCents > 0) {
            $this->cateringService->addUserCredit(
                $user,
                $amountInCents,
                sprintf('Incoming payment #%d - remaining amount', $payment->getId())
            );
            $creditAdded = $amountInCents;
            $processingNotes[] = sprintf('Added %.2f € as catering credit', $creditAdded / 100);
        }

        // Update payment processing notes
        $payment->setProcessingNotes(implode("\n", $processingNotes));

        $result = [
            'shop_orders_processed' => $shopOrdersProcessed['orders_processed'],
            'shop_amount_used' => $shopOrdersProcessed['amount_used'],
            'catering_orders_processed' => $cateringOrdersProcessed['orders_processed'],
            'catering_amount_used' => $cateringOrdersProcessed['amount_used'],
            'credit_added' => $creditAdded,
            'total_amount' => $payment->getAmount(),
            'processing_notes' => $processingNotes
        ];

        $this->logger->info('Payment processed successfully', [
            'payment_id' => $payment->getId(),
            'user_id' => $user->getUuid()->toString(),
            'result' => $result
        ]);

        return $result;
    }

    private function processShopOrders(User $user, int &$availableAmount, array &$processingNotes): array
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

    private function processCateringOrders(User $user, int &$availableAmount, array &$processingNotes): array
    {
        if ($availableAmount <= 0) {
            return ['orders_processed' => 0, 'amount_used' => 0];
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
            'amount_used' => $result['amount_used']
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
