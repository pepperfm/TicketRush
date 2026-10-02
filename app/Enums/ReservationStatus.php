<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case PaymentPending = 'payment_pending';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Failed = 'failed';
}
