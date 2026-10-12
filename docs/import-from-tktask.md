# Import from tktask

`tkcrm:import-tktask` brings a tktask site over: logins, companies, products, projects, tasks and
time logs, invoices with their lines and payments, recurring items, expenses, watched sites,
attached files and settings. `tkcrm:verify-import` then proves the figures match.

The import reads a **copy** of tktask's MariaDB database and of its `data/` directory. It never
writes to either. Do not point it at the live tktask server.

## Before

- Load a dump of the tktask database into a MariaDB server the tkcrm container can reach, and put
  its address in `.env`:

  ```bash
  TKTASK_DB_HOST=…
  TKTASK_DB_PORT=3306
  TKTASK_DB_DATABASE=…
  TKTASK_DB_USERNAME=…
  TKTASK_DB_PASSWORD=…
  ```

- Copy tktask's `data/` directory to `data/tktask-data/` beside the compose file, for the attached
  files.
- Run the site alone, so nothing is billed or emailed part way: `TKCRM_WORKER=0` in `.env`, then
  `up -d`.

## Import

```bash
docker compose -f docker-compose-live.yml exec app artisan tkcrm:import-tktask --dry-run
docker compose -f docker-compose-live.yml exec app artisan tkcrm:import-tktask --data=/app/data/tktask-data
```

`--dry-run` counts what would come over and changes nothing. The real run **replaces** everything
the import fills: it is for a new site. It refuses when the site holds records made or changed in
tkcrm since the last import, unless given `--force`, which deletes them. A run that fails part way
leaves the database as it was.

Read what it prints. A note is something done on purpose: every record keeps its tktask id, an
issued invoice keeps its tktask number and exactly the totals tktask showed, and an unpaid invoice
is treated as reminded on the day of the import, so the first reminder is not drafted that day. A
warning is a record that could not be brought over as it stood, with what was done instead.

What is not imported: client credit (tkcrm has none), projects' documents, logins that are not
active staff, and site checks older than 90 days.

Passwords come over as they are: each login signs in with its tktask password.

## Check

```bash
docker compose -f docker-compose-live.yml exec app artisan tkcrm:verify-import
```

It compares tkcrm with tktask and changes neither:

- every table's rows, by id;
- every invoice with tktask's own view of it: sub-total, discount, tax, total, paid and unpaid;
- every issued invoice's stored figures with what tkcrm's own calculator makes of its lines;
- what each client owes;
- the profit and loss of every financial year.

It ends with "Everything agrees with tktask." or lists each difference and exits with an error. Do
not go on with a difference that is not explained. A grey line is a note, not a difference: a
tktask row that points at something tktask itself has not got, for instance.

## After

Sign in and compare the dashboard and the outstanding list with tktask's. Under **Settings**,
check the owner company, the next invoice number and the email wording. Then remove
`TKCRM_WORKER=0` and run `up -d` to start the worker, and delete `data/tktask-data/`.
