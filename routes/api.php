<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\ApiTokenController;
use Illuminate\Support\Facades\Route;

/*
| The read-only JSON API, under /api/v1. Every route is a GET and needs a personal access token
| with the `read` ability, made on the profile page. Nothing here changes a record, issues an
| invoice or sends mail. Errors are JSON.
*/

Route::prefix('v1')->name('api.')->middleware(['auth:sanctum', 'abilities:'.ApiTokenController::ABILITY, 'throttle:api'])->group(function () {
    Route::get('/', [ApiController::class, 'index'])->name('index');

    Route::get('/clients', [ApiController::class, 'clients'])->name('clients');
    Route::get('/invoices', [ApiController::class, 'invoices'])->name('invoices');
    Route::get('/outstanding', [ApiController::class, 'outstanding'])->name('outstanding');
    Route::get('/tasks', [ApiController::class, 'tasks'])->name('tasks');
    Route::get('/time-logs', [ApiController::class, 'timeLogs'])->name('time-logs');
    Route::get('/recurring', [ApiController::class, 'recurrings'])->name('recurring');
    Route::get('/expenses', [ApiController::class, 'expenses'])->name('expenses');
    Route::get('/profit-loss/{year?}', [ApiController::class, 'profitLoss'])->where('year', '(19|20)\d\d')->name('profit-loss');
    Route::get('/sites', [ApiController::class, 'sites'])->name('sites');
});
