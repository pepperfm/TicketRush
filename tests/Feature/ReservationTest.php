<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Jobs\ExpireReservation;
use App\Jobs\ProcessPayment;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_same_idempotency_key_returns_same_reservation(): void
    {
        Queue::fake();

        $event = $this->createEventWithTickets(2);
        $headers = ['Idempotency-Key' => 'same-request-0001'];

        $first = $this->postJson("/api/events/{$event->id}/reservations", [], $headers)
            ->assertCreated()
            ->json('data');

        $second = $this->postJson("/api/events/{$event->id}/reservations", [], $headers)
            ->assertCreated()
            ->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame($first['ticket_id'], $second['ticket_id']);

        $this->assertDatabaseHas('reservations', [
            'id' => $first['id'],
            'idempotency_key' => 'same-request-0001',
        ]);

        Queue::assertPushed(ExpireReservation::class, 1);
    }

    public function test_inventory_cannot_be_oversold(): void
    {
        Queue::fake();

        $event = $this->createEventWithTickets(1);

        $first = $this->postJson(
            "/api/events/{$event->id}/reservations",
            [],
            ['Idempotency-Key' => 'inventory-request-0001'],
        )->assertCreated();

        $this->postJson(
            "/api/events/{$event->id}/reservations",
            [],
            ['Idempotency-Key' => 'inventory-request-0002'],
        )->assertConflict();

        $this->assertDatabaseHas('tickets', [
            'id' => $first->json('data.ticket_id'),
            'status' => TicketStatus::Reserved->value,
        ]);

        $this->assertSame(
            1,
            $event->reservations()->whereIn('status', [
                ReservationStatus::Pending->value,
                ReservationStatus::PaymentPending->value,
                ReservationStatus::Confirmed->value,
            ])->count(),
        );
    }

    public function test_purchase_is_dispatched_asynchronously(): void
    {
        Queue::fake();

        $event = $this->createEventWithTickets(1);

        $reservationId = $this->postJson(
            "/api/events/{$event->id}/reservations",
            [],
            ['Idempotency-Key' => 'payment-request-0001'],
        )->assertCreated()->json('data.id');

        $this->postJson("/api/reservations/{$reservationId}/purchase")
            ->assertAccepted()
            ->assertJsonPath('data.status', ReservationStatus::PaymentPending->value);

        Queue::assertPushed(ProcessPayment::class, fn (ProcessPayment $job) => $job->reservationId === $reservationId);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => ReservationStatus::PaymentPending->value,
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $reservationId,
            'type' => 'reservation.payment_requested',
        ]);
    }

    private function createEventWithTickets(int $count): Event
    {
        $event = Event::query()->create([
            'name' => 'Test event '.uniqid(),
            'starts_at' => now()->addDay(),
            'sales_start_at' => now()->subMinute(),
            'total_tickets' => $count,
            'is_active' => true,
        ]);

        foreach (range(1, $count) as $unused) {
            Ticket::query()->create([
                'event_id' => $event->getKey(),
                'status' => TicketStatus::Available,
            ]);
        }

        return $event;
    }
}
