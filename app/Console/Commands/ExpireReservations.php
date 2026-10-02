<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Jobs\ExpireReservation;
use App\Models\Reservation;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire {--limit=1000}';

    protected $description = 'Enqueue expiration for overdue reservations as a safety sweep.';

    public function handle(): int
    {
        Reservation::query()
            ->whereIn('status', [
                ReservationStatus::Pending->value,
                ReservationStatus::PaymentPending->value,
            ])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id')
            ->each(fn (string $id) => ExpireReservation::dispatch($id));

        return self::SUCCESS;
    }
}
