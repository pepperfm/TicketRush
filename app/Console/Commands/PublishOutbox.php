<?php

namespace App\Console\Commands;

use App\Jobs\ConsumeOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--limit=500}';

    protected $description = 'Dispatch unpublished outbox events for idempotent consumption.';

    public function handle(): int
    {
        OutboxEvent::query()
            ->whereNull('published_at')
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id')
            ->each(fn (string $id) => ConsumeOutboxEvent::dispatch($id));

        return self::SUCCESS;
    }
}
