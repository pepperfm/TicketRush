<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('reservations:expire')
    ->everySecond()
    ->withoutOverlapping();

Schedule::command('outbox:publish')
    ->everySecond()
    ->withoutOverlapping();

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes();
