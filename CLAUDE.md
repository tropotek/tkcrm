# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project overview

tkcrm is a Laravel 13 app for one owner-operator: clients, tasks and time logs, invoices and payments, recurring billing, expenses, a profit and loss report per financial year, and client site monitoring. It is a rewrite of tktask (`~/Projects/Dev/tktask`). AUD is the only base currency.

The design and the task list live in `_notes/laravel-rewrite/` (gitignored). `README.md` there is the proposal and the task index; each task has its own file. Read the task file before starting a task, and tick it off there when it is done.

The live tktask at `https://task.ttek.org/` sends real invoices. Nothing here connects to it or changes it before the cut-over runbook (task A4).

## Stack

- PHP 8.4, Laravel 13, Octane on FrankenPHP
- SQLite in WAL mode
- Vite, Bootstrap 5 with the Minton template, htmx and Alpine (tasks 02 and 03)
- PHPUnit for tests (not Pest), Pint for code style

## Commands

There is no PHP with SQLite or Composer on the host. Everything runs in the dev containers (`docker-compose.yml`):

```bash
docker compose up -d                              # app, vite, mailpit
docker compose --profile worker up -d             # the queue and scheduler too: only when working on mail, billing or pings
docker compose exec app composer install
docker compose exec app php artisan migrate
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

`composer test` clears the config cache and runs the suite. Tests run against an in-memory SQLite database.

## Layout

- `app/`: application code, `App\` namespace.
- `data/`: runtime data, gitignored. The SQLite database is `data/tkcrm.sqlite` (WAL mode). In production this directory is the mounted volume.
- `database/migrations/`: schema.
- `Dockerfile`, `docker-compose.yml`, `docker/`: the dev image and containers. `docker/Caddyfile` is Octane's FrankenPHP site config.
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
- Run Pint before committing.
