# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project overview

tkcrm is a Laravel 13 app for one owner-operator: clients, tasks and time logs, invoices and payments, recurring billing, expenses, a profit and loss report per financial year, and client site monitoring. It is a rewrite of tktask (`~/Projects/Dev/tktask`). AUD is the only base currency.

The design and the task list live in `_notes/laravel-rewrite/` (gitignored). `README.md` there is the proposal and the task index; each task has its own file. Read the task file before starting a task, and tick it off there when it is done.

The live tktask at `https://task.ttek.org/` sends real invoices. Nothing here connects to it or changes it before the cut-over runbook (task A4).

## Stack

- PHP 8.4, Laravel 13, Octane on FrankenPHP
- SQLite in WAL mode
- Vite, Bootstrap 5 with the Minton default skin (light and dark), ApexCharts for charts, htmx and Alpine
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

`composer test` clears the config cache and runs the suite. Tests run against an in-memory SQLite database.

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
- An invoice is changed only while `$invoice->isOpen()`: its details and lines in the shared dialog from its page (`InvoiceController`, `InvoiceLineController`), everything else answering 403. A line with a source (`$line->isFromSource()`, a task's time) is never edited or deleted on the invoice: re-opening the task takes it off.
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
- Scheduled commands are listed in `routes/console.php`, in UTC, and run only in the worker container.
- There are no roles or permissions. Every login sees and does everything, so no screen checks an ability for who the user is.
- Until the app is live, a schema change edits the table's own migration rather than adding a second one; rebuild with `composer fresh`.
- Run Pint before committing.
