<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\ContactController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — MailForge AI
|--------------------------------------------------------------------------
|
| Version 1 API Routes. Authentication is handled via Laravel Sanctum
| tokens for all non-public endpoints.
|
*/

Route::prefix('v1')->group(function () {
    // Authentication Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/user', [AuthController::class, 'user']);
        });
    });

    // Authenticated REST API Routes
    Route::middleware('auth:sanctum')->group(function () {
        // Contacts API
        Route::post('/contacts/import', [ContactController::class, 'import']);
        Route::apiResource('contacts', ContactController::class);

        // Campaign Management API
        Route::get('/campaigns/{campaign}/status', [CampaignController::class, 'status']);
        Route::get('/campaigns/{campaign}/delivery-status', [CampaignController::class, 'deliveryStatus']);
        Route::get('/campaigns/{campaign}/recipients', [CampaignController::class, 'recipients']);
        Route::post('/campaigns/{campaign}/recipients', [CampaignController::class, 'addRecipients']);
        Route::post('/campaigns/{campaign}/generate', [CampaignController::class, 'generate']);
        Route::post('/campaigns/{campaign}/approve', [CampaignController::class, 'approve']);
        Route::post('/campaigns/{campaign}/dispatch', [CampaignController::class, 'dispatch']);
        Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
        Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
        Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
        Route::apiResource('campaigns', CampaignController::class);
    });
});
