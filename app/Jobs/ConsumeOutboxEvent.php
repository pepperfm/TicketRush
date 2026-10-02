<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConsumeOutboxEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 10;

    public function __construct(public string $outboxEventId)
    {
    }

    public function uniqueId(): string
    {
        return $this->outboxEventId;
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $event = OutboxEvent::query()
                ->whereKey($this->outboxEventId)
                ->lockForUpdate()
                ->first();

            if (! $event || $event->published_at !== null) {
                return;
            }

            $inserted = DB::table('inbox_messages')->insertOrIgnore([
                'message_id' => $event->getKey(),
                'consumer' => 'demo-audit-log',
                'processed_at' => now(),
            ]);

            if ($inserted === 1) {
                Log::info('Consumed outbox event.', [
                    'event_id' => $event->getKey(),
                    'type' => $event->type,
                    'aggregate_type' => $event->aggregate_type,
                    'aggregate_id' => $event->aggregate_id,
                ]);
            }

            $event->forceFill(['published_at' => now()])->save();
        }, attempts: 3);
    }
}
