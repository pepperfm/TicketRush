<?php

namespace App\Actions;

use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Exceptions\SoldOutException;
use App\Jobs\ExpireReservation;
use App\Models\Event;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use App\Models\Ticket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ReserveTicket
{
    public function handle(Event $event, string $idempotencyKey): Reservation
    {
        if ($existing = $this->existing($event, $idempotencyKey)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($event, $idempotencyKey): Reservation {
                if ($existing = $this->existing($event, $idempotencyKey, lock: true)) {
                    return $existing;
                }

                $ticket = Ticket::query()
                    ->where('event_id', $event->getKey())
                    ->where('status', TicketStatus::Available->value)
                    ->orderBy('id')
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first();

                if (! $ticket) {
                    throw new SoldOutException('No tickets are currently available.');
                }

                $expiresAt = now()->addSeconds(config('ticketrush.reservation_ttl_seconds'));

                $reservation = Reservation::query()->create([
                    'event_id' => $event->getKey(),
                    'ticket_id' => $ticket->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => ReservationStatus::Pending,
                    'expires_at' => $expiresAt,
                ]);

                $ticket->update([
                    'status' => TicketStatus::Reserved,
                    'reserved_until' => $expiresAt,
                ]);

                OutboxEvent::record(
                    aggregateType: 'reservation',
                    aggregateId: (string) $reservation->getKey(),
                    type: 'reservation.created',
                    payload: [
                        'reservation_id' => (string) $reservation->getKey(),
                        'event_id' => $event->getKey(),
                        'ticket_id' => $ticket->getKey(),
                        'expires_at' => $expiresAt->toISOString(),
                    ],
                );

                ExpireReservation::dispatch((string) $reservation->getKey())
                    ->delay($expiresAt)
                    ->afterCommit();

                return $reservation;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($event, $idempotencyKey) ?? throw $exception;
        }
    }

    private function existing(Event $event, string $idempotencyKey, bool $lock = false): ?Reservation
    {
        $query = Reservation::query()
            ->where('event_id', $event->getKey())
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
