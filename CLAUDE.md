# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project overview

tkcrm is a Laravel 13 app for one owner-operator: clients, tasks and time logs, invoices and payments, recurring billing, expenses, a profit and loss report per financial year, and client site monitoring. It is a rewrite of tktask (`~/Projects/Dev/tktask`). AUD is the only base currency.

The design and the task list live in `_notes/laravel-rewrite/` (gitignored). `README.md` there is the proposal and the task index; each task has its own file. Read the task file before starting a task, and tick it off there when it is done.

The live tktask at `https://task.ttek.org/` sends real invoices. Nothing here connects to it or changes it before the cut-over runbook (task A4).

## Stack

- PHP 8.4, Laravel 13, Octane on FrankenPHP
- SQLite in WAL mode
- Laravel Sanctum for API tokens, `laravel/mcp` for the MCP server
- Vite, Bootstrap 5 with the Minton default skin (light and dark), ApexCharts for charts, htmx and Alpine
- dompdf for PDFs, built in-process
- PHPUnit for tests (not Pest), Pint for code style

## Commands

There is no PHP with SQLite or Composer on the host. Everything runs in the dev containers (`docker-compose.yml`):

```bash
docker compose up -d                              # app, vite, mailpit
docker compose --profile worker up -d             # the queue and scheduler too: only when working on mail, billing or pings
docker compose exec app composer install
docker compose exec app php artisan migrate                # apply new migrations; keeps the data
docker compose exec app php artisan db:seed                # add any missing development data (local only)
docker compose exec app composer fresh                     # roll every migration back, migrate and seed
docker compose exec app php artisan tkcrm:create-user you@example.com   # create a login (--name, --password, --timezone)
docker compose exec app php artisan tkcrm:bill-recurring      # add a line for each due recurring item to its client's open invoice; sends nothing
docker compose exec app php artisan tkcrm:send-review-digest  # email every login what is waiting in the review queue, if anything is
docker compose exec app php artisan tkcrm:check-sites         # check every active watched site now (every 5 minutes in the worker)
docker compose exec app php artisan tkcrm:import-tktask --dry-run   # what an import of the tktask copy would bring (TKTASK_DB_* in .env)
docker compose exec app php artisan tkcrm:prune-notifications  # delete read notifications older than 30 days (nightly in the worker)
docker compose exec app composer test
docker compose exec app vendor/bin/pint
docker compose build app                          # after changing the Dockerfile
```

| Service | Where | What |
|---|---|---|
| `app` | http://localhost:8780 | Octane on FrankenPHP with `--watch`, so PHP changes reload on save |
| `vite` | http://localhost:5780 | Vite dev server, hot reload |
| `worker` | — | `queue:listen` and `schedule:work` in one container. Opt-in (`--profile worker`), so dev never bills, mails or pings by itself |
| `mailpit` | http://localhost:8781 | catches all outgoing mail |

In dev the page loads its scripts from the Vite server's own address (the LAN IP in the page source), so open the app on that address, not `localhost`, when checking it in a browser: on `localhost` htmx and Alpine never load.

The ports come from `APP_PORT`, `VITE_PORT` and `MAILPIT_PORT` in `.env`. Wicket's dev stack uses 8777 and 5777, Nugget's 8778, 5778 and 8779.

Without the worker, queued jobs (all client mail from task 50 on) wait in the `jobs` table. Start the worker, or run `docker compose exec app php artisan queue:work --once`, to send them to Mailpit.

The containers load `.env` as real environment variables, so a change to `.env` needs `docker compose up -d` to recreate them, and `phpunit.xml` forces its test settings with `<server force="true">`.

Do not run `php artisan migrate:fresh` or `db:wipe` while the app container is up. On SQLite they empty the database file, and Octane still has it open in WAL mode, so every later query fails with "database is locked". `composer fresh` uses `migrate:refresh` for that reason. If it happens, `docker compose restart app` and migrate again.

The seeder makes one dev login, `owner@example.com` with the password `password`.

`composer test` clears the config cache and runs the suite. Tests run against an in-memory SQLite database. The suite runs in one process and peaks near 115 MB, so `phpunit.xml` gives it a 512M memory limit; PHP's 128M default was passed once (in a dompdf test) at 1,125 tests.

