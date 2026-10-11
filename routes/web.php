<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskCategoryController;
use App\Http\Controllers\TaskController;
use App\Http\Middleware\ApplySettings;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('auth')->group(function () {
    Route::view('/', 'dashboard')->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::get('/profile/photo', [ProfileController::class, 'photo'])->name('profile.photo');

    // Companies are never deleted here: a client or supplier that is done with is made inactive.
    Route::resource('companies', CompanyController::class)->except('destroy');

    Route::resource('projects', ProjectController::class);

    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::resource('task-categories', TaskCategoryController::class)->except('show');

    Route::get('/products/prices', [ProductPriceController::class, 'edit'])->name('products.prices.edit');
    Route::post('/products/prices/preview', [ProductPriceController::class, 'preview'])->name('products.prices.preview');
    Route::put('/products/prices', [ProductPriceController::class, 'update'])->name('products.prices.update');
    Route::get('/products/{product}/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
    Route::resource('products', ProductController::class)->except('show');
    Route::resource('product-categories', ProductCategoryController::class)->except('show');

    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('/attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])->name('attachments.thumbnail');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    // The signed-in user's own. Opening one marks it read and goes where it leads.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/menu', [NotificationController::class, 'menu'])->name('notifications.menu');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

// The container health check. No session, cookies or settings: it must answer before the first migration.
Route::get('/up', HealthController::class)
    ->name('health')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        ApplySettings::class,
    ]);

require __DIR__.'/auth.php';
