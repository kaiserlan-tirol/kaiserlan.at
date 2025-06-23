<?php

namespace App\Entity;

enum CateringOrderStatus : int
{
    /** order created */
    case Created = 1;
    /** payment sent by user, awaiting confirmation */
    case PaymentSent = 5;
    /** payment done */
    case Paid = 9;
    /** order cancelled */
    case Refunded = 98;
    case Canceled = 99;

    public const STATUS_OPEN = [self::Created];
    public const STATUS_PAYMENT_SENT = [self::PaymentSent];
    public const STATUS_ACTIVE = [self::Paid];
    public const STATUS_DEAD = [self::Canceled, self::Refunded];
    public const STATUS_NOT_DEAD = [self::Created, self::PaymentSent, self::Paid];

    public function isOpen(): bool
    {
        return in_array($this, self::STATUS_OPEN);
    }
    
    public function isPaymentSent(): bool
    {
        return in_array($this, self::STATUS_PAYMENT_SENT);
    }

    public function isActive(): bool
    {
        return in_array($this, self::STATUS_ACTIVE);
    }

    public function isDead(): bool
    {
        return in_array($this, self::STATUS_DEAD);
    }

    public function isCanceled(): bool
    {
        return $this === self::Canceled;
    }

    public function isRefunded(): bool
    {
        return $this === self::Refunded;
    }
}
