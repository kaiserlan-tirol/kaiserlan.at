<?php

namespace App\Service;

use App\Entity\ShopAddon;
use App\Entity\ShopOrder;
use App\Entity\ShopOrderHistory;
use App\Entity\ShopOrderHistoryAction;
use App\Entity\ShopOrderPositionAddon;
use App\Entity\ShopOrderPositionTicket;
use App\Entity\ShopOrderStatus;
use App\Entity\Ticket;
use App\Entity\User;
use App\Exception\OrderLifecycleException;
use App\Helper\EmailRecipient;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\ShopAddonsRepository;
use App\Repository\ShopOrderPositionRepository;
use App\Repository\ShopOrderRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;


class ShopService
{
    private readonly ShopOrderRepository $orderRepository;
    private readonly ShopOrderPositionRepository $shopOrderPositionRepository;
    private readonly ShopAddonsRepository $shopAddonsRepository;
    private readonly SettingService $settingService;
    private readonly EntityManagerInterface $em;
    private readonly TicketService $ticketService;
    private readonly EmailService $emailService;
    private readonly IdmRepository $userRepo;
    private readonly TransactionService $transactionService;

    public const DEFAULT_TICKET_PRICE = 5000;
    public const MAX_TICKET_COUNT = 20;
    private LoggerInterface $logger;

    public function __construct(ShopOrderRepository $orderRepository, ShopOrderPositionRepository $shopOrderPositionRepository, ShopAddonsRepository $shopAddonsRepository,
                                IdmManager          $idmManager, SettingService $settingService, TicketService $ticketService, EmailService $emailService, EntityManagerInterface $em, LoggerInterface $logger, TransactionService $transactionService)
    {
        $this->orderRepository = $orderRepository;
        $this->shopOrderPositionRepository = $shopOrderPositionRepository;
        $this->shopAddonsRepository = $shopAddonsRepository;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->settingService = $settingService;
        $this->ticketService = $ticketService;
        $this->emailService = $emailService;
        $this->em = $em;
        $this->logger = $logger;
        $this->transactionService = $transactionService;
    }

    public function getAll()
    {
        return $this->orderRepository->findAll();
    }

    private function checkLimits(ShopOrder $order, bool $paidOnly = false): bool
    {
        $countTotal = $this->countOrderedAddons(null, $paidOnly);
        $countUser = $this->countOrderedAddons($order->getOrderer(), $paidOnly);

        foreach ($order->getShopOrderPositions() as $pos) {
            if (!($pos instanceof ShopOrderPositionAddon)) {
                continue;
            }
            $addon = $pos->getAddon();
            if (empty($addon)) {
                continue;
            }
            $id = $addon->getId();
            if (!$addon->isActive()) {
                return false;
            }
            $countUser[$id] = ($countUser[$id] ?? 0) + 1;
            if ($addon->getOnlyOnce() && $countUser[$id] > 1) {
                return false;
            }
            $countTotal[$id] = ($countTotal[$id] ?? 0) + 1;
            if (!is_null($addon->getMaxQuantityGlobal())) {
                if ($countTotal[$id] > $addon->getMaxQuantityGlobal()) return false;
            }
        }

        return true;
    }

    private function fulfillOrder(ShopOrder $order): void
    {
        // handle tickets
        $buyer = $order->getOrderer();
        $first_ticket = null;
        foreach ($order->getShopOrderPositions() as $pos) {
            if ($pos instanceof ShopOrderPositionTicket) {
                $ticket = $this->ticketService->createTicket();
                $pos->setTicket($ticket);
                if (!$first_ticket) { $first_ticket = $ticket; }
            }
        }
        // activate one ticket for buyer if they don't have a ticket yet
        if ($first_ticket && !$this->ticketService->getTicketUser($buyer)) {
            $this->ticketService->redeemTicket($first_ticket, $buyer);
        }
    }

