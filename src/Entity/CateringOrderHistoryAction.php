<?php

namespace App\Entity;

enum CateringOrderHistoryAction : string
{
    case OrderCreated = 'order_created';
    case PaymentSuccessful = 'payment_successful';
    case PaymentFailed = 'payment_failed';
    case PaymentNotice = 'payment_notice';
    case OrderRefunded = 'payment_refunded';
    case OrderCanceled = 'payment_canceled';
    case OrderFulfilled = 'order_fulfilled';
    case OrderDelivered = 'order_delivered';
}
