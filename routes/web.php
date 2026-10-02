<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => response()->json([
    'name' => 'TicketRush',
    'purpose' => 'Laravel high-load laboratory',
    'health' => '/up',
    'api' => '/api',
]));
