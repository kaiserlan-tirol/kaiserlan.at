<?php

namespace App\Entity;

enum CateringOrderHistoryAction : string
{
    case OrderCreated = 'Bestellung erstellt';
    case PaymentSuccessful = 'Bezahlung erfolgreich';
    case PaymentFailed = 'Bezahlung fehlgeschlagen';
    case PaymentNotice = 'Zahlungshinweis';
    case PaymentSent = 'Zahlung gesendet';
    case OrderRefunded = 'Bestellung rückerstattet';
    case OrderCanceled = 'Bestellung storniert';
    case OrderFulfilled = 'Bestellung abgeschlossen';
    case OrderDelivered = 'Bestellung ausgeliefert';
    
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
