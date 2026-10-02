<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $event = Event::query()->firstOrCreate(
            ['name' => 'TicketRush Opening Night'],
            [
                'starts_at' => now()->addDays(30),
                'sales_start_at' => now()->subMinute(),
                'total_tickets' => 10_000,
                'is_active' => true,
            ],
        );

        if ($event->tickets()->exists()) {
            return;
        }

        foreach (array_chunk(range(1, $event->total_tickets), 1000) as $chunk) {
            DB::table('tickets')->insert(array_map(
                static fn () => [
                    'event_id' => $event->getKey(),
                    'status' => 'available',
                    'reserved_until' => null,
                ],
                $chunk,
            ));
        }
    }
}
