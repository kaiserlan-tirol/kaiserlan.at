<?php

namespace App\Exception;

use App\Entity\OrderInterface;
use RuntimeException;

class OrderLifecycleException extends RuntimeException
{
    public readonly OrderInterface $order;

    public function __construct(OrderInterface $order, $message = null)
    {
        // Default message if none provided
        if ($message === null || $message === '') {
            $message = 'Statusänderung der Bestellung nicht möglich';
        }
        
        parent::__construct($message);

        $this->order = $order;
    }
}
