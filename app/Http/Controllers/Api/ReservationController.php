<?php

namespace App\Http\Controllers\Api;

use App\Actions\ReserveTicket;
use App\Exceptions\SoldOutException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    private function payload(Reservation $reservation): array
    {
        return [
            'id' => (string) $reservation->getKey(),
            'event_id' => $reservation->event_id,
            'ticket_id' => $reservation->ticket_id,
            'status' => $reservation->status->value,
            'expires_at' => $reservation->expires_at->toISOString(),
            'confirmed_at' => $reservation->confirmed_at?->toISOString(),
        ];
    }
}
