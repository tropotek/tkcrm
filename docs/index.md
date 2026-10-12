# tkcrm

tkcrm keeps the books of one owner-operator: clients, tasks and time, invoices and payments,
recurring billing, expenses, a profit and loss report for each financial year, and monitoring of
client sites. It is built on Laravel and ships as one Docker image (FrankenPHP and SQLite), with
everything it stores in a single `data/` directory. AUD is the only currency.

## What it does

- **Time becomes an invoice line.** Time is logged against a task at the rate of the day.
  Closing the task adds one line for it to the client's open invoice.
- **Invoices that keep their figures.** An issued invoice stores its totals and its GST rate, and
  nothing works them out again. GST is one rate on a whole invoice, or off altogether.
- **Recurring billing that only drafts.** Hosting, domains and care plans are added to the
  client's open invoice when they fall due. Nothing is issued or emailed without your click: it
  waits in a review queue, with the overdue reminders that are due.
- **Statements, receipts and reminders.** Each is a PDF and an email whose wording you set, and
  every message is in a mail log.
- **Expenses and the profit and loss.** Expenses by category with the share that can be claimed,
  a profit and loss and a sales report for each financial year, and a BAS summary while GST is on.
- **Site monitoring.** Client sites are checked every 5 minutes, and you are told once when one
  goes down and once when it comes back.
- **Read-only API and MCP server.** A program, or an assistant such as Claude, can read what the
  screens show with a token you can revoke.
- **Two containers of one image.** The site and its worker. Migrations run on start, and a backup
  is one directory.

## Documentation

| Guide | For |
|---|---|
| [Installation](installation.md) | Running tkcrm with Docker Compose, HTTPS three ways, upgrading |
| [Configuration](configuration.md) | Environment variables, mail, and the settings in the site |
| [Backup and restore](backup-restore.md) | Backing up the `data/` directory and restoring it |
| [API and Claude (MCP)](api.md) | Tokens, the JSON endpoints and connecting an assistant |
| [Import from tktask](import-from-tktask.md) | Bringing a tktask site over and checking the figures |
| [Development](development.md) | Working on tkcrm, running tests, releasing |
