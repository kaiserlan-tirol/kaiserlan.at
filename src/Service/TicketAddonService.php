<?php

namespace App\Service;

use App\Entity\ShopAddon;
use App\Entity\ShopOrderStatus;
use App\Entity\User;
use App\Repository\ShopOrderPositionRepository;
use Ramsey\Uuid\UuidInterface;

/**
 * Service to help track which addons a user has purchased and can use
 * based on their redeemed tickets
 */
class TicketAddonService
{
    private readonly ShopOrderPositionRepository $shopOrderPositionRepository;

    public function __construct(ShopOrderPositionRepository $shopOrderPositionRepository)
    {
        $this->shopOrderPositionRepository = $shopOrderPositionRepository;
    }

    /**
     * Get all addons that a user can use based on their redeemed tickets
     * @param User|UuidInterface $user
     * @return array [addon_id => count]
     */
    public function getUserAvailableAddons(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->shopOrderPositionRepository->countAddonsForRedeemedTickets(
            $uuid, 
            ShopOrderStatus::STATUS_ACTIVE
        );
    }

    /**
     * Check if a user has a specific addon available through their redeemed tickets
     * @param User|UuidInterface $user
     * @param ShopAddon $addon
     * @return int Number of that addon the user has available
     */
    public function getUserAddonCount(User|UuidInterface $user, ShopAddon $addon): int
    {
        $availableAddons = $this->getUserAvailableAddons($user);
        return $availableAddons[$addon->getId()] ?? 0;
    }

    /**
     * Check if a user has a specific addon available
     * @param User|UuidInterface $user
     * @param ShopAddon $addon
     * @return bool
     */
    public function userHasAddon(User|UuidInterface $user, ShopAddon $addon): bool
    {
        return $this->getUserAddonCount($user, $addon) > 0;
    }

    /**
     * Get addon counts per ticket for a specific user
     * @param User|UuidInterface $user
     * @return array [ticket_id => [addon_id => count]]
     */
    public function getUserTicketAddons(User|UuidInterface $user): array
    {
        $uuid = $user instanceof User ? $user->getUuid() : $user;
        return $this->shopOrderPositionRepository->countAddonsPerTicket(
            $uuid, 
            ShopOrderStatus::STATUS_ACTIVE
        );
    }
}
