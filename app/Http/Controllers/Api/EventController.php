<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function __invoke(Event $event): JsonResponse
    {
        $counts = $event->tickets()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'data' => [
                'id' => $event->getKey(),
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toISOString(),
                'total_tickets' => $event->total_tickets,
                'available_tickets' => (int) ($counts[TicketStatus::Available->value] ?? 0),
                'reserved_tickets' => (int) ($counts[TicketStatus::Reserved->value] ?? 0),
                'sold_tickets' => (int) ($counts[TicketStatus::Sold->value] ?? 0),
            ],
        ]);
    }
}
