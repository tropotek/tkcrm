<?php

namespace App\Providers;

use App\Mail\RedirectAllMail;
use App\Models\Company;
use App\Models\Domain;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Recurring;
use App\Models\Task;
use App\Models\TaskLog;
use App\Support\Owner;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: one per request, so the stored settings are read once a request and never go stale under Octane.
        $this->app->scoped(Settings::class);
        $this->app->scoped(Owner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // MAIL_REDIRECT_TO: every message the app sends, whatever sends it.
        Event::listen(MessageSending::class, RedirectAllMail::class);

        // The names records are stored under in polymorphic columns (attachments, invoice line sources
        // and the mail log). A model that takes attachments, or that mail is logged against, must be listed here.
        Relation::morphMap([
            'task' => Task::class,
            'task_log' => TaskLog::class,
            'invoice' => Invoice::class,
            'company' => Company::class,
            'recurring' => Recurring::class,
            'expense' => Expense::class,
            'domain' => Domain::class,
        ]);
    }
}
