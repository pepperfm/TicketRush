<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['aggregate_type', 'aggregate_id', 'type', 'payload', 'published_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function record(string $aggregateType, string $aggregateId, string $type, array $payload): self
    {
        return self::query()->create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'type' => $type,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
