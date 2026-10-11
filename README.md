# tkcrm

Clients, tasks and time, invoices, recurring billing, expenses, a profit and loss report per financial year, and client site monitoring, for one owner-operator.

Built on Laravel 13 and SQLite. A rewrite of tktask.

Status: in development.

## Development

Requires Docker. PHP 8.4, Composer and Node are in the dev image.

```bash
cp .env.example .env
docker compose up -d                                  # builds the image, installs PHP and Node packages
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app composer test                 # run the test suite
```

- App: http://localhost:8780
- Vite dev server: http://localhost:5780
- Mailpit (all outgoing mail): http://localhost:8781

The ports are set in `.env`.

The queue and the scheduler run in a `worker` container that is off by default, so a dev site never bills, mails or pings on a timer. Start it with `docker compose --profile worker up -d`.