    private function unfulfillOrder(ShopOrder $order, bool $deleteTickets): void
    {
        // handle tickets
        foreach ($order->getShopOrderPositions() as $pos) {
            if ($pos instanceof ShopOrderPositionTicket) {
                // invalidate ticket
                $pos->setTicket(null);
                $ticket = $pos->getTicket();
                if ($deleteTickets && $ticket) {
                    $this->ticketService->deleteTicket($ticket);
                }
            }
        }
    }

    private function emailOrder(ShopOrder $order): void
    {
        // notify the buyer and send the codes
        $user = $this->userRepo->findOneById($order->getOrderer());
        $this->emailService->scheduleHook(EmailService::APP_HOOK_ORDER, EmailRecipient::fromUser($user), [
            'order' => $order,
            'showPaymentInfo' => $order->getStatus() == ShopOrderStatus::Created,
            'showPaymentSuccess' => $order->getStatus() == ShopOrderStatus::Paid,
        ]);
    }

    public function placeOrder(ShopOrder $order): void
    {
        if ($order->isEmpty()) {
            throw new OrderLifecycleException($order);
        }
        if (!$this->checkLimits($order)) {
            throw new OrderLifecycleException($order);
        }
        $result = $this->setState($order, ShopOrderStatus::Created);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function cancelOrder(ShopOrder $order): void
    {
        $result = $this->setState($order, ShopOrderStatus::Canceled);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function refundOrder(ShopOrder $order): void
    {
        if ($order->countRedeemedTickets() > 0) {
            throw new OrderLifecycleException($order);
        }
        $result = $this->setState($order, ShopOrderStatus::Refunded);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    public function setOrderPaid(ShopOrder $order): void
    {
        $result = $this->setState($order, ShopOrderStatus::Paid);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }

        // Create a transaction record for the manual payment confirmation
        try {
            $this->transactionService->processShopOrderPayment(
                $order->getOrderer(),
                $order->calculateTotal(),
                $order->getId(),
                'Zahlungsbestätigung durch Admin'
            );
            
            $this->logger->info('Created transaction record for manually confirmed shop order', [
                'order_id' => $order->getId(),
                'amount' => $order->calculateTotal(),
                'user_id' => $order->getOrderer()->toString()
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to create transaction record for manual payment', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
            // Don't throw - order state is already updated, this is just for tracking
        }
    }

    public function setOrderPaidUndo(ShopOrder $order): void
    {
        $result = $this->setState($order, ShopOrderStatus::Created);
        if (!$result) {
            throw new OrderLifecycleException($order);
        }
    }

    private function setState(ShopOrder $order, ShopOrderStatus $status): bool
    {
        $valid_transfer = match ($order->getStatus()) {
            null => $status == ShopOrderStatus::Created,
            ShopOrderStatus::Created => $status == ShopOrderStatus::Paid || $status == ShopOrderStatus::Canceled,
            ShopOrderStatus::Paid => $status == ShopOrderStatus::Refunded,
            default => false,
        };
        if (!$valid_transfer) {
            return false;
        }

        $new_state = match ($order->getStatus()) {
            // if the order has 0 amount, it is fulfilled immediately
            null => $order->calculateTotal() == 0 ? ShopOrderStatus::Paid : ShopOrderStatus::Created,
            // currently only state transfer from created to both other states are allowed.
            default => $status,
        };
        $order->setStatus($new_state);
        $this->em->persist($order);
        $this->em->flush();
        $this->handleNewState($order);
        $this->em->flush();
        return true;
    }

    private function handleNewState(ShopOrder $order): void
    {
        $this->logger->info("Order {$order->getId()} is now in stage {$order->getStatus()->name}");
        switch ($order->getStatus()) {
            case ShopOrderStatus::Created:
                $this->emailOrder($order);
                break;
            case ShopOrderStatus::Refunded:
                $this->unfulfillOrder($order, true);
                break;
            case ShopOrderStatus::Canceled:
                break;
            case ShopOrderStatus::Paid:
                $this->fulfillOrder($order);
                $this->emailOrder($order);
                break;
        }
        $order->addShopOrderHistory(
            (new ShopOrderHistory())
                ->setLoggedAt(new DateTimeImmutable())
                ->setAction(match ($order->getStatus()){
                    ShopOrderStatus::Created => ShopOrderHistoryAction::OrderCreated,
                    ShopOrderStatus::Paid => ShopOrderHistoryAction::PaymentSuccessful,
                    ShopOrderStatus::Refunded => ShopOrderHistoryAction::OrderRefunded,
                    ShopOrderStatus::Canceled => ShopOrderHistoryAction::OrderCanceled,
                })
        );
    }

    public function orderAdheresToLimits(ShopOrder $order, bool $paidOnly): bool
    {
        return $this->checkLimits($order, $paidOnly);
    }

    public function hasOpenOrders(User|UuidInterface $user): bool
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->countOrders($uuid, ShopOrderStatus::Created) != 0;
    }

    public function getAddons(bool $all = false): array
    {
        return !$all ? $this->shopAddonsRepository->findActive() : $this->shopAddonsRepository->findAll();
    }

    public function allocOrder(User|UuidInterface $user): ShopOrder
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return (new ShopOrder())
            ->setOrderer($uuid)
            ->setCreatedAt(new DateTimeImmutable());
    }

    public function orderAddTickets(ShopOrder $order, int $ticketCnt): void
    {
        $price = $this->settingService->get('lan.signup.price', self::DEFAULT_TICKET_PRICE);
        $discount_limit = $this->settingService->get('lan.signup.discount.limit');
        $discount_price = $this->settingService->get('lan.signup.discount.price');
        if ($discount_price && $discount_limit && $ticketCnt >= $discount_limit) {
            $price = $discount_price;
        }
        for ($i = 0; $i < $ticketCnt; $i++) {
            $order->addShopOrderPosition((new ShopOrderPositionTicket())->setPrice($price));
        }
    }

    public function orderAddAddon(ShopOrder $order, ShopAddon $addon, int $cnt, ?ShopAddon $zeroedBy = null): void
    {
        for ($i = 0; $i < $cnt; $i++) {
            $order->addShopOrderPosition((new ShopOrderPositionAddon())->fillWithAddon($addon, null, $zeroedBy));
        }
    }

    /**
     * Add an addon to a specific ticket
     */
    public function orderAddAddonToTicket(ShopOrderPositionTicket $ticket, ShopAddon $addon, int $cnt = 1, ?ShopAddon $zeroedBy = null): void
    {
        for ($i = 0; $i < $cnt; $i++) {
            $addonPosition = (new ShopOrderPositionAddon())->fillWithAddon($addon, $ticket, $zeroedBy);
            $ticket->getOrder()->addShopOrderPosition($addonPosition);
            $ticket->addAddon($addonPosition);
        }
    }

    /**
     * The addon among $ticketAddons that zeroes the price of $addon, or null if $addon keeps its price.
     * An addon is zeroed if a trigger on the same ticket lists it, or (floor rule) if a trigger zeroes
     * the ticket base price and $addon has a negative price — a free ticket must not go below 0€.
     *
     * @param ShopAddon[]|iterable $ticketAddons addons selected/present on the same ticket
     */
    public function getZeroingTrigger(ShopAddon $addon, iterable $ticketAddons): ?ShopAddon
    {
        foreach ($ticketAddons as $trigger) {
            if ($trigger === $addon || ($trigger->getId() !== null && $trigger->getId() === $addon->getId())) {
                continue;
            }
            if ($trigger->zerosAddon($addon)) {
                return $trigger;
            }
            if ($trigger->isZerosTicketPrice() && ($addon->getPrice() ?? 0) < 0) {
                return $trigger;
            }
        }
        return null;
    }

    /**
     * The addon among $ticketAddons that zeroes the ticket base price, or null.
     *
     * @param ShopAddon[]|iterable $ticketAddons addons selected/present on the same ticket
     */
    public function getTicketZeroingTrigger(iterable $ticketAddons): ?ShopAddon
    {
        foreach ($ticketAddons as $trigger) {
            if ($trigger->isZerosTicketPrice()) {
                return $trigger;
            }
        }
        return null;
    }

    /**
     * Process ticket-specific addon data from the form
     */
    public function processTicketAddons(ShopOrder $order, array $ticketAddonsData): void
    {
        // Get all ticket positions from the order
        $ticketPositions = array_values(array_filter(
            $order->getShopOrderPositions()->toArray(),
            fn($pos) => $pos instanceof ShopOrderPositionTicket
        ));

        $addons = $this->getAddons();

        foreach ($ticketAddonsData as $ticketIndex => $addonData) {
            if (!isset($ticketPositions[$ticketIndex])) {
                continue; // Skip if ticket doesn't exist
            }

            $ticket = $ticketPositions[$ticketIndex];

            // collect the full selection of this ticket first, the zero-rules depend on it
            $selection = [];
            foreach ($addons as $addon) {
                $quantity = (int) ($addonData["addon{$addon->getId()}"] ?? 0);
                if ($quantity > 0) {
                    $selection[] = [$addon, $quantity];
                }
            }
            $selectedAddons = array_map(fn($s) => $s[0], $selection);

            if ($this->getTicketZeroingTrigger($selectedAddons)) {
                $ticket->setPrice(0);
            }

            foreach ($selection as [$addon, $quantity]) {
                $zeroedBy = $this->getZeroingTrigger($addon, $selectedAddons);
                $this->orderAddAddonToTicket($ticket, $addon, $quantity, $zeroedBy);
            }
        }
    }

    /**
     * @param User|UuidInterface $user
     * @param ShopOrderStatus|null $status
     * @return ShopOrder[]
     */
    public function getOrderByUser(User|UuidInterface $user, ?ShopOrderStatus $status = null): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->orderRepository->queryOrders($uuid, $status);
    }

    public function deleteOrder(ShopOrder $order): void
    {
        if (!$order->getStatus()->isDead()) {
            throw new OrderLifecycleException($order);
        }
        $this->logger->info("Order {$order->getId()} was deleted.");
        $this->em->remove($order);
        $this->em->flush();
    }

    public function toggleAddonActivity(ShopAddon $addon): void
    {
        $addon->setActive(!$addon->isActive());
        $this->em->flush();
    }

    public function allocAddon(): ShopAddon
    {
        return (new ShopAddon())->setActive(false)->setPrice(100)->setName('Neues Addon')->setDescription('')->setOnlyOnce(false);
    }

    public function saveAddon(ShopAddon $addon): void
    {
        $this->em->persist($addon);
        $this->em->flush();
    }

    /**
     * Admin-facing warnings about incomplete zero-rule configuration.
     *
     * @return string[]
     */
    public function getAddonConfigWarnings(ShopAddon $addon): array
    {
        if (!$addon->isZerosTicketPrice()) {
            return [];
        }
        $missing = [];
        foreach ($this->getAddons() as $other) {
            if ($other->getId() === $addon->getId() || ($other->getPrice() ?? 0) >= 0 || $addon->zerosAddon($other)) {
                continue;
            }
            $missing[] = $other->getName();
        }
        if (empty($missing)) {
            return [];
        }
        return [sprintf(
            '"%s" setzt den Ticketpreis auf 0 €, aber folgende Addons mit negativem Preis fehlen in der 0 €-Liste: %s. '
            . 'Sie werden bei gemeinsamer Auswahl trotzdem auf 0 € gesetzt, damit kein negativer Ticketpreis entsteht.',
            $addon->getName(),
            implode(', ', $missing)
        )];
    }

    public function deleteAddon(ShopAddon $addon): void
    {
        $this->em->remove($addon);
        $this->em->flush();;
    }

    /**
     * @return array [[User, Order], ...]
     */
    public function getOrders(): array
    {
        $orders = $this->orderRepository->findAll();
        
        // Extract UUIDs and build result in single pass
        $uuids = [];
        $result = [];
        foreach ($orders as $order) {
            $uuidObj = $order->getOrderer();
            $uuidStr = $uuidObj->toString();
            $uuids[] = $uuidObj;
            $result[] = [
                'user' => null, // Will be filled after bulk fetch
                'order' => $order,
                'uuid' => $uuidStr
            ];
        }

        // Bulk fetch users and index by UUID
        $users = $this->userRepo->findById($uuids);
        $usersByUuid = [];
        foreach ($users as $user) {
            $usersByUuid[$user->getUuid()->toString()] = $user;
        }

        // Fill in users
        foreach ($result as &$item) {
            $item['user'] = $usersByUuid[$item['uuid']] ?? null;
            unset($item['uuid']); // Remove temporary key
        }
        
        return $result;
    }

    /**
     * @return array [[User, Text, Price],...]
     */
    public function getAddonOrders(): array
    {
        $filter = ShopOrderStatus::STATUS_ACTIVE;
        $sop = $this->shopOrderPositionRepository->getOrderedAddons($filter);
        $uuids = array_map(fn($p) => $p->getOrder()->getOrderer(), $sop);

        // Bulk fetch users and index by UUID
        $users = $this->userRepo->findById($uuids);
        $usersByUuid = [];
        foreach ($users as $user) {
            $usersByUuid[$user->getUuid()->toString()] = $user;
        }

        $result = [];
        foreach ($sop as $item) {
            $uuid = $item->getOrder()->getOrderer()->toString();
            $result[] = [
                'user' => $usersByUuid[$uuid] ?? null,
                'text' => $item->getText(), 
                'price' => $item->getPrice()
            ];
        }
        return $result;
    }
    /**
     * @param User|UuidInterface|null $user An optional user to count the purchases for that user.
     * @param bool $paidOnly only handle paid orders
     * @return array Array mapping AddonId to count of sold items of that addon.
     */
    public function countOrderedAddons(User|UuidInterface|null $user = null, bool $paidOnly = false): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $filter = $paidOnly ? ShopOrderStatus::STATUS_ACTIVE : ShopOrderStatus::STATUS_NOT_DEAD;
        return $this->shopOrderPositionRepository->countOrderedAddonsById($uuid, $filter);
    }

