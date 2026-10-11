# tkcrm

Clients, tasks and time, invoices, recurring billing, expenses, a profit and loss report per financial year, and client site monitoring, for one owner-operator.

Built on Laravel 13 and SQLite. A rewrite of tktask.

Status: in development.

## Development

Requires PHP 8.4 with `pdo_sqlite` and `bcmath`, Composer and Node.

```bash
composer setup    # install, create .env, generate key, create and migrate data/tkcrm.sqlite, build assets
composer test     # run the test suite
```
