<?php

namespace App\Service;

use App\Entity\CateringOrder;
use App\Entity\CateringOrderHistory;
use App\Entity\CateringOrderHistoryAction;
use App\Entity\CateringOrderPosition;
use App\Entity\CateringOrderStatus;
use App\Entity\CateringProduct;
use App\Entity\User;
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

    public function __construct(
        CateringOrderRepository $orderRepository,
        CateringOrderPositionRepository $orderPositionRepository,
        CateringProductRepository $productRepository,
        IdmManager $idmManager,
        EmailService $emailService,
        ShopService $shopService,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderPositionRepository = $orderPositionRepository;
        $this->productRepository = $productRepository;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->emailService = $emailService;
        $this->shopService = $shopService;
        $this->em = $em;
        $this->logger = $logger;
    }

    public function getProducts(bool $all = false): array
    {
        return !$all ? $this->productRepository->findActive() : $this->productRepository->findAll();
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

    public function allocOrder(User|UuidInterface $user): CateringOrder
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return (new CateringOrder())
            ->setOrderer($uuid)
            ->setCreatedAt(new DateTimeImmutable());
    }

    public function orderAddProduct(CateringOrder $order, CateringProduct $product, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }

        // Check if product already exists in order
        foreach ($order->getCateringOrderPositions() as $position) {
            if ($position->getProduct() && $position->getProduct()->getId() === $product->getId()) {
                $position->setQuantity($position->getQuantity() + $quantity);
                return;
            }
        }

        // Get user to check for addon-based pricing
        $user = $this->userRepo->findOneById($order->getOrderer());
        $userAddons = $user ? $this->getUserAddons($user) : [];
        
        // Create new position
        $position = (new CateringOrderPosition())
            ->fillWithProduct($product)
            ->setQuantity($quantity);
        
        // Override price to 0 if product is included in user's addons
        if ($product->isIncludedInAnyAddon($userAddons)) {
            $position->setPrice(0);
        }
        
        $order->addCateringOrderPosition($position);
    }

    public function placeOrder(CateringOrder $order): void
    {
        if ($order->isEmpty()) {
            throw new OrderLifecycleException($order);
        }

        if (!$this->checkProductsActive($order)) {
            throw new OrderLifecycleException($order);
        }

        $result = $this->setState($order, CateringOrderStatus::Created);
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
            null => $status == CateringOrderStatus::Created,
            CateringOrderStatus::Created => $status == CateringOrderStatus::Paid || $status == CateringOrderStatus::Canceled,
            CateringOrderStatus::Paid => $status == CateringOrderStatus::Refunded,
            default => false,
        };

        if (!$valid_transfer) {
            return false;
        }

        $new_state = match ($order->getStatus()) {
            // if the order has 0 amount, it is fulfilled immediately
            null => $order->calculateTotal() == 0 ? CateringOrderStatus::Paid : CateringOrderStatus::Created,
            default => $status,
        };

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
            ->setActive(false)
            ->setPrice(100)
            ->setName('Neues Produkt')
            ->setDescription('');
    }

    public function saveProduct(CateringProduct $product): CateringProduct
    {
        // For existing products, refresh from database to ensure we have the managed entity
        if ($product->getId()) {
            error_log("Saving existing product ID: " . $product->getId());
            error_log("Input product code: " . ($product->getProductCode() ?? 'NULL'));
            error_log("Input addons count: " . $product->getIncludedInAddons()->count());
            
            $managedProduct = $this->productRepository->find($product->getId());
            if ($managedProduct) {
                error_log("Found managed product, updating...");
                
                // Update the managed entity with form data
                $managedProduct->setName($product->getName());
                $managedProduct->setDescription($product->getDescription());
                $managedProduct->setPrice($product->getPrice());
                $managedProduct->setActive($product->isActive());
                $managedProduct->setProductCode($product->getProductCode());
                $managedProduct->setSortIndex($product->getSortIndex());
                
                error_log("Updated managed product code: " . ($managedProduct->getProductCode() ?? 'NULL'));
                
                // Handle addon relationships - we need to carefully update the collection
                // First, remove all current relationships that are not in the new collection
                $currentAddons = $managedProduct->getIncludedInAddons()->toArray();
                $newAddons = $product->getIncludedInAddons()->toArray();
                
                error_log("Current addons: " . count($currentAddons));
                error_log("New addons: " . count($newAddons));
                
                // Remove addons that are no longer selected
                foreach ($currentAddons as $currentAddon) {
                    if (!in_array($currentAddon, $newAddons, true)) {
                        error_log("Removing addon: " . $currentAddon->getName());
                        $managedProduct->removeIncludedInAddon($currentAddon);
                    }
                }
                
                // Add new addons that weren't previously selected
                foreach ($newAddons as $newAddon) {
                    if (!in_array($newAddon, $currentAddons, true)) {
                        error_log("Adding addon: " . $newAddon->getName());
                        $managedProduct->addIncludedInAddon($newAddon);
                    }
                }
                
                error_log("About to flush changes...");
                $this->em->flush();
                error_log("Flush completed");
                return $managedProduct;
            }
        }
        
        // For new products, persist as usual
        error_log("Saving new product");
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
}
