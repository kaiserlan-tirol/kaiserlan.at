<?php

namespace App\Entity;

enum ShopOrderHistoryAction : string
{
    case OrderCreated = 'Bestellung erstellt';
    case PaymentSuccessful = 'Bezahlung erfolgreich';
    case PaymentFailed = 'Bezahlung fehlgeschlagen';
    case PaymentNotice = 'Zahlungshinweis';
    case OrderRefunded = 'Bestellung rückerstattet';
    case OrderCanceled = 'Bestellung storniert';
    
    /**
     * Get a formatted display value for the action
     *
     * @return string The formatted display value
     */
    public function getDisplayValue(): string
    {
        return $this->value;
    }
}
