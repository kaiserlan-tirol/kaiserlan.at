<?php

namespace App\Service;

use App\Entity\CateringCreditTransaction;
use App\Entity\CateringOrder;
use App\Entity\CateringOrderHistory;
use App\Entity\CateringOrderHistoryAction;
use App\Entity\CateringOrderPosition;
use App\Entity\CateringOrderStatus;
use App\Entity\CateringProduct;
use App\Entity\User;
use App\Entity\UserCateringCredit;
use App\Repository\CateringCreditTransactionRepository;
use App\Repository\UserCateringCreditRepository;
use App\Exception\OrderLifecycleException;
use App\Helper\EmailRecipient;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\CateringOrderPositionRepository;
use App\Repository\CateringOrderRepository;
use App\Repository\CateringProductRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

class CateringService
{
    private readonly CateringOrderRepository $orderRepository;
    private readonly CateringOrderPositionRepository $orderPositionRepository;
    private readonly CateringProductRepository $productRepository;
    private readonly EntityManagerInterface $em;
    private readonly LoggerInterface $logger;
    private readonly IdmRepository $userRepo;
    private readonly EmailService $emailService;
    private readonly ShopService $shopService;
    private readonly UserCateringCreditRepository $creditRepository;
    private readonly CateringCreditTransactionRepository $transactionRepository;

    public function __construct(
        CateringOrderRepository $orderRepository,
        CateringOrderPositionRepository $orderPositionRepository,
        CateringProductRepository $productRepository,
        IdmManager $idmManager,
        EmailService $emailService,
        ShopService $shopService,
        UserCateringCreditRepository $creditRepository,
        CateringCreditTransactionRepository $transactionRepository,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderPositionRepository = $orderPositionRepository;
        $this->productRepository = $productRepository;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->emailService = $emailService;
        $this->shopService = $shopService;
        $this->creditRepository = $creditRepository;
        $this->transactionRepository = $transactionRepository;
        $this->em = $em;
        $this->logger = $logger;
    }

    public function getProducts(bool $all = false): array
    {
        return !$all ? $this->productRepository->findActive() : $this->productRepository->findAll();
    }
    
    /**
     * Get a product by its product code
     */
    public function getProductByCode(string $productCode): ?CateringProduct
    {
        return $this->productRepository->findByProductCode($productCode);
    }

    /**
     * Check if user has purchased and paid for addons that include catering products
     */
    public function userHasFlatrate(User|UuidInterface $user): bool
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        
        // Get all addons the user has purchased and paid for
        $userPaidAddons = $this->shopService->countOrderedAddons($uuid, true); // paid only
        
        if (empty($userPaidAddons)) {
            return false;
        }
        
        // Check if any of the user's addons include catering products
        $allProducts = $this->productRepository->findActive();
        foreach ($allProducts as $product) {
            foreach ($product->getIncludedInAddons() as $addon) {
                if (($userPaidAddons[$addon->getId()] ?? 0) > 0) {
                    return true; // User has at least one addon that includes catering products
                }
            }
        }
        
