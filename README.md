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

## API and MCP

Both are read-only: they can read what you see on the screens and cannot change a record, issue an invoice or send an email. Both take a personal access token, made on the profile page under API tokens. The token is shown once, when it is made.

**API.** JSON at `/api/v1`, GET only. `GET /api/v1` lists the endpoints: clients, invoices, outstanding, tasks, time-logs, recurring, expenses, profit-loss and sites. Each takes the same filters as its screen, by the same names, and a list is paged with `page` and `per_page`. Amounts are decimal strings in AUD.

```bash
curl -H "Authorization: Bearer YOUR-TOKEN" -H "Accept: application/json" "http://tkcrm.tk/api/v1/outstanding"
```

**MCP.** A server at `/mcp` with one tool for each endpoint, for an assistant such as Claude. Register it with Claude Code, with a token in place of `YOUR-TOKEN` and your own address in place of `http://tkcrm.tk`:

```bash
claude mcp add --transport http tkcrm http://tkcrm.tk/mcp --header "Authorization: Bearer YOUR-TOKEN"
```

The profile page shows the same line with the site's own address filled in.
