<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ProcessPayment implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 10;

    public function __construct(public string $reservationId)
    {
        $this->tries = max(1, (int) config('ticketrush.payment.retry_times', 3));
    }

    public function backoff(): array
    {
        return [1, 2, 5, 10];
    }

    public function handle(): void
    {
        $reservation = Reservation::query()->find($this->reservationId);

        if (! $reservation || in_array($reservation->status, [
            ReservationStatus::Confirmed,
            ReservationStatus::Expired,
            ReservationStatus::Failed,
        ], true)) {
            return;
        }

        if ($reservation->expires_at->isPast()) {
            ExpireReservation::dispatch($this->reservationId);

            return;
        }

        $response = Http::acceptJson()
            ->withHeaders([
                'Idempotency-Key' => 'reservation-'.$this->reservationId,
            ])
            ->timeout(config('ticketrush.payment.timeout_seconds'))
            ->post(rtrim(config('ticketrush.payment.url'), '/').'/payments', [
                'reservation_id' => $this->reservationId,
                'event_id' => $reservation->event_id,
                'ticket_id' => $reservation->ticket_id,
            ]);

        $response->throw();

        $paymentReference = (string) $response->json('payment_reference');

        DB::transaction(function () use ($paymentReference): void {
            $reservation = Reservation::query()
                ->whereKey($this->reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation || $reservation->status === ReservationStatus::Confirmed) {
                return;
            }

            if ($reservation->status === ReservationStatus::Expired) {
                OutboxEvent::record(
                    aggregateType: 'reservation',
                    aggregateId: $this->reservationId,
                    type: 'payment.refund_required',
                    payload: [
                        'reservation_id' => $this->reservationId,
                        'payment_reference' => $paymentReference,
                        'reason' => 'reservation_expired_before_confirmation',
                    ],
                );

                return;
            }

            if ($reservation->status !== ReservationStatus::PaymentPending) {
                return;
            }

            $reservation->update([
                'status' => ReservationStatus::Confirmed,
                'confirmed_at' => now(),
                'payment_reference' => $paymentReference,
            ]);

            $updatedTickets = $reservation->ticket()
                ->where('status', TicketStatus::Reserved->value)
                ->update([
                    'status' => TicketStatus::Sold->value,
                    'reserved_until' => null,
                ]);

            if ($updatedTickets !== 1) {
                throw new RuntimeException('Reserved ticket state changed before payment confirmation.');
            }

            OutboxEvent::record(
                aggregateType: 'reservation',
                aggregateId: $this->reservationId,
                type: 'reservation.confirmed',
                payload: [
                    'reservation_id' => $this->reservationId,
                    'payment_reference' => $paymentReference,
                ],
            );
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $reservation = Reservation::query()
                ->whereKey($this->reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation || $reservation->status !== ReservationStatus::PaymentPending) {
                return;
            }

            OutboxEvent::record(
                aggregateType: 'reservation',
                aggregateId: $this->reservationId,
                type: 'payment.processing_failed',
                payload: [
                    'reservation_id' => $this->reservationId,
                    'error' => $exception?->getMessage(),
                ],
            );
        });
    }
}
