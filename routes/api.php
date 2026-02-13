<?php

use Illuminate\Support\Facades\Route;
use Lyre\AiAgents\Http\Controllers\AgentResolutionController;
use Lyre\AiAgents\Http\Controllers\EventIngestionController;
use Lyre\AiAgents\Http\Controllers\RunController;

Route::post('/agents/resolve', [AgentResolutionController::class, 'resolve']);
Route::post('/events', [EventIngestionController::class, 'store']);
Route::post('/run', [RunController::class, 'run']);
Route::post('/stream', [RunController::class, 'stream']);
