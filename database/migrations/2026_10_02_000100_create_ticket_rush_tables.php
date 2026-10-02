<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('sales_start_at')->nullable();
            $table->unsignedInteger('total_tickets');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('available');
            $table->timestampTz('reserved_until')->nullable();
            $table->index(['event_id', 'status', 'id'], 'tickets_allocation_idx');
            $table->index(['status', 'reserved_until'], 'tickets_expiration_idx');
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('status', 32)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestampsTz();

            $table->unique(['event_id', 'idempotency_key'], 'reservations_event_idempotency_unique');
            $table->index(['status', 'expires_at'], 'reservations_expiration_idx');
        });

        DB::statement(
            "CREATE UNIQUE INDEX reservations_active_ticket_unique
             ON reservations(ticket_id)
             WHERE status IN ('pending', 'payment_pending', 'confirmed')"
        );

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->string('type', 128);
            $table->jsonb('payload');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['published_at', 'created_at'], 'outbox_unpublished_idx');
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('inbox_messages', function (Blueprint $table): void {
            $table->uuid('message_id');
            $table->string('consumer', 128);
            $table->timestampTz('processed_at');
            $table->primary(['message_id', 'consumer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('events');
    }
};
