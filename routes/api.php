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

/*
 * Convenience endpoint for API exploration (Postman, curl, etc.).
 * Returns a valid Sanctum token and a vault ID for the first user found.
 * Intended for development/testing only — not for production use.
 */
Route::get('/get-token', function () {
    /** @var \App\Models\User|null $user */
    $user = \App\Models\User::with('vaults')->first();

    if (! $user) {
        return response()->json([
            'message' => 'No users found. Run database migrations and seeders first.',
        ], 404);
    }

    $vault = $user->vaults->first();

    return response()->json([
        'token' => $user->createToken('api-explorer')->plainTextToken,
        'vault_id' => $vault?->id,
    ]);
});
