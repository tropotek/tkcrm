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
- `config/navigation.php`: the sidebar menu, read by `App\Support\Navigation`.
- `resources/fonts/`, `resources/images/`: icon fonts and images the SCSS refers to.
- `tests/Feature`, `tests/Unit`: PHPUnit tests.
- `_notes/`: local planning docs, gitignored.
- `version.md`: the app version.

## Conventions

- Money is never a float. Amounts are stored as integer cents, and rates and quantities as integers or decimal strings, calculated with BCMath (task 20 adds the money support).
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
- There are no roles or permissions. Every login sees and does everything, so no screen checks an ability for who the user is.
- Until the app is live, a schema change edits the table's own migration rather than adding a second one; rebuild with `composer fresh`.
- Run Pint before committing.