    /**
     * Ticket statistic: all sold tickets and the addons booked on them, grouped by price.
     *
     * @param bool $paidOnly only handle paid orders
     * @return array ['rows' => [['label' => string, 'count' => int, 'price' => int, 'revenue' => int], ...], 'total' => int]
     */
    public function getTicketStatistics(bool $paidOnly = false): array
    {
        $filter = $paidOnly ? ShopOrderStatus::STATUS_ACTIVE : ShopOrderStatus::STATUS_NOT_DEAD;
        $tickets = $this->shopOrderPositionRepository->getTicketStatistics($filter);
        $addons = $this->shopOrderPositionRepository->getAddonStatistics($filter);

        $rows = [];
        foreach ($tickets as $ticket) {
            $rows[] = [
                'label' => count($tickets) > 1 ? 'Tickets' : 'Tickets gesamt',
                'count' => $ticket['count'],
                'price' => $ticket['price'],
                'revenue' => $ticket['revenue'],
            ];
        }
        foreach ($addons as $addon) {
            $rows[] = [
                'label' => $addon['text'] !== '' ? $addon['text'] : "Addon #{$addon['addonId']}",
                'count' => $addon['count'],
                'price' => $addon['price'],
                'revenue' => $addon['revenue'],
            ];
        }

        return [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'revenue')),
        ];
    }

    /**
     * @param ShopAddon $addon The addon to be counted.
     * @param bool $paidOnly only handle paid orders
     * @return int The number of purchased items
     */
    public function countOrderedAddon(ShopAddon $addon, bool $paidOnly = false): int
    {
        $filter = $paidOnly ? ShopOrderStatus::STATUS_ACTIVE : ShopOrderStatus::STATUS_NOT_DEAD;
        return $this->shopOrderPositionRepository->countOrderedAddons($addon, null, $filter);
    }

    /**
     * Get addons attached to a specific ticket
     * @return ShopOrderPositionAddon[]
     */
    public function getTicketAddons(Ticket $ticket): array
    {
        $ticketPosition = $ticket->getShopOrderPosition();
        if (!$ticketPosition) {
            return [];
        }
        
        return $ticketPosition->getAddons()->toArray();
    }

    /**
     * Check if a user has a specific addon through any of their redeemed tickets
     */
    public function userHasAddon(User|UuidInterface $user, ShopAddon $addon): bool
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $ticket = $this->ticketService->getTicketUser($uuid);
        
        if (!$ticket) {
            return false;
        }
        
        $ticketAddons = $this->getTicketAddons($ticket);
        foreach ($ticketAddons as $addonPosition) {
            if ($addonPosition->getAddon() && $addonPosition->getAddon()->getId() === $addon->getId()) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get all addons for a specific user based on their redeemed ticket
     * @return ShopAddon[]
     */
    public function getUserAddons(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        $ticket = $this->ticketService->getTicketUser($uuid);
        
        if (!$ticket) {
            return [];
        }
        
        $ticketAddons = $this->getTicketAddons($ticket);
        $addons = [];
        
        foreach ($ticketAddons as $addonPosition) {
            if ($addonPosition->getAddon()) {
                $addons[] = $addonPosition->getAddon();
            }
        }
        
        return $addons;
    }

    /**
     * Add addon(s) to an already existing order (admin action)
     */
    public function addAddonToOrder(ShopOrder $order, ShopAddon $addon, int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be >= 1');
        }
        // Disallow addon modification on refunded or canceled orders
        if (in_array($order->getStatus(), [ShopOrderStatus::Refunded, ShopOrderStatus::Canceled])) {
            throw new OrderLifecycleException($order);
        }
        // Apply zero-rules of addons already on the order; only unambiguous with a single ticket
        $zeroedBy = null;
        $ticketPositions = array_values(array_filter(
            $order->getShopOrderPositions()->toArray(),
            fn($pos) => $pos instanceof ShopOrderPositionTicket
        ));
        if (count($ticketPositions) === 1) {
            $existingAddons = array_filter(array_map(
                fn(ShopOrderPositionAddon $pos) => $pos->getAddon(),
                $ticketPositions[0]->getAddons()->toArray()
            ));
            $zeroedBy = $this->getZeroingTrigger($addon, $existingAddons);
        }
        // Append positions, then enforce limits before flushing; roll back in-memory on violation.
        // (A shallow clone can't be used for the probe: it shares the positions collection, and the
        // probe position pointing at the unmanaged clone makes the later flush fail.)
        $newPositions = [];
        for ($i = 0; $i < $quantity; $i++) {
            $position = (new ShopOrderPositionAddon())->fillWithAddon($addon, null, $zeroedBy);
            $order->addShopOrderPosition($position);
            $newPositions[] = $position;
        }
        if (!$this->orderAdheresToLimits($order, $order->getStatus() === ShopOrderStatus::Paid)) {
            foreach ($newPositions as $position) {
                $order->removeShopOrderPosition($position);
            }
            throw new \RuntimeException('Limit verletzt: Addon kann nicht hinzugefügt werden.');
        }
        // Persist changes
        $this->em->persist($order);
        // Add to history
        $order->addShopOrderHistory(
            (new ShopOrderHistory())
                ->setLoggedAt(new \DateTimeImmutable())
                ->setAction(ShopOrderHistoryAction::AddonAdded)
        );
        $this->em->flush();
        // If order is paid, fulfill newly added addons constraints if any
        if ($order->getStatus() === ShopOrderStatus::Paid) {
            // No ticket creation needed; addons do not trigger fulfillment logic directly
            $this->logger->info('Addon(s) added to paid order', [
                'order_id' => $order->getId(),
                'addon_id' => $addon->getId(),
                'quantity' => $quantity
            ]);
            // Record a transaction if addon has a price > 0
            $priceEach = $zeroedBy ? 0 : $addon->getPrice();
            if ($priceEach > 0) {
                try {
                    $this->transactionService->addManualCredit(
                        $order->getOrderer(),
                        -($priceEach * $quantity), // Negative for payment
                        'shop',
                        'Addon hinzugefügt: ' . $addon->getName()
                    );
                } catch (\Throwable $txe) {
                    $this->logger->warning('Failed to record transaction for added addon', [
                        'order_id' => $order->getId(),
                        'addon_id' => $addon->getId(),
                        'error' => $txe->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Add an addon (e.g., foodflat) to a specific ticket and deduct the price from catering balance
     * This is used when a user buys an addon after already purchasing their ticket
     *
     * @param Ticket $ticket The ticket to add the addon to
     * @param ShopAddon $addon The addon to add
     * @return void
     * @throws \Exception If the ticket doesn't have an order position, order is not paid, addon already exists, or insufficient balance
     */
    public function addAddonToTicketWithCateringBalance(Ticket $ticket, ShopAddon $addon): void
    {
        // Get the ticket's order position
        $ticketPosition = $ticket->getShopOrderPosition();
        if (!$ticketPosition) {
            throw new \Exception('Ticket hat keine zugeordnete Bestellung');
        }

        $order = $ticketPosition->getOrder();
        if (!$order || $order->getStatus() !== ShopOrderStatus::Paid) {
            throw new \Exception('Ticket-Bestellung ist nicht bezahlt');
        }

        // Check if addon already exists on this ticket
        foreach ($ticketPosition->getAddons() as $existingAddon) {
            if ($existingAddon->getAddon() && $existingAddon->getAddon()->getId() === $addon->getId()) {
                throw new \Exception('Addon bereits vorhanden auf diesem Ticket');
            }
        }

        // Check if addon is one-per-ticket and user already has it
        if ($addon->isOnePerTicket()) {
            $redeemer = $ticket->getRedeemer();
            if ($redeemer && $this->userHasAddon($redeemer, $addon)) {
                throw new \Exception('Addon kann nur einmal pro Ticket gebucht werden');
            }
        }

        // Apply zero-rules of addons already on this ticket
        $existingAddons = array_filter(array_map(
            fn(ShopOrderPositionAddon $pos) => $pos->getAddon(),
            $ticketPosition->getAddons()->toArray()
        ));
        $zeroedBy = $this->getZeroingTrigger($addon, $existingAddons);
        $addonPrice = $zeroedBy ? 0 : $addon->getPrice();

        // Deduct from catering balance if price > 0 (allow negative balance)
        if ($addonPrice > 0) {
            $this->transactionService->addManualCredit(
                $order->getOrderer(),
                -$addonPrice, // Negative for deduction
                'catering',
                sprintf('Addon gebucht: %s', $addon->getName())
            );
        }

        // Add the addon to the ticket
        $addonPosition = (new ShopOrderPositionAddon())->fillWithAddon($addon, $ticketPosition, $zeroedBy);
        $order->addShopOrderPosition($addonPosition);
        $ticketPosition->addAddon($addonPosition);

        // Add to order history
        $order->addShopOrderHistory(
            (new ShopOrderHistory())
                ->setLoggedAt(new \DateTimeImmutable())
                ->setAction(ShopOrderHistoryAction::AddonAdded)
        );

        $this->em->persist($order);
        $this->em->persist($addonPosition);
        $this->em->flush();

        $this->logger->info('Addon added to ticket via catering balance', [
            'ticket_id' => $ticket->getId(),
            'addon_id' => $addon->getId(),
            'addon_name' => $addon->getName(),
            'price' => $addonPrice,
            'user_id' => $order->getOrderer()->toString()
        ]);
    }
}
