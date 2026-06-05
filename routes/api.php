<?php

use App\Domains\Contact\Import\Api\Controllers\ImportController;
use App\Domains\Settings\ManageUsers\Api\Controllers\UserController;
use App\Domains\Vault\ManageVault\Api\Controllers\VaultController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    // users
    Route::get('user', [UserController::class, 'user']);
    Route::apiResource('users', UserController::class)->only(['index', 'show']);

    // vaults
    Route::apiResource('vaults', VaultController::class);

    // imports
    Route::apiResource('import', ImportController::class)->only(['index', 'show', 'store']);
    Route::post('import/{import}/cancel', [ImportController::class, 'cancel']);
    Route::get('import/{import}/errors', [ImportController::class, 'errors']);
    Route::get('import/{import}/errors.csv', [ImportController::class, 'downloadErrors']);
});
