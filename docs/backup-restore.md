# Backup and restore

Everything tkcrm keeps is in the `data/` directory beside `docker-compose-live.yml`:

| Path | What |
|---|---|
| `data/tkcrm.sqlite` | the database, with `tkcrm.sqlite-wal` and `tkcrm.sqlite-shm` beside it while the site runs |
| `data/private/` | every attached file (receipts, task files) and each login's photo |
| `data/private/.app-key` | the key that signs and encrypts sessions |
| `data/logs/` | daily logs, kept 30 days |
| `data/caddy/` | the site's certificates, when it serves HTTPS itself |

Back up the whole directory, not the database file alone: the attachments are files, and the
database only names them. Keep the `.env` with it, for the mail password and the other settings.

## Backup

Stop the site, archive `data/`, and start it again. Stopping first is what makes the copy whole:
the database is in WAL mode, so a copy of `tkcrm.sqlite` taken while the site runs can miss the
newest writes.

```sh
cd /srv/tkcrm
docker compose -f docker-compose-live.yml stop
mkdir -p backups
tar -czf backups/tkcrm-$(date +%F).tgz data .env
docker compose -f docker-compose-live.yml start
```

The site is down for a few seconds. Email that was queued waits in the database and is sent when
the worker is back, and a site check that was missed is made 5 minutes later.

A nightly host crontab entry (in a crontab, `%` is written `\%`):

```cron
15 2 * * *  cd /srv/tkcrm && docker compose -f docker-compose-live.yml stop && tar -czf backups/tkcrm-$(date +\%F).tgz data .env; docker compose -f docker-compose-live.yml start
```

Keep it clear of 3:25 am to 3:35 am and of 7:00 am to 7:10 am, Melbourne time, when the nightly
jobs and the billing run.

Copy the archives off the machine. They are the business's billing record and hold every client's
details: keep them somewhere private, and keep one copy somewhere else.

## Restore

On a fresh machine, or to replace everything:

```sh
cd /srv/tkcrm
docker compose -f docker-compose-live.yml down
mv data data.old                      # keep until the restore checks out
tar -xzf backups/tkcrm-2026-10-11.tgz
docker compose -f docker-compose-live.yml up -d
```

To look at a backup without it billing or emailing anyone, put `TKCRM_WORKER=0` in `.env` before
the last line.

The site migrates the restored database on start, so an older backup works with a newer image. A
backup made by a newer image than the one running does not: run the newer image.

Then check it:

- `curl http://localhost:8080/up` answers `{"version":"…","db":"ok"}`.
- Sign in. If nobody can, the `.app-key` is not the one the backup was made with; signing in again
  is all that is lost.
- Compare the dashboard's outstanding total and the last invoice number with what you expect.
- Open an expense with a receipt and open the receipt: that proves `data/private/` came back.

## Before an upgrade

Take a backup first. A migration changes the database in place, and the only way back to the older
image is the backup made before it.
