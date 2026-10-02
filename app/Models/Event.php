<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = ['name', 'starts_at', 'sales_start_at', 'total_tickets', 'is_active'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'sales_start_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
