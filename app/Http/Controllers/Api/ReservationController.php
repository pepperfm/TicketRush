<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReserveTicket;
use App\Enums\ReservationStatus;
use App\Exceptions\SoldOutException;
use App\Http\Controllers\Controller;
use App\Jobs\ExpireReservation;
use App\Jobs\ProcessPayment;
use App\Models\Event;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function store(Request $request, Event $event, ReserveTicket $reserveTicket): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if (strlen($key) < 8 || strlen($key) > 128) {
            return response()->json([
                'message' => 'Idempotency-Key header must contain 8 to 128 characters.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $event->is_active || $event->sales_start_at?->isFuture()) {
            return response()->json([
                'message' => 'Ticket sales are not active.',
            ], Response::HTTP_CONFLICT);
        }

        try {
            $reservation = $reserveTicket->handle($event, $key);
        } catch (SoldOutException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $this->payload($reservation),
        ], Response::HTTP_CREATED);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        return response()->json(['data' => $this->payload($reservation)]);
    }

    public function purchase(Reservation $reservation): JsonResponse
    {
        $result = DB::transaction(function () use ($reservation): array {
            $locked = Reservation::query()
                ->whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === ReservationStatus::Confirmed) {
                return ['reservation' => $locked, 'dispatch' => false, 'expired' => false];
            }

            if (in_array($locked->status, [
                ReservationStatus::Expired,
                ReservationStatus::Failed,
            ], true) || $locked->expires_at->isPast()) {
                return ['reservation' => $locked, 'dispatch' => false, 'expired' => true];
            }

            if ($locked->status === ReservationStatus::PaymentPending) {
                return ['reservation' => $locked, 'dispatch' => false, 'expired' => false];
            }

            $locked->update(['status' => ReservationStatus::PaymentPending]);

            OutboxEvent::record(
                aggregateType: 'reservation',
                aggregateId: (string) $locked->getKey(),
                type: 'reservation.payment_requested',
                payload: ['reservation_id' => (string) $locked->getKey()],
            );

            return ['reservation' => $locked, 'dispatch' => true, 'expired' => false];
        }, attempts: 3);

        /** @var Reservation $current */
        $current = $result['reservation'];

        if ($result['expired']) {
            ExpireReservation::dispatch((string) $current->getKey());

            return response()->json([
                'message' => 'Reservation has expired.',
                'data' => $this->payload($current),
            ], Response::HTTP_CONFLICT);
        }

        if ($result['dispatch']) {
            ProcessPayment::dispatch((string) $current->getKey());
        }

        return response()->json([
            'data' => $this->payload($current->fresh()),
        ], $current->status === ReservationStatus::Confirmed
            ? Response::HTTP_OK
            : Response::HTTP_ACCEPTED);
    }

    private function payload(Reservation $reservation): array
    {
        return [
            'id' => (string) $reservation->getKey(),
            'event_id' => $reservation->event_id,
            'ticket_id' => $reservation->ticket_id,
            'status' => $reservation->status->value,
            'expires_at' => $reservation->expires_at->toISOString(),
            'confirmed_at' => $reservation->confirmed_at?->toISOString(),
            'payment_reference' => $reservation->payment_reference,
        ];
    }
}
