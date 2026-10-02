<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::get('/events/{event}', EventController::class);
Route::post('/events/{event}/reservations', [ReservationController::class, 'store']);
Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
Route::post('/reservations/{reservation}/purchase', [ReservationController::class, 'purchase']);
