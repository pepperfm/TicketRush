<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ExpireReservation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $reservationId)
    {
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $reservation = Reservation::query()
                ->whereKey($this->reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return;
            }

            if (! in_array($reservation->status, [
                ReservationStatus::Pending,
                ReservationStatus::PaymentPending,
            ], true)) {
                return;
            }

            if ($reservation->expires_at->isFuture()) {
                self::dispatch($this->reservationId)
                    ->delay($reservation->expires_at)
                    ->afterCommit();

                return;
            }

            $reservation->update(['status' => ReservationStatus::Expired]);

            $reservation->ticket()
                ->where('status', TicketStatus::Reserved->value)
                ->update([
                    'status' => TicketStatus::Available->value,
                    'reserved_until' => null,
                ]);

            OutboxEvent::record(
                aggregateType: 'reservation',
                aggregateId: (string) $reservation->getKey(),
                type: 'reservation.expired',
                payload: ['reservation_id' => (string) $reservation->getKey()],
            );
        }, attempts: 3);
    }
}
