<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BasSummaryController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyStatementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceEmailController;
use App\Http\Controllers\InvoiceLineController;
use App\Http\Controllers\InvoiceReminderController;
use App\Http\Controllers\JobRunController;
use App\Http\Controllers\MailLogController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductPriceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfitLossController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RecurringController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskCategoryController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskLogController;
use App\Http\Middleware\ApplySettings;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::get('/profile/photo', [ProfileController::class, 'photo'])->name('profile.photo');
    // The user's own API tokens, on the profile page. A token is shown once, when it is made.
    Route::post('/profile/tokens', [ApiTokenController::class, 'store'])->name('profile.tokens.store');
    Route::delete('/profile/tokens/{token}', [ApiTokenController::class, 'destroy'])->name('profile.tokens.destroy');

    // Companies are never deleted here: a client or supplier that is done with is made inactive.
    Route::resource('companies', CompanyController::class)->except('destroy');
    Route::get('/companies/{company}/statement', [CompanyStatementController::class, 'create'])->name('companies.statement.create');
    Route::post('/companies/{company}/statement', [CompanyStatementController::class, 'store'])->name('companies.statement.store');
    Route::get('/companies/{company}/statement/pdf', [CompanyStatementController::class, 'pdf'])->name('companies.statement.pdf');

    Route::resource('projects', ProjectController::class);

    // POST finds or starts a client's open invoice.
    Route::resource('invoices', InvoiceController::class)->except('create');
    Route::get('/invoices/{invoice}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('/invoices/{invoice}/task-list', [InvoiceController::class, 'taskList'])->name('invoices.task-list');
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::post('/invoices/{invoice}/reopen', [InvoiceController::class, 'reopen'])->name('invoices.reopen');
    Route::get('/invoices/{invoice}/cancel', [InvoiceController::class, 'confirmCancel'])->name('invoices.cancel.confirm');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::get('/invoices/{invoice}/email', [InvoiceEmailController::class, 'create'])->name('invoices.email.create');
    Route::post('/invoices/{invoice}/email', [InvoiceEmailController::class, 'store'])->name('invoices.email.store');
    // An overdue invoice's reminder: previewed and sent, passed over once, or switched off for that invoice.
    Route::get('/invoices/{invoice}/reminder', [InvoiceReminderController::class, 'create'])->name('invoices.reminder.create');
    Route::post('/invoices/{invoice}/reminder', [InvoiceReminderController::class, 'store'])->name('invoices.reminder.store');
    Route::post('/invoices/{invoice}/reminder/skip', [InvoiceReminderController::class, 'skip'])->name('invoices.reminder.skip');
    Route::put('/invoices/{invoice}/reminders', [InvoiceReminderController::class, 'toggle'])->name('invoices.reminders.toggle');
    Route::resource('invoices.payments', PaymentController::class)->shallow()->only('create', 'store', 'destroy');
    Route::resource('invoices.lines', InvoiceLineController::class)->shallow()->except('index', 'show');

    Route::resource('recurrings', RecurringController::class)->except('show');

    // What is waiting for the owner to send. POST issues an open invoice and queues its email together.
    Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
    Route::get('/review/{invoice}', [ReviewController::class, 'show'])->name('review.show');
    Route::post('/review/{invoice}', [ReviewController::class, 'send'])->name('review.send');

    Route::resource('expenses', ExpenseController::class)->except('show');
    Route::resource('expense-categories', ExpenseCategoryController::class)->except('show');

    // A task is never deleted: it is cancelled.
    Route::resource('tasks', TaskController::class)->except('destroy');
    Route::post('/tasks/{task}/close', [TaskController::class, 'close'])->name('tasks.close');
    Route::post('/tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
    Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
    // Time logs are added and edited in the shared dialog.
    Route::resource('task-logs', TaskLogController::class)->except('show');
    Route::resource('task-categories', TaskCategoryController::class)->except('show');

    Route::get('/products/prices', [ProductPriceController::class, 'edit'])->name('products.prices.edit');
    Route::post('/products/prices/preview', [ProductPriceController::class, 'preview'])->name('products.prices.preview');
    Route::put('/products/prices', [ProductPriceController::class, 'update'])->name('products.prices.update');
    Route::get('/products/{product}/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
    Route::resource('products', ProductController::class)->except('show');
    Route::resource('product-categories', ProductCategoryController::class)->except('show');

    // Watched sites. POST …/check asks for a check now; only the checks change a site's state.
    Route::resource('domains', DomainController::class);
    Route::post('/domains/{domain}/check', [DomainController::class, 'check'])->name('domains.check');

    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('/attachments/{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])->name('attachments.thumbnail');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    // The signed-in user's own. Opening one marks it read and goes where it leads.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/menu', [NotificationController::class, 'menu'])->name('notifications.menu');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');

    Route::get('/mail-log', [MailLogController::class, 'index'])->name('mail-log.index');
    Route::post('/mail-log/{mailLog}/resend', [MailLogController::class, 'resend'])->name('mail-log.resend');

    Route::get('/reports/profit-loss', [ProfitLossController::class, 'show'])->name('reports.profit-loss');
    // Only while GST is on: it answers 404 with it off.
    Route::get('/reports/bas', BasSummaryController::class)->name('reports.bas');
    Route::get('/reports/sales', SalesReportController::class)->name('reports.sales');
    Route::get('/reports/profit-loss/pdf', [ProfitLossController::class, 'pdf'])->name('reports.profit-loss.pdf');

    Route::get('/jobs', [JobRunController::class, 'index'])->name('jobs.index');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/test-mail', [SettingsController::class, 'testMail'])->name('settings.test-mail');
    Route::post('/settings/mail-preview/{template}', [SettingsController::class, 'mailPreview'])->name('settings.mail-preview');
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
