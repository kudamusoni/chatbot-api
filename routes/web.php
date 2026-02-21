<?php

use App\Http\Controllers\App\AuthController as AppAuthController;
use App\Http\Controllers\App\AppraisalQuestionsController;
use App\Http\Controllers\App\AnalyticsController;
use App\Http\Controllers\App\CatalogImportController;
use App\Http\Controllers\App\ClientContextController;
use App\Http\Controllers\App\ConversationController;
use App\Http\Controllers\App\EmbedCodeController;
use App\Http\Controllers\App\LeadController as AppLeadController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\ValuationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test', function () {
    return view('test');
});

Route::prefix('app')
    ->middleware(['force.json.app'])
    ->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('login', [AppAuthController::class, 'login']);
            Route::post('logout', [AppAuthController::class, 'logout'])->middleware('app.auth');
            Route::get('me', [AppAuthController::class, 'me'])->middleware('app.auth');
        });

        Route::middleware('app.auth')->group(function () {
            Route::get('clients', [ClientContextController::class, 'index']);
            Route::post('clients/{client}/switch', [ClientContextController::class, 'switch']);
            Route::post('clients/clear', [ClientContextController::class, 'clear']);
        });

        Route::middleware(['app.auth', 'set.current.client'])->group(function () {
            Route::get('appraisal-questions', [AppraisalQuestionsController::class, 'index']);
            Route::get('leads', [AppLeadController::class, 'index']);
            Route::get('leads/export', [AppLeadController::class, 'export']);
            Route::get('leads/{id}', [AppLeadController::class, 'show']);
            Route::get('conversations', [ConversationController::class, 'index']);
            Route::get('conversations/{id}/messages', [ConversationController::class, 'messages']);
            Route::get('conversations/{id}/events', [ConversationController::class, 'events']);
            Route::get('valuations', [ValuationController::class, 'index']);
            Route::get('valuations/{id}', [ValuationController::class, 'show']);
            Route::get('analytics/summary', [AnalyticsController::class, 'summary']);
            Route::get('analytics/timeseries', [AnalyticsController::class, 'timeseries']);
            Route::get('settings', [SettingsController::class, 'show']);
            Route::get('embed-code', [EmbedCodeController::class, 'show']);
            Route::get('catalog-imports', [CatalogImportController::class, 'index']);
            Route::get('catalog-imports/{catalogImportId}', [CatalogImportController::class, 'show']);
            Route::get('catalog-imports/{catalogImportId}/errors', [CatalogImportController::class, 'errors']);

            Route::middleware('require.tenant.role:owner,admin')->group(function () {
                Route::put('appraisal-questions/reorder', [AppraisalQuestionsController::class, 'reorder']);
                Route::post('appraisal-questions', [AppraisalQuestionsController::class, 'store']);
                Route::put('appraisal-questions/{id}', [AppraisalQuestionsController::class, 'update']);
                Route::delete('appraisal-questions/{id}', [AppraisalQuestionsController::class, 'destroy']);
                Route::patch('leads/{id}', [AppLeadController::class, 'update']);
                Route::put('settings', [SettingsController::class, 'update']);
                Route::put('settings/domains', [SettingsController::class, 'updateDomains']);
                Route::post('catalog-imports', [CatalogImportController::class, 'store']);
                Route::post('catalog-imports/{catalogImportId}/upload', [CatalogImportController::class, 'upload']);
                Route::post('catalog-imports/{catalogImportId}/validate', [CatalogImportController::class, 'validateImport']);
                Route::post('catalog-imports/{catalogImportId}/start', [CatalogImportController::class, 'start']);
                Route::post('catalog-imports/{catalogImportId}/retry', [CatalogImportController::class, 'retry']);
            });
        });
    });
