<?php

use App\Http\Controllers\Api\V1\FeatureRequestController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FormController;
use App\Http\Controllers\Api\V1\FormFieldController;
use App\Http\Controllers\Api\V1\FormSettingsController;
use App\Http\Controllers\Api\V1\SubmitController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\WixController;
use App\Http\Middleware\WixInstanceAuth;
use Illuminate\Support\Facades\Route;

Route::post('/wix_webhook_smartform', [WixController::class, 'handleWixWebhooksSmartForm']);

Route::prefix('v1')->group(function () {
    // Public: widget reads form by comp_id (auth resolved inline)
    Route::get('/forms', [FormController::class, 'index']);
    Route::get('/form', [FormController::class, 'showByWidget']);

    Route::middleware(WixInstanceAuth::class)->group(function () {
        Route::post('/forms/ensure', [FormController::class, 'ensure']);
        Route::put('/forms/{id}', [FormController::class, 'update']);
        Route::get('/forms/{id}/fields', [FormFieldController::class, 'index']);
        Route::post('/forms/{id}/generate-fields', [FormFieldController::class, 'generate'])
            ->middleware('throttle:5,1');
        Route::post('/forms/{id}/ai-edit', [FormFieldController::class, 'editWithAi'])
            ->middleware('throttle:5,1');
        Route::post('/forms/{id}/translate', [FormFieldController::class, 'translate'])
            ->middleware('throttle:5,1');
        Route::post('/feature-requests', [FeatureRequestController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::put('/forms/{id}/fields', [FormFieldController::class, 'update']);
        Route::get('/forms/{id}/submissions', [SubmissionController::class, 'index']);
        Route::get('/forms/{id}/submissions/export.csv', [SubmissionController::class, 'exportCsv']);

        Route::get('/forms/{formId}/settings', [FormSettingsController::class, 'show']);
        Route::put('/forms/{formId}/settings', [FormSettingsController::class, 'update']);

        Route::post('/uploads/init', [UploadController::class, 'init'])->middleware('throttle:30,1');
        Route::put('/uploads/{id}/upload', [UploadController::class, 'upload'])->middleware('throttle:30,1');
        Route::post('/uploads/complete', [UploadController::class, 'complete'])->middleware('throttle:30,1');
        Route::get('/files/{id}/download', [FileController::class, 'download']);
    });

    // Public signed download for admin notification emails (no Wix auth)
    Route::get('/files/{id}/download-public', [FileController::class, 'downloadPublic'])
        ->middleware('signed')
        ->name('files.download-public');

    Route::post('/forms/{compId}/submit', [SubmitController::class, 'submit'])
        ->middleware('throttle:60,1');
    Route::get('/forms/{compId}/submissions/{submissionId}', [SubmitController::class, 'getSubmissionForEdit']);
    Route::put('/forms/{compId}/submissions/{submissionId}', [SubmitController::class, 'updateSubmission']);
});
