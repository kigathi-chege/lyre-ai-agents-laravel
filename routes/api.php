<?php

use Illuminate\Support\Facades\Route;
use Lyre\AiAgents\Http\Controllers\EventIngestionController;
use Lyre\AiAgents\Http\Controllers\RunController;

Route::post('/events', [EventIngestionController::class, 'store']);
Route::post('/run', [RunController::class, 'run']);
Route::post('/stream', [RunController::class, 'stream']);
