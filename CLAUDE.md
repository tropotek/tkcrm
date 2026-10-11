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
- `Dockerfile`, `docker-compose.yml`, `docker/`: the dev image and containers. `docker/Caddyfile` is Octane's FrankenPHP site config.
- `resources/scss/`: `app.scss` and `icons.scss` are the entries. `config/default` and `custom/` are lifted from Minton (`~/Projects/Dev/Minton/Minton/resources/scss`).
- `resources/js/`: `app.js` is the entry. `minton/` holds the template's own scripts. `modal.js` drives the shared dialog.
- `resources/views/components/layouts/`: the page layouts. `<x-layouts.app title="…">` is the signed-in shell (top bar, sidebar, page title, flash messages, footer, shared dialog); `<x-layouts.guest>` is the card for the login pages.
- `/up` (`HealthController`, `App\Support\HealthStatus`): the health check the containers poll, and the reply tkcrm's own site monitor reads from a Laravel site. 200 with `version` and `db: ok` when the database accepts a write, 503 with `db: error` otherwise.
- `config/tkcrm.php`: tkcrm's own settings that come from the environment.
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
- A user's photo is on the private `local` disk under `photos/` and is sent by `ProfileController::photo` to that user only. Use `$user->photoUrl()` for its address.
- Settings the owner can change are read with `App\Facades\Settings::get('key')`. Every key, its default, type and validation rules are in `App\Support\Settings::definitions()`; a new setting is added there and to `settings/edit.blade.php`. An unknown key throws. The table is read once per request.
- A rate setting (GST, the tax estimate) is read in basis points; `Settings::percentage()` and `setPercentage()` convert for the settings form.
- The site name comes from the `site_name` setting, copied into `config('app.name')` for each web request by `ApplySettings`. Code outside a web request (a queued job, a command) reads `Settings::get('site_name')`.
- A company (`App\Models\Company`) is a client or a supplier (`App\Enums\CompanyType`). `$company->account_number` is `CO-` and the id padded to ten digits, so an imported company must keep its tktask id. There is no client credit.
- The seeder's companies, and every factory, use made-up names and `example.com` addresses. Keep it that way.
- There are no roles or permissions. Every login sees and does everything, so no screen checks an ability for who the user is.
- Until the app is live, a schema change edits the table's own migration rather than adding a second one; rebuild with `composer fresh`.
- Run Pint before committing.