        return false;
    }

    /**
     * Get addons that the user has purchased and paid for
     */
    public function getUserAddons(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $userPaidAddons = $this->shopService->countOrderedAddons($uuid, true); // paid only
        
        $addons = [];
        $allAddons = $this->shopService->getAddons(all: true);
        
        foreach ($allAddons as $addon) {
            if (($userPaidAddons[$addon->getId()] ?? 0) > 0) {
                $addons[] = $addon;
            }
        }
        
        return $addons;
    }

    /**
     * Get products that should be free for users with specific addons
     */
    public function getFlatrateProducts(User|UuidInterface|null $user = null): array
    {
        if (!$user) {
            return [];
        }
        
        $userAddons = $this->getUserAddons($user);
        if (empty($userAddons)) {
            return [];
        }
        
        $products = $this->productRepository->findActive();
        $freeProducts = [];
        
        foreach ($products as $product) {
            if ($product->isIncludedInAnyAddon($userAddons)) {
                $freeProducts[] = $product;
            }
        }
        
        return $freeProducts;
    }

    /**
     * Get products that user needs to pay for
     */
    public function getPaidProducts(User|UuidInterface|null $user = null): array
    {
        if (!$user) {
            return $this->productRepository->findActive();
        }
        
        $userAddons = $this->getUserAddons($user);
        $products = $this->productRepository->findActive();
        $paidProducts = [];
        
        foreach ($products as $product) {
            if (!$product->isIncludedInAnyAddon($userAddons)) {
                $paidProducts[] = $product;
            }
        }
        
        return $paidProducts;
    }

    /**
     * Create a new catering order for a user
     * 
     * @param User|UuidInterface $user The user who is ordering
     * @return CateringOrder A new order instance
     */
    public function allocOrder(User|UuidInterface $user): CateringOrder
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        
        // Special handling for guest user
        $isGuest = $user instanceof User && $user->getUuid()->toString() === '00000000-0000-0000-0000-000000000000';
        
        $order = new CateringOrder();
        $order->setOrderer($uuid);
        $order->setCreatedAt(new DateTimeImmutable());
        
        return $order;
    }

    public function placeOrder(CateringOrder $order): void
    {
        if ($order->isEmpty()) {
            throw new OrderLifecycleException($order);
        }

        if (!$this->checkProductsActive($order)) {
            throw new OrderLifecycleException($order);
        }
        
        // Special handling for free orders - set to Paid directly if total is 0
        if ($order->calculateTotal() == 0) {
            $result = $this->setState($order, CateringOrderStatus::Paid);
        } else {
            $result = $this->setState($order, CateringOrderStatus::Created);
        }
        
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function cancelOrder(CateringOrder $order): void
    {
        $result = $this->setState($order, CateringOrderStatus::Canceled);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function refundOrder(CateringOrder $order): void
    {
        $result = $this->setState($order, CateringOrderStatus::Refunded);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function setOrderPaid(CateringOrder $order): void
    {
        $result = $this->setState($order, CateringOrderStatus::Paid);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function setOrderPaidUndo(CateringOrder $order): void
    {
        $result = $this->setState($order, CateringOrderStatus::Created);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    private function setState(CateringOrder $order, CateringOrderStatus $status): bool
    {
        $valid_transfer = match ($order->getStatus()) {
            null => $status == CateringOrderStatus::Created || $status == CateringOrderStatus::Paid, // Allow setting directly to Paid for free orders
            CateringOrderStatus::Created => $status == CateringOrderStatus::Paid || $status == CateringOrderStatus::Canceled || $status == CateringOrderStatus::PaymentSent,
            CateringOrderStatus::PaymentSent => $status == CateringOrderStatus::Paid || $status == CateringOrderStatus::Canceled || $status == CateringOrderStatus::Created,
            CateringOrderStatus::Paid => $status == CateringOrderStatus::Refunded || $status == CateringOrderStatus::Created, // Allow reverting to Created
            default => false,
        };

        if (!$valid_transfer) {
            return false;
        }

        $new_state = $status; // Use the requested status directly

        $order->setStatus($new_state);
        $this->em->persist($order);
        $this->em->flush();
        $this->handleNewState($order);
        $this->em->flush();
        return true;
    }

    private function handleNewState(CateringOrder $order): void
    {
        $this->logger->info("Catering Order {$order->getId()} is now in stage {$order->getStatus()->name}");
        
        switch ($order->getStatus()) {
            case CateringOrderStatus::Created:
                $this->emailOrder($order);
                break;
            case CateringOrderStatus::PaymentSent:
                // Optional: Send notification to admin about payment sent
                break;
            case CateringOrderStatus::Refunded:
                break;
            case CateringOrderStatus::Canceled:
                break;
            case CateringOrderStatus::Paid:
                $this->emailOrder($order);
                break;
        }

        $order->addCateringOrderHistory(
            (new CateringOrderHistory())
                ->setLoggedAt(new DateTimeImmutable())
                ->setText('')
                ->setAction(match ($order->getStatus()) {
                    CateringOrderStatus::Created => CateringOrderHistoryAction::OrderCreated,
                    CateringOrderStatus::PaymentSent => CateringOrderHistoryAction::PaymentSent,
                    CateringOrderStatus::Paid => CateringOrderHistoryAction::PaymentSuccessful,
                    CateringOrderStatus::Refunded => CateringOrderHistoryAction::OrderRefunded,
                    CateringOrderStatus::Canceled => CateringOrderHistoryAction::OrderCanceled,
                })
        );
    }

    private function emailOrder(CateringOrder $order): void
    {
        // TODO: Implement email notification
        // $user = $this->userRepo->findOneById($order->getOrderer());
        // $this->emailService->scheduleHook(...);
    }

    private function checkProductsActive(CateringOrder $order): bool
    {
        foreach ($order->getCateringOrderPositions() as $position) {
            $product = $position->getProduct();
            if ($product && !$product->isActive()) {
                return false;
            }
        }
        return true;
    }

    public function hasOpenOrders(User|UuidInterface $user): bool
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->countOrders($uuid, CateringOrderStatus::Created) != 0;
    }

    /**
     * @param User|UuidInterface $user
     * @param CateringOrderStatus|null $status
     * @return CateringOrder[]
     */
    public function getOrderByUser(User|UuidInterface $user, ?CateringOrderStatus $status = null): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->queryOrders($uuid, $status);
    }

    /**
     * Find all orders for a specific user
     * 
     * @param User|UuidInterface $user The user or user UUID
     * @return CateringOrder[] Array of catering orders
     */
    public function findOrdersByUser(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->queryOrders($uuid);
    }

    public function deleteOrder(CateringOrder $order): void
    {
        if (!$order->getStatus()->isDead()) {
            throw new OrderLifecycleException($order);
        }
        $this->logger->info("Catering Order {$order->getId()} was deleted.");
        $this->em->remove($order);
        $this->em->flush();
    }

    public function allocProduct(): CateringProduct
    {
        return (new CateringProduct())
            ->setActive(true)
            ->setPrice(200)
            ->setName('')
            ->setDescription('');
    }

    public function saveProduct(CateringProduct $product): CateringProduct
    {
        // For existing products, refresh from database to ensure we have the managed entity
        if ($product->getId()) {
            $managedProduct = $this->productRepository->find($product->getId());
            if ($managedProduct) {
                // Update the managed entity with form data
                $managedProduct->setName($product->getName());
                $managedProduct->setDescription($product->getDescription());
                $managedProduct->setPrice($product->getPrice());
                $managedProduct->setActive($product->isActive());
                $managedProduct->setProductCode($product->getProductCode());
                $managedProduct->setSortIndex($product->getSortIndex());
                
                // Handle image property if set
                if ($product->getImage()) {
                    $managedProduct->setImage($product->getImage());
                }
                
                // Handle addon relationships - we need to carefully update the collection
                // First, remove all current relationships that are not in the new collection
                $currentAddons = $managedProduct->getIncludedInAddons()->toArray();
                $newAddons = $product->getIncludedInAddons()->toArray();
                
                // Remove addons that are no longer selected
                foreach ($currentAddons as $currentAddon) {
                    if (!in_array($currentAddon, $newAddons, true)) {
                        $managedProduct->removeIncludedInAddon($currentAddon);
                    }
                }
                
                // Add new addons that weren't previously selected
                foreach ($newAddons as $newAddon) {
                    if (!in_array($newAddon, $currentAddons, true)) {
                        $managedProduct->addIncludedInAddon($newAddon);
                    }
                }
                
                $this->em->flush();
                return $managedProduct;
            }
        }
        
        // For new products, persist as usual
        $this->em->persist($product);
        $this->em->flush();
        return $product;
    }

    public function deleteProduct(CateringProduct $product): void
    {
        $this->em->remove($product);
        $this->em->flush();
    }

    public function toggleProductActivity(CateringProduct $product): void
    {
        $product->setActive(!$product->isActive());
        $this->em->flush();
    }

    /**
     * @return array [[User, Order], ...]
     */
    public function getOrders(): array
    {
        $orders = $this->orderRepository->findAll();
        $uuids = array_map(fn($o) => $o->getOrderer(), $orders);

        // preload users
        $this->userRepo->findById($uuids);

        $result = [];
        foreach ($orders as $item) {
            $result[] = [
                'user' => $this->userRepo->findOneById($item->getOrderer()),
                'order' => $item
            ];
        }
        return $result;
    }

    /**
     * @param User|UuidInterface|null $user An optional user to count the purchases for that user.
     * @param bool $paidOnly only handle paid orders
     * @return array Array mapping ProductId to quantity of sold items of that product.
     */
    public function countOrderedProducts(User|UuidInterface|null $user = null, bool $paidOnly = false): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $filter = $paidOnly ? CateringOrderStatus::STATUS_ACTIVE : CateringOrderStatus::STATUS_NOT_DEAD;
        return $this->orderPositionRepository->countOrderedProductsById($uuid, $filter);
    }

    /**
     * @param CateringProduct $product The product to be counted.
     * @param bool $paidOnly only handle paid orders
     * @return int The number of purchased items
     */
    public function countOrderedProduct(CateringProduct $product, bool $paidOnly = false): int
    {
        $filter = $paidOnly ? CateringOrderStatus::STATUS_ACTIVE : CateringOrderStatus::STATUS_NOT_DEAD;
        return $this->orderPositionRepository->countOrderedProducts($product, null, $filter);
    }

    /**
     * @return array [[User, Text, Price, Quantity],...]
     */
    public function getProductOrders(): array
    {
        $filter = CateringOrderStatus::STATUS_ACTIVE;
        $positions = $this->orderPositionRepository->getOrderedProducts($filter);
        $uuids = array_map(fn($p) => $p->getOrder()->getOrderer(), $positions);

        // preload users
        $this->userRepo->findById($uuids);

        $result = [];
        foreach ($positions as $item) {
            $result[] = [
                'user' => $this->userRepo->findOneById($item->getOrder()->getOrderer()),
                'text' => $item->getText(),
                'price' => $item->getPrice(),
                'quantity' => $item->getQuantity(),
                'total' => $item->getTotalPrice()
            ];
        }

        return $result;
    }

    public function getUserBalance(User|UuidInterface $user): int
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->getTotalSpentByUser($uuid);
    }

    public function allocOrderPosition(): CateringOrderPosition
    {
        return new CateringOrderPosition();
    }
    
    /**
     * Add a product to a catering order
     * 
     * @param CateringOrder $order The order to add the product to
     * @param CateringProduct $product The product to add
     * @param int $quantity The quantity to add
     * @return CateringOrderPosition The created order position
     */
    public function orderAddProduct(CateringOrder $order, CateringProduct $product, int $quantity): CateringOrderPosition
    {
        if ($quantity <= 0) {
            return new CateringOrderPosition();
        }

        // Check if product already exists in order
        foreach ($order->getCateringOrderPositions() as $position) {
            if ($position->getProduct() && $position->getProduct()->getId() === $product->getId()) {
                $position->setQuantity($position->getQuantity() + $quantity);
                return $position;
            }
        }
        
        // Check if this is a guest user
        $isGuest = $order->getOrderer()->toString() === '00000000-0000-0000-0000-000000000000';
        $price = $product->getPrice();
        
        if (!$isGuest) {
            // For regular users, check if they have a flatrate that includes this product
            $user = $this->userRepo->findOneById($order->getOrderer());
            $userAddons = $user ? $this->getUserAddons($user) : [];
            
            if ($product->isIncludedInAnyAddon($userAddons)) {
                // Product is included in user's flatrate, set price to 0
                $price = 0;
            }
        }
        
        // Create new position
        $position = new CateringOrderPosition();
        $position->setOrder($order);
        $position->setPrice($price);
        $position->setQuantity($quantity);
        $position->setProductName($product->getName());
        $position->setProductCode($product->getProductCode());
        $position->setProduct($product);
        
        $order->addCateringOrderPosition($position);
        
        return $position;
    }
    
    /**
     * Persist an order to the database
     * 
     * @param CateringOrder $order The order to persist
     * @return CateringOrder The persisted order
     */
    public function persistOrder(CateringOrder $order): CateringOrder
    {
        // Set initial state
        if ($order->getStatus() === null) {
            $this->setState($order, CateringOrderStatus::Created);
        }
        
        // Persist the order and its positions
        $this->em->persist($order);
        
        foreach ($order->getCateringOrderPositions() as $position) {
            $this->em->persist($position);
        }
        
        $this->em->flush();
        
        return $order;
    }

    /**
     * Get the user's credit balance
     * 
     * @param User|UuidInterface $user The user to get the credit for
     * @return int The user's credit balance in cents
     */
    public function getUserCredit(User|UuidInterface $user): int
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $credit = $this->creditRepository->findByUser($uuid);
        
        return $credit ? $credit->getAmount() : 0;
    }

    /**
     * Add credit to a user's account
     * 
     * @param User|UuidInterface $user The user to add credit to
     * @param int $amount The amount to add in cents
     * @param string|null $note Optional note about the credit addition
     * @return UserCateringCredit The updated credit entity
     */
    public function addUserCredit(User|UuidInterface $user, int $amount, ?string $note = null): UserCateringCredit
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive');
        }
        
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $credit = $this->creditRepository->findByUser($uuid);
        
        if (!$credit) {
            $credit = new UserCateringCredit();
            $credit->setUser($uuid);
        }
        
        $credit->addCredit($amount);
        if ($note) {
            $credit->setNote($note);
        }
        
        $this->creditRepository->save($credit);
        
        // Record transaction
        $transaction = new CateringCreditTransaction();
        $transaction->setUser($uuid);
        $transaction->setAmount($amount);
        $transaction->setType(CateringCreditTransaction::TYPE_PAYMENT_RECEIVED);
        $transaction->setDescription($note ?? 'Guthaben aufgeladen');
        
        $this->transactionRepository->save($transaction);
        
        return $credit;
    }
    
    /**
     * Deduct credit from a user's account
     * 
     * @param User|UuidInterface $user The user to deduct credit from
     * @param int $amount The amount to deduct in cents
     * @param CateringOrder|null $order Associated order if applicable
     * @param string|null $note Optional note for manual credit adjustments
     * @return bool True if enough credit was available and deducted, false otherwise
     */
    public function deductUserCredit(User|UuidInterface $user, int $amount, ?CateringOrder $order = null, ?string $note = null): bool
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deduction amount must be positive');
        }
        
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $credit = $this->creditRepository->findByUser($uuid);
        
        // Create credit entity if it doesn't exist yet
        if (!$credit) {
            $credit = new UserCateringCredit();
            $credit->setUser($uuid);
        }
        
        // Always allow deduction (potentially going into negative balance)
        $credit->deductCredit($amount);
        $this->creditRepository->save($credit);
        
        // Record transaction
        $transaction = new CateringCreditTransaction();
        $transaction->setUser($uuid);
        $transaction->setAmount(-$amount);
        
        if ($order) {
            $transaction->setType(CateringCreditTransaction::TYPE_ORDER_PAYMENT);
            $transaction->setOrder($order);
            $transaction->setDescription('Bezahlung für Bestellung #' . $order->getId());
        } else {
            $transaction->setType(CateringCreditTransaction::TYPE_CREDIT_ADJUSTMENT);
            $transaction->setDescription($note ?: 'Manuelle Guthabenanpassung');
        }
        
        $this->transactionRepository->save($transaction);
        
        return true;
    }
    
    /**
     * Get transaction history for a user
     * 
     * @param User|UuidInterface $user The user to get transaction history for
     * @return array The transaction history
     */
    public function getUserTransactionHistory(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->transactionRepository->findByUser($uuid);
    }

    /**
     * Mark orders as payment sent by the user
     * 
     * @param array $orders The orders to mark
     */
    public function markOrdersAsPaymentSent(array $orders): void
    {
        foreach ($orders as $order) {
            if ($order->isOpen()) {
                $this->setState($order, CateringOrderStatus::PaymentSent);
            }
        }
        $this->em->flush();
    }
    
    /**
     * Process a payment from a user
     * 
     * This will:
     * 1. Mark as many orders as paid as the payment amount covers
     * 2. Add any remaining amount as credit to the user's account
     * 
     * @param User|UuidInterface $user The user who made the payment
     * @param int $amount The payment amount in cents
     * @param string|null $note Optional note about the payment
     * @return array Summary of the payment processing
     */
    public function processPayment(User|UuidInterface $user, int $amount, ?string $note = null): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $ordersProcessed = 0;
        $amountUsed = 0;
        $amountToCredit = 0;
        
        // Get all orders that are marked as payment sent, sorted by creation date (oldest first)
        $paymentSentOrders = $this->orderRepository->findBy(
            ['orderer' => $uuid, 'status' => CateringOrderStatus::PaymentSent],
            ['createdAt' => 'ASC']
        );
        
        // If no orders marked as payment sent, also try to cover regular open orders
        if (empty($paymentSentOrders)) {
            $paymentSentOrders = $this->orderRepository->findBy(
                ['orderer' => $uuid, 'status' => CateringOrderStatus::Created],
                ['createdAt' => 'ASC']
            );
        }
        
        // Mark orders as paid until we run out of payment
        $remainingAmount = $amount;
        foreach ($paymentSentOrders as $order) {
            $orderTotal = $order->calculateTotal();
            
            // If we have enough payment left to cover this order
            if ($remainingAmount >= $orderTotal) {
                $this->setState($order, CateringOrderStatus::Paid);
                $remainingAmount -= $orderTotal;
                $amountUsed += $orderTotal;
                $ordersProcessed++;
                
                // Record transaction for this order
                $transaction = new CateringCreditTransaction();
                $transaction->setUser($uuid);
                $transaction->setAmount(-$orderTotal);
                $transaction->setType(CateringCreditTransaction::TYPE_ORDER_PAYMENT);
                $transaction->setOrder($order);
                $transaction->setDescription('Bezahlung für Bestellung #' . $order->getId());
                $this->transactionRepository->save($transaction);
            } else {
                // Not enough to cover this order - it remains in payment_sent status
                break;
            }
        }
        
        // Add any remaining amount as credit
        if ($remainingAmount > 0) {
            $this->addUserCredit($uuid, $remainingAmount, $note);
            $amountToCredit = $remainingAmount;
        }
        
        $this->em->flush();
        
        return [
            'orders_processed' => $ordersProcessed,
            'amount_used' => $amountUsed,
            'amount_credited' => $amountToCredit,
        ];
    }
    
    /**
     * Apply a user's available credit to their open orders
     * 
     * @param User|UuidInterface $user The user
     * @return array Summary of credit application
     */
    public function applyUserCreditToOrders(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $availableCredit = $this->getUserCredit($user);
        $ordersProcessed = 0;
        $amountUsed = 0;
        
        if ($availableCredit <= 0) {
            return [
                'orders_processed' => 0,
                'amount_used' => 0,
                'remaining_credit' => 0,
            ];
        }
        
        // Get open orders, sorted by creation date (oldest first)
        $openOrders = $this->orderRepository->findBy(
            ['orderer' => $uuid, 'status' => CateringOrderStatus::Created],
            ['createdAt' => 'ASC']
        );
        
        // Apply credit to orders
        $remainingCredit = $availableCredit;
        foreach ($openOrders as $order) {
            $orderTotal = $order->calculateTotal();
            
            // If we have enough credit to cover this order
            if ($remainingCredit >= $orderTotal) {
                $this->setState($order, CateringOrderStatus::Paid);
                $this->deductUserCredit($uuid, $orderTotal, $order);
                $remainingCredit -= $orderTotal;
                $amountUsed += $orderTotal;
                $ordersProcessed++;
            } else {
                // Not enough credit to cover this order
                break;
            }
        }
        
        return [
            'orders_processed' => $ordersProcessed,
            'amount_used' => $amountUsed,
            'remaining_credit' => $remainingCredit,
        ];
    }
}
