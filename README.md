# tkcrm

[![Docs](https://img.shields.io/badge/docs-tropotek.github.io%2Ftkcrm-1f6feb)](https://tropotek.github.io/tkcrm/)

Clients, tasks and time, invoices and payments, recurring billing, expenses, a profit and loss
report for each financial year, and monitoring of client sites, for one owner-operator. Built on
Laravel, shipped as one Docker image (FrankenPHP and SQLite) with everything it stores in a single
`data/` directory. AUD is the only currency.

Nothing is issued or emailed to a client without your click: recurring items and overdue reminders
are drafted and wait in a review queue.

## Quick start

Copy [`docker-compose-example.yml`](docker-compose-example.yml) into an empty directory as `docker-compose-live.yml`, then:

```sh
docker compose -f docker-compose-live.yml up -d
docker compose -f docker-compose-live.yml exec app artisan tkcrm:create-user you@example.com
```

Open <http://localhost:8080>.

## Documentation

Full docs at **<https://tropotek.github.io/tkcrm/>**:

| Guide | For |
|---|---|
| [Installation](docs/installation.md) | Running tkcrm with Docker Compose, HTTPS three ways, upgrading |
| [Configuration](docs/configuration.md) | Environment variables, mail, and the settings in the site |
| [Backup and restore](docs/backup-restore.md) | Backing up the `data/` directory and restoring it |
| [API and Claude (MCP)](docs/api.md) | Tokens, the JSON endpoints and connecting an assistant |
| [Import from tktask](docs/import-from-tktask.md) | Bringing a tktask site over and checking the figures |
| [Development](docs/development.md) | Working on tkcrm, running tests, releasing |
