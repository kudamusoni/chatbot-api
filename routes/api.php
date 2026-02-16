<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ConversationController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Widget\AppraisalConfirmController;
use App\Http\Controllers\Widget\BackToChatController;
use App\Http\Controllers\Widget\BootstrapController;
use App\Http\Controllers\Widget\ChatController;
use App\Http\Controllers\Widget\HistoryController;
use App\Http\Controllers\Widget\LeadIdentityConfirmController;
use App\Http\Controllers\Widget\ResetController;
use App\Http\Controllers\Widget\SseController;
use App\Http\Controllers\Widget\ValuationRetryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Widget routes (no auth - public endpoints for chatbot widget)
Route::prefix('widget')->group(function () {
    Route::post('bootstrap', [BootstrapController::class, 'store']);
    Route::post('chat', [ChatController::class, 'store']);
    Route::post('appraisal/confirm', [AppraisalConfirmController::class, 'store']);
    Route::post('lead/confirm-identity', [LeadIdentityConfirmController::class, 'store']);
    Route::post('back-to-chat', [BackToChatController::class, 'store']);
    Route::post('valuation/retry', [ValuationRetryController::class, 'store']);
    Route::post('reset', [ResetController::class, 'store']);
    Route::get('history', [HistoryController::class, 'index']);
    Route::get('sse', [SseController::class, 'stream']);
});

// Admin routes (Sanctum auth)
Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::get('conversations/{conversation}/events', [ConversationController::class, 'events']);
        Route::get('leads', [LeadController::class, 'index']);
        Route::get('leads/{lead}', [LeadController::class, 'show']);
    });
});