## Layout

- `app/`: application code, `App\` namespace.
- `data/`: runtime data, gitignored. The SQLite database is `data/tkcrm.sqlite` (WAL mode). In production this directory is the mounted volume.
- `database/migrations/`: schema.
- `Dockerfile`, `docker-compose.yml`, `docker/`: the dev image and containers. `docker/Caddyfile` is Octane's FrankenPHP site config. `docker/php.ini` holds the upload limits and is copied into the image, so changing it needs `docker compose build app`.
- `resources/scss/`: `app.scss` and `icons.scss` are the entries. `config/default` and `custom/` are lifted from Minton (`~/Projects/Dev/Minton/Minton/resources/scss`).
- `resources/js/`: `app.js` is the entry. `minton/` holds the template's own scripts. `modal.js` drives the shared dialog.
- `resources/views/components/layouts/`: the page layouts. `<x-layouts.app title="…">` is the signed-in shell (top bar, sidebar, page title, flash messages, footer, shared dialog); `<x-layouts.guest>` is the card for the login pages.
- `/up` (`HealthController`, `App\Support\HealthStatus`): the health check the containers poll, and the reply tkcrm's own site monitor reads from a Laravel site. 200 with `version` and `db: ok` when the database accepts a write, 503 with `db: error` otherwise.
- `config/tkcrm.php`: tkcrm's own settings that come from the environment.
- `resources/views/components/`: `field` (a form's text field), `company-select`, `duration` (hours and minutes), `attachments`, `modal` (what a dialog route returns) and `date`.
- `config/navigation.php`: the sidebar menu, read by `App\Support\Navigation`.
- `routes/api.php`, `app/Api/`: the read-only JSON API. `routes/ai.php`, `app/Mcp/`: the MCP server over it.
- `resources/fonts/`, `resources/images/`: icon fonts and images the SCSS refers to.
- `tests/Feature`, `tests/Unit`: PHPUnit tests.
- `_notes/`: local planning docs, gitignored.
- `version.md`: the app version.

## Conventions

- Money is never a float. An amount is an `App\Support\Money` (whole cents), stored in an integer column through `App\Casts\MoneyCast`. `Money::parse()` reads what a person typed, `->format()` gives `$1,234.50` for a page or PDF, and `->toDecimal()` gives `1234.50` for a form field or the API. Validate a money field with `new App\Rules\MoneyAmount`.
- A quantity is a whole number or a decimal string, never a float: `$price->multiply('1.25')`. A rate is basis points: `$amount->rate(1000)` is 10% of it, and `App\Support\BasisPoints` converts to and from a percentage. Both round half up to the cent (half a cent goes away from zero).
- Do sums on cents or with BCMath. Never use `round()`, `floatval()`, `number_format()` on an amount, or a `decimal`/`float` column for money.
- An issued invoice stores its totals. Nothing recalculates them from the lines afterwards.
- Nothing is issued or emailed to a client without the owner's click.
- Invoice totals, GST, cycle dates and the profit and loss are written test-first.
- Real client data never enters this repo: no copy of the tktask database, its `data/` files, client names, addresses, emails or invoices in code, fixtures, tests or seeders. The importer is tested against a small made-up fixture database.
- No test sends real mail.
- jQuery is imported only by the scripts in `resources/js/minton/` and is not a global. tkcrm's own front-end code does not use it.
- Only the Minton partials tkcrm uses are in the repo. To use another, copy it from Minton and import it in `app.scss`. In a copied partial, a `url()` path is relative to that partial's own file.
- ApexCharts is the only chart library.
- Every planned screen is already in `config/navigation.php` under a route name (`invoices.index`, `tasks.index`, `reports.profit-loss`, …) and shows greyed in the sidebar until that route exists. A new screen uses the name listed there, or changes it there.
- A dialog is loaded by htmx into the one shared modal: the trigger has `hx-get="…" hx-target="#app-modal-content"`, and the route returns an `<x-modal title="…" :action="…">`. A 422 reply re-renders the form in the dialog; a reply with the header `HX-Trigger: modal-close` closes it. htmx requests carry the CSRF token automatically.
- Flash messages: `->with('success' | 'error' | 'warning' | 'info', '…')`.
- Every page needs a signed-in user: put its route inside the `auth` group in `routes/web.php`. The login and password reset routes are in `routes/auth.php`. There is no registration. `RoutesNeedLoginTest` lists the only routes open to a guest and fails when another appears.
- API tokens are Laravel Sanctum's, made and revoked on the profile page (`ApiTokenController`), each with the one ability `read`. An API route is behind `['auth:sanctum', 'abilities:read']`. A token is never a login to the screens and the web session is never a login to the API; Sanctum's own cookie route is off (`sanctum.routes`).
- The read-only API is `/api/v1` (`routes/api.php`), GET only. What each endpoint answers is a method of `App\Api\ApiData`, which reads through the screen's own search or report class with the screen's own filter names; never query for the API separately. A list is paged with `App\Api\Page`, and values are written with `App\Api\Format` (amounts as decimal strings, days as `Y-m-d`, moments in UTC). An endpoint is added to `ApiData`, `ApiController`, `routes/api.php` and the index's list together.
- The MCP server is `App\Mcp\TkcrmServer` at `POST /mcp` (`routes/ai.php`), behind the API's own token, ability and rate limit, with one read-only tool for each API endpoint. A tool extends `App\Mcp\Tools\ApiTool` and returns what the same `ApiData` method gives its endpoint, with the endpoint's filter names as its arguments: add a tool with every new endpoint, and never give a tool anything that writes or sends.
- tktask's data comes in through `tkcrm:import-tktask` (`App\Import\Importer`), which reads the `tktask` connection (`TKTASK_DB_*`): a copy in tktask's dev stack, never the live server, and never written to. The importer first empties every table in `Importer::GUARDED` (kept in an order the foreign keys allow; a new imported table is added there). A pass (`App\Import\Pass`, listed in `Importer::PASSES`) then fills its own, keeps tktask's ids, and gives each row tktask's own created and modified times, which is how the next run tells an imported row from one made in tkcrm (refused without `--force`). `TktaskSource::SCHEMA` is every tktask table and column it knows. A pass is tested with the `TktaskDatabase` trait and made-up rows.
- Private files are never served by a signed URL (`serve` is off on the `local` disk): a controller behind the login sends them.
- Login and password recovery are throttled per email and IP address together, with the limits in `config/tkcrm.php`. Recovery and reset answer the same whether or not the email has an account; keep it that way when changing them.
- Emails are stored in lower case and SQLite compares them exactly, so lower-case an email before looking a user up by it.
- `laravel/breeze` is a dev dependency used only as a source of stubs to copy from `vendor/laravel/breeze/stubs/default`. Never run `breeze:install`: it would replace the layout and the Vite setup with Tailwind.
- Dates are stored in UTC and shown in the reader's timezone. In a view use `<x-date :value="…" />` (it takes a `format`); elsewhere use `App\Support\LocalTime::of($date)`. Never format a stored date directly.
- The private `local` disk is `data/private`, outside the web root and inside the one directory that is a volume in production. Nothing on it has a URL of its own: a controller behind the login sends each file.
- A user's photo is under `photos/` there and is sent by `ProfileController::photo` to that user only. Use `$user->photoUrl()` for its address.
- Files are attached to a record with `<x-attachments :for="$record" />`. The record's model uses `App\Attachments\HasAttachments` and is listed in the morph map in `AppServiceProvider` under the name it is stored by. The size limit and the kinds of file accepted are in `config/tkcrm.php`; a file's kind is read from its contents.
- Settings the owner can change are read with `App\Facades\Settings::get('key')`. Every key, its default, type and validation rules are in `App\Support\Settings::definitions()`; a new setting is added there and to `settings/edit.blade.php`. An unknown key throws. The table is read once per request.
- A rate setting (GST, the tax estimate) is read in basis points; `Settings::percentage()` and `setPercentage()` convert for the settings form.
- The site name comes from the `site_name` setting, copied into `config('app.name')` for each web request by `ApplySettings`. Code outside a web request (a queued job, a command) reads `Settings::get('site_name')`.
- A company (`App\Models\Company`) is a client or a supplier (`App\Enums\CompanyType`). `$company->account_number` is `CO-` and the id padded to ten digits, so an imported company must keep its tktask id. There is no client credit.
- The business's own name, ABN, address, phone and email come from `App\Support\Owner` (inject it or `app(Owner::class)`), which reads the company chosen as the owner in the settings. Nothing else reads the `owner_company_id` setting, and no PDF or email hard-codes those details.
- A product (`App\Models\Product`, in a `ProductCategory`) has a `price` (Money), a unique `code`, a default `cycle` (`App\Enums\Cycle`). Its price is copied onto an invoice line or a time log when that is made, so a price change never alters what is already billed or logged.
- A recurring item (`App\Models\Recurring`) bills a company every cycle (`Cycle::recurring()`: any but each). With no `price` of its own it follows its product's: bill from `$recurring->billedPrice()`, read when the line is made and copied onto it. It needs a product or a price. `previous_on`, `next_on` and `count` are not fillable: the run that bills it keeps them.
- A recurring item's billing days are `$recurring->dates()` (`App\Recurrings\CycleDates`), numbered from 0: `date(n)` is the start date plus n cycles, always worked out from the start and never from the date before, with a month's end clamped. `period(n)->label()` is what a billing covers, for its invoice line. Nothing else adds a cycle to a date.
- Due recurring items are billed only by `App\Recurrings\RecurringRun` (`tkcrm:bill-recurring`): one line per cycle on the client's open invoice, with the item as its source and the period in its description, and a notice per client. It issues and sends nothing. An item moves on in the same step that makes its line, and only from the next date it was read at, so a cycle is billed once however often it runs; an inactive item moves on with no line, and one past its `end_on` (the last day it may bill) is made inactive.
- Making a company inactive does not stop its recurring items by itself: its edit form offers to make them inactive too (`stop_recurrings`, ticked to begin with) and the save warns when they are left billing. Anything else that makes a company inactive must do the same.
- Recurring items are listed and edited at `/recurrings` (`RecurringController`, `App\Recurrings\RecurringSearch`). Once the run has moved an item on (`$recurring->hasRun()`) its start and cycle are fixed and it cannot be deleted, only ended or made inactive; until then its `next_on` follows its `start_on`. What it comes to a year is `$recurring->yearlyValue()` (`Cycle::timesPerYear()`).
- An expense (`App\Models\Expense`) is bought from a supplier company, in an `ExpenseCategory`. Its `total` is what was paid with any GST in it, and `gst_included` is the amount of that GST (0 for none), never a flag. `$expense->net()` is the total less its GST; `$expense->claimable()` is its category's `claim_ratio` (basis points) of that, rounded per expense. Add up `claimable()` per expense; never apply a ratio to a sum. A refund is a negative expense. Its receipts are its attachments.
- Expenses are listed and edited at `/expenses` (`ExpenseController`, `App\Expenses\ExpenseSearch`), opening on the reader's current financial year, with `ExpenseSearch::totals()` under the table. A new expense saves and returns to its own form, because a receipt can only be attached to a record that exists. With GST off a posted `gst_included` is ignored, so an expense keeps what it had. Categories are at `/expense-categories`, with the share claimed typed as a percentage.
- A watched site is an `App\Models\Domain` of a company, and each check of it an `App\Models\DomainPing` (ok, HTTP status, response time, the JSON reply where there was one, and why it failed). The domain itself holds where it stands: `state` (`App\Enums\DomainState`), `failures` in a row, `checked_at`, `last_ok_at`, and the `version` and `details` it last reported. None of that is fillable: only the ping job sets it.
- Sites are checked only by `App\Domains\SiteChecks` (`tkcrm:check-sites`, every 5 minutes in the worker; `check($domain)` for one now). Any 2xx is up; a JSON reply is kept and read by its keys, and a `db` other than `ok` is a failure. Two failures in a row make a site down and one success makes it up, each change raising `App\Events\DomainStateChanged` once. A run with a failure in it asks the outside addresses first and records nothing if none answers. Its settings are `monitoring` in `config/tkcrm.php`. Test it with `Http::fake()`, never a real address.
- `Cycle::Biannual` means every two years, as in tktask, and is labelled that way. Do not relabel it "biannual" or treat it as twice a year.
- A status that follows from dates is never stored. A project's is `$project->status()` (`App\Enums\ProjectStatus`), with `Project::withStatus($status)` as the matching query scope; change the two together. "Today" is the signed-in user's day (`LocalTime`), not UTC's.
- A task's status is `$task->status()` (`App\Enums\TaskStatus`: closed if it has a closed date, else cancelled if it has a cancelled date, else open), with `Task::withStatus()` as its scope. `closed_at`, `cancelled_at`, `invoiced_at` and `invoice_line_id` are not fillable: closing, cancelling and billing set them.
- A closed or cancelled task is read-only until re-opened: `TaskController` refuses to edit it, and its attachments are locked with it. `$task->close()`, `cancel()` and `reopen()` are the only things that set its dates. A task is never deleted. `$task->canReopen()` is false once `isLockedByInvoice()`: its line is on an invoice that is no longer open.
- Closing a task bills it: `$task->close()` adds one line to the client's open invoice, coded `TSK-<id>`, quantity 1 at the sum of each billable log's `amount()`, with the time in the description, and sets `invoiced_at` and `invoice_line_id`. No billable time, no line. `reopen()` takes the line off again. Cancelling bills nothing.
- A model whose attachments are locked at times overrides `canChangeAttachments()` from `HasAttachments`; the panel and `AttachmentController` both obey it.
- A length of time is typed into `<x-duration name="…" label="…" :minutes="…" />`, which posts `name[hours]` and `name[minutes]`; `Duration::fromParts()` makes them minutes.
- A time log (`App\Models\TaskLog`) copies its `rate` (cents per hour) from its product when it is made, and again only if its product is changed. `$log->amount()` is the rate for its minutes, to the cent. Bill from the log's rate, never from the product's current price.
- Time logs are added and edited in the shared dialog (`TaskLogController`, `task-logs/dialog.blade.php`), from a task's page (`?task=ID`) or from the list of all logs at `/task-logs`. A save answers `HX-Refresh: true` with a flashed message instead of `modal-close`, because a log changes the page's totals as well as its list. Use that when a dialog's save changes more of the page than one table.
- A time log's start is typed in the user's timezone (`datetime-local`) and stored as a UTC moment; the list's date range is in the reader's days. The logs of a closed or cancelled task are read-only.
- An invoice (`App\Models\Invoice`) belongs to a company and is open until issued. Its number, dates and stored totals are not fillable: issuing, paying, cancelling and re-opening set them. 
- An invoice's figures come from `$invoice->totals()` (`App\Invoices\InvoiceTotals`): the stored ones once issued, calculated from the lines until then. Nothing else adds up lines. A line's own total is `$line->total()`. GST is added on top of prices: the rate on the sub-total less the discount, rounded once.
- An invoice's status is `$invoice->status()` (`App\Enums\InvoiceStatus`: cancelled, else paid, else unpaid once issued, else open), with `Invoice::withStatus()` as its scope.
- A product's price is kept and typed without GST. While GST is on, the product list adds an "Inc. GST" column worked out from `Gst::rate()` for reading only; nothing stores a price with GST in it.
- GST is one rate on a whole invoice, or none: it is not set per line and no product is exempt. `App\Invoices\Gst::rate()` is the rate in force (0 with the `gst_enabled` setting off), which an open invoice is worked out at. Issuing stores `gst_rate` on the invoice with its totals, so an issued invoice keeps what it charged. `$invoice->totals()->chargesGst()` says whether it did.
- An invoice number comes only from `App\Invoices\InvoiceNumber::allocate()`, called first thing inside the transaction that issues the invoice. It moves the `next_invoice_number` setting on in the database; never work a number out from `Settings::get()` or `max(number)`.
- An invoice is changed only while `$invoice->isOpen()`: its details and lines in the shared dialog from its page (`InvoiceController`, `InvoiceLineController`), everything else answering 403. A task's line (`$line->isLockedBySource()`) is never edited or deleted on the invoice: re-opening the task takes it off. A recurring item's line is changed like any other.
- An invoice is overdue when `$invoice->isOverdue()`: unpaid and its due date before the signed-in user's today (the due date itself is not overdue). `Invoice::overdue()` is the matching scope; change the two together.
- `$invoice->issue()`, `reopen()` and `cancel()` are the only things that set an invoice's number, dates, GST rate and stored totals; each has a `…Problem()` method giving the reason it cannot be done. Issuing sends nothing. Re-opening (unpaid, no payment) clears the stored figures and keeps the number. Cancelling keeps everything, lines included, and is final; it is confirmed in the shared dialog, where a ticked box re-opens the tasks billed on it (`Task::reopenOffCancelledInvoice()`, which leaves the line in place) so their time can be billed again.
- A payment goes in through `$invoice->recordPayment()` and out through `removePayment()`, which set and clear `paid_on`; `paymentProblem()` says why one is refused (only an unpaid invoice, above zero, never more than `$invoice->outstanding()`). `recordPayment()` itself sends nothing: the payment dialog's box, ticked to begin with, has `PaymentController` email a receipt (`App\Mail\ReceiptMail`) only when the payment pays the invoice in full and the client has an email. A list that shows what is owing loads `withSum('payments', 'amount')`, which `paidTotal()` uses.
- A PDF is built in-process with dompdf from a Blade view that stands alone (its own styles, no script, nothing fetched), by a class like `App\Invoices\InvoicePdf` (or `TaskListPdf`, `App\Reports\ProfitLossPdf`) that hands its HTML to `App\Support\Pdf::render()`; the same HTML can serve as an on-screen preview. A document prints figures it is given (`$invoice->totals()`); it works nothing out.
- The invoice document is a "Tax invoice" with a GST line only when `$invoice->totals()->chargesGst()`; otherwise it is an "Invoice" that states no GST has been charged. An open invoice prints as a watermarked draft with no number.
- Whatever bills a client adds a line to `$company->openInvoice()`, which finds the company's open invoice or makes one.
- An invoice line (`App\Models\InvoiceLine`) copies its product code, description and unit price; it does not point at a product. Its quantity is a decimal string of up to four places and a float is refused. Its `source` is the record it was made from (a task), by morph map name.
- The invoices migration is dated before the tasks one, because `tasks.invoice_line_id` is a foreign key to `invoice_lines`.
- Durations are whole minutes in the database and shown with `App\Support\Duration::format()` ("8h 30m").
- A date-only value (a project's start, an invoice's due date) is shown with `->format('j M Y')`, not `<x-date>`: it is a day, not a moment, and must not shift with the timezone.
- What a project's work adds up to against its quote is `App\Projects\ProjectSummary`. Billable time is always priced per log with `$log->amount()` and then summed.
- A date-only column is cast `immutable_date` and compared in SQL with `whereDate()`, because SQLite stores it with a midnight time.
- The seeder's companies, and every factory, use made-up names and `example.com` addresses. Keep it that way.
- A list page follows `companies/`: a search object (`App\Companies\CompanySearch`) reads the filters and order from the query string, the controller returns the whole page or the `_table` partial alone when htmx asks for the table's id, and the filter form swaps the table as it changes.
- The task list (`App\Tasks\TaskSearch`, `TaskController`) remembers its filters and order in the session: opened with no query string it comes back as it was left, and `?clear=1` forgets them. A link that must show particular tasks carries its own query string.
- A form's text fields use `<x-field name="…" label="…" :value="…" />`. A company is chosen in another form with `<x-company-select>`, whose New button adds one in the shared dialog; the save answers with a `company-added` event carrying its id, name and type.
- A form request whose form can be posted from the dialog uses the `AnswersInDialog` trait. The form re-rendered in the dialog after a failed save fills its fields from the request, not from `old()`, and flashes nothing (see `CompanyRequest` and `companies/dialog.blade.php`, or `product-categories/` for a form that lives only in the dialog and refreshes its list with an event).
- Records with history are made inactive, not deleted. A company has no delete. A product can be deleted only while `$product->isInUse()` is false: a module that points at a product (time logs, recurring items) adds its check to that method. A category can be deleted only while it has no products.
- With GST off, no page shows a GST field, badge or column: check `Settings::get('gst_enabled')` first. The one exception is what goes to a client: an invoice (PDF and email) that charges no GST says so in plain words, that the business is not registered for GST and none has been charged, so the client does not add it or hold it back.
- Product prices are changed in bulk by `ProductPriceController` (the Update prices button over the product list), which only writes `products.price`. A negative percentage undoes a rise of that size (`PriceChange`: -8 divides by 1.08), so a rise can always be taken back to the cent; it is not that percentage off. Anything that bills must copy the price at the time, never read it back from the product later.
- In a dialog, mark the first field `autofocus`; `modal.js` focuses it once the dialog is open. A dialog always swaps its contents, whatever swap its trigger inherits.
- `GET /products/{id}/lookup` gives a product's code, name, description, price (a decimal string) and cycle as JSON, for a form that fills a line from a product.
- Do not give an element the id `status` or `preloader`: the Minton skin styles both and hides `#status`.
- To tell the owner something, send a database notification to every login with `App\Support\NotificationFeed::tellEveryone(...)`. A plain one is `new App\Notifications\Notice($title, $text, $url, $icon)`; one with data of its own gets a class that implements `App\Notifications\Contracts\Presentable`, whose static `present(array $data)` says how it is shown from the stored data alone. The top bar's bell, the notifications page and the browser notification all read that.
- An email is a `Mailable` with an `envelope()`, sent with `App\Models\MailLog::send($mailable, $kind, $related)`, never `Mail::send()` or the job directly: that records it in `mail_log` and queues `App\Jobs\SendMail`, which sets the owner company as the sender, retries a failed send three times, keeps the row's state (queued, sent, failed) and tells every login when it fails for good. A record mail is logged against must be in the morph map. `MAIL_REDIRECT_TO` in the environment sends every message to one address, with who it was for in the subject (`App\Mail\RedirectAllMail`).
- An email to a client takes its wording from `App\Enums\MailTemplate` (invoice, receipt, statement, reminder): `MailTemplate::Invoice->fill($values, note: …)` gives an `App\Mail\FilledTemplate`, whose `subject` goes in the envelope and whose `content()` is the Mailable's, in the one layout `mail/layout.blade.php` with a plain text part. Wording is plain text with `{placeholders}`; the default is in the enum and the owner's own is a `wording` setting, never stored while it equals the default. A new placeholder is added to `placeholders()` and `sampleValues()` together. Anything a client must be told whatever the wording says (the no-GST statement) is not left to a placeholder.
- A message's Mailable must not have a public `$filled` or `$owner`: a Mailable's public properties are given to its view and win over the layout's own two. And never pass a Mailable to a page's view: a view renders it to a string. Pass its envelope, documents and HTML.
- An issued, unpaid invoice is emailed from its page (`InvoiceEmailController`, `App\Mail\InvoiceMail`): the dialog previews the message and lists what is attached, takes a personal note, and Send queues it. `$invoice->emailProblem()` says why it cannot go (not unpaid, or the company has no email). Who an email to a client goes to is `App\Mail\ClientEnvelope::for($company, $subject)`: the company's email, cc its accounts email, bcc the owner with `bcc_owner` on. Attached: the invoice, the task list where it has task lines, and one statement (from the oldest unpaid invoice's issue day to today) when the client has other unpaid invoices. A message the owner previews before it goes follows this shape: a Mailable with `filled()` and a `documents()` list that the dialog and `attachments()` both read, shown in the shared preview-and-send dialog `<x-mail-dialog>`. A Mailable has `$from`, `$to`, `$subject` and more of its own: do not name a constructor property after one.
- The review queue (`/review`, `App\Review\ReviewQueue`) is every open invoice with lines, and every overdue invoice due a reminder. `App\Review\IssueAndSend` issues one and queues its email in one transaction on the owner's click, and previews that email by issuing inside a transaction it rolls back, so what is shown is what would go. A sidebar link shows a count with a `badge` name in `config/navigation.php`, which `App\Support\Navigation::badge()` counts.
- An overdue reminder is only ever drafted. `$invoice->reminderDueOn()` is the day the next falls due: 7 days after the due date, then 14 days after `reminded_on` (the day the last was sent or passed over), and never with `reminders_off`. `isReminderDue()` and the `reminderDue()` scope go with it; change the three together. A due one waits in the review queue, and is sent from the shared dialog (`InvoiceReminderController`, `App\Mail\ReminderMail`, with the invoice and a statement attached), which calls `$invoice->markReminded()`.
- The review digest (`tkcrm:send-review-digest`, `App\Mail\ReviewDigestMail`) goes to every login's email, and not at all when nothing is waiting.
- A client's statement is `App\Statements\Statement::for($company, $from, $to)`: the opening balance, each invoice (from its issue day, at its stored total) and payment (on the reader's day it was received) in the range with a running balance, the closing balance, and the invoices unpaid as at the last day or today, whichever is earlier, which always add up to the closing balance. Open and cancelled invoices are left out. `App\Statements\StatementPdf` prints it; nothing else works a client's balance out over time.
- A client's statement is opened and emailed from the company's page (`CompanyStatementController`, `App\Mail\StatementMail`), for a range that begins as the current financial year. A client with no email can still open the PDF.
- The profit and loss is `App\Reports\ProfitLoss::for($year)`, and nothing else works its figures out: income is the payments received in the year (the reader's day), expenses are by category with `claimable()` added up per expense, and GST is always left out. Net profit is income less everything spent; taxable profit is income less what can be claimed, and the tax estimate is the `tax_estimate_rate` setting of that.
- Which payments fall in a financial year, and how much of each is GST, is `App\Reports\PaymentsReceived::in($year)`. Every report that counts money received reads it (the profit and loss, `App\Reports\SalesReport`), so they cannot disagree; do not query payments by date for a report any other way.
- The BAS summary (`/reports/bas`, `App\Reports\BasSummary::for($quarter)`) is GST on invoices by issue day less GST in expenses by purchase day, whenever either was paid, and exists only while GST is on: its route answers 404 and its menu entry carries `'setting' => 'gst_enabled'`.
- In a view that has a `@php … @endphp` block, do not also use the inline `@php(…)`: Blade takes it as the start of a block.
- The dashboard's figures are `App\Dashboard\Dashboard::now()`, each taken from where its own screen gets it (`ReviewQueue`, `PaymentsReceived`, `RecurringSearch`, the invoice and task scopes). A new panel is added there, not worked out in the view.
- A site going down or coming back is told once to every login by `App\Listeners\SendSiteAlert` (a `Notice` and one `App\Mail\SiteAlertMail`), never to a client, and not at all for a domain with `alerts` off. How long it was down is counted from the first failed check (`DomainHistory::outageBegan()`).
- Watched sites are listed and edited at `/domains` (`DomainController`, `App\Domains\DomainSearch`). A site's page reads its past from its pings with `App\Domains\DomainHistory` (response times, outages as runs of two or more failed checks, versions seen). Check now posts to `domains.check`. A site that has been checked is made inactive, not deleted.
- A chart is a `<div data-chart="…" data-categories="…" data-series="…">` drawn by `resources/js/charts.js` (columns in dollars; or `data-chart-type="line" data-unit="ms"` for a line over time), with its figures in cents and its colours from `--series-1` and `--series-2` in `app.scss` (stepped for the dark skin too). Every figure in a chart is also in a table on the same page.
- A financial year is `App\Support\FinancialYear`, named by the year it ends in (2027 is 1 July 2026 to 30 June 2027). `FinancialYear::current()` is the reader's; `of($day)` takes a day as given, so turn a stored moment into the reader's day first. Its quarters are `App\Support\FinancialQuarter` (July to September is the first), and `FinancialYear::withData()` lists the years a report can be asked for.
- Scheduled jobs are listed in `App\Schedule\ScheduledJobs::all()` and nowhere else: `routes/console.php` registers that list, and the Scheduled jobs page (`/jobs`) shows it. Each runs by the clock in Melbourne, takes a lock, and has every run recorded in `job_runs` (`App\Models\JobRun`); a failed run tells every login. They run only in the worker container.
- There are no roles or permissions. Every login sees and does everything, so no screen checks an ability for who the user is.
- Until the app is live, a schema change edits the table's own migration rather than adding a second one; rebuild with `composer fresh`.
- Run Pint before committing.
