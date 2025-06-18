<?php

namespace App\Entity;

/**
 * Common interface for ShopOrder and CateringOrder classes
 */
interface OrderInterface
{
    /**
     * Get the order ID
     * 
     * @return int|null The order ID
     */
    public function getId(): ?int;
}
