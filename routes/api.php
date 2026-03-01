<?php

use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FormController;
use App\Http\Controllers\Api\V1\FormFieldController;
use App\Http\Controllers\Api\V1\SubmitController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\WixController;
use App\Http\Middleware\WixInstanceAuth;
use Illuminate\Support\Facades\Route;

Route::post('/wix_webhook_smartform', [WixController::class, 'handleWixWebhooksSmartForm']);

Route::prefix('v1')->group(function () {
    // Public: widget reads form by widgetInstanceId (auth resolved inline)
    Route::get('/forms', [FormController::class, 'index']);
    Route::get('/form', [FormController::class, 'showByWidget']);

    Route::middleware(WixInstanceAuth::class)->group(function () {
        Route::get('/tenant/me', [TenantController::class, 'me']);
        Route::get('/tenant/settings', [TenantSettingsController::class, 'show']);
        Route::put('/tenant/settings', [TenantSettingsController::class, 'update']);

        Route::post('/forms/ensure', [FormController::class, 'ensure']);
        Route::put('/forms/{id}', [FormController::class, 'update']);
        Route::get('/forms/{id}/fields', [FormFieldController::class, 'index']);
        Route::post('/forms/{id}/generate-fields', [FormFieldController::class, 'generate'])
            ->middleware('throttle:5,1');
        Route::post('/forms/{id}/ai-edit', [FormFieldController::class, 'editWithAi'])
            ->middleware('throttle:5,1');
        Route::put('/forms/{id}/fields', [FormFieldController::class, 'update']);
        Route::get('/forms/{id}/submissions', [SubmissionController::class, 'index']);
        Route::get('/forms/{id}/submissions/export.csv', [SubmissionController::class, 'exportCsv']);

        Route::post('/uploads/init', [UploadController::class, 'init'])->middleware('throttle:30,1');
        Route::put('/uploads/{id}/upload', [UploadController::class, 'upload'])->middleware('throttle:30,1');
        Route::post('/uploads/complete', [UploadController::class, 'complete'])->middleware('throttle:30,1');
        Route::get('/files/{id}/download', [FileController::class, 'download']);
    });

    Route::post('/forms/{widgetInstanceId}/submit', [SubmitController::class, 'submit'])
        ->middleware('throttle:60,1');
});