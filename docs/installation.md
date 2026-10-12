# Installation

Requires Docker with the Compose plugin. Copy
[`docker-compose-live.yml`](https://github.com/tropotek/tkcrm/blob/main/docker-compose-live.yml)
into a directory of its own and start it:

```bash
mkdir tkcrm && cd tkcrm
curl -fsSLO https://raw.githubusercontent.com/tropotek/tkcrm/main/docker-compose-live.yml
docker compose -f docker-compose-live.yml up -d
docker compose -f docker-compose-live.yml exec app artisan tkcrm:create-user you@example.com
```

`tkcrm:create-user` asks for a password (or takes `--name`, `--password` and `--timezone`). Then
sign in at http://localhost:8080. There is no registration, and every login sees and does
everything.

It starts two containers of the one image:

- `app`, the site.
- `worker`, which sends the queued email and runs the schedule: recurring billing at 7:00 am, the
  review digest at 7:10 am, a check of every watched site each 5 minutes, and pruning at night.
  Times are Melbourne's. The **Scheduled jobs** page lists them with their last runs.

On its first start the site makes the database, runs the migrations and generates its key. The
worker waits for the site to be healthy. Everything the site keeps is in `data/` beside the
compose file; see [Backup and restore](backup-restore.md).

Settings go in a `.env` file beside the compose file: see [Configuration](configuration.md). Set
the mail settings before sending anything: without them no email leaves the site.

To bring a tktask site over instead of starting empty, see [Import from tktask](import-from-tktask.md).

## After the first sign-in

Under **Settings**:

1. Add your own business as a company and choose it as the owner. Its name, ABN, address and email
   are what every invoice and email is from.
2. Set the next invoice number, the days until an invoice is overdue and the payment instructions
   printed on an invoice.
3. Turn GST on if the business is registered for it. With it off, an invoice says that none has
   been charged.

Each login sets its own timezone on **Profile**, which decides what "today" is and which
financial year a payment falls in.

## The site alone, without the worker

```bash
TKCRM_WORKER=0
```

in `.env` starts the site with no worker, so nothing is billed, emailed or checked. This is for an
import, or for looking at a restored backup. Email queued in the meantime waits and is sent when the
worker starts: remove the line and run `up -d` again.

## HTTPS

There are three ways to serve the site.

**Plain HTTP.** Port 8080 as it comes, which is enough on a private network. Nothing to set.

For the other two, set `APP_URL` to the `https://` address and `SESSION_SECURE_COOKIE=true`.

**A reverse proxy in front** (Caddy, nginx) that ends TLS and sends to `TKCRM_PORT`. Tell tkcrm to
believe it, or its links and redirects come out as `http://`:

```bash
TRUSTED_PROXIES=192.168.0.20        # the proxy's address, a comma list, or * for any
```

A Caddy site for it is two lines:

```
tkcrm.example.com {
    reverse_proxy tkcrm-host:8080
}
```

**From the container itself.** Uncomment the two lines marked `https` in
`docker-compose-live.yml`, which publish ports 443 and 80, and give the site its name:

```bash
SERVER_NAME=tkcrm.example.com       # a host name turns HTTPS on; ":80", or none, is plain HTTP only
```

Caddy, which is in the image, then gets a certificate for that name and renews it, with nothing
else to set. The name has to be public and ports 80 and 443 have to reach the container, since
that is how the certificate authority checks it. Port 80 otherwise only redirects to HTTPS. The
certificates are kept in `data/caddy/`, so an upgrade does not ask for them again.

Where that cannot work, say which certificate to use instead:

```bash
TKCRM_TLS=internal                  # signed by Caddy's own authority, for a name that is not public
```

```bash
TKCRM_TLS_CERT=/certs/fullchain.pem     # or one of your own: put the files in certs/ beside the
TKCRM_TLS_KEY=/certs/privkey.pem        # compose file, readable by uid 1000, and uncomment its mount
```

A browser trusts an `internal` certificate only once Caddy's root,
`data/caddy/pki/authorities/local/root.crt`, is installed on it. A certificate of your own is read
when the container starts, so restart it after a renewal.

## Upgrade

Take a [backup](backup-restore.md) first: a migration changes the database in place.

```bash
docker compose -f docker-compose-live.yml pull
docker compose -f docker-compose-live.yml up -d
```

The compose file pulls the image on every start, so stopping and starting the site also upgrades
it. Versions are listed on the [package page](https://github.com/tropotek/tkcrm/pkgs/container/tkcrm).
Set `TKCRM_TAG` to one, such as `1.0.0`, to stay on it.

The site runs any new migrations as it starts, and the worker waits for it.
`curl http://localhost:8080/up` answers with the version once it is up.

## Running a command

```bash
docker compose -f docker-compose-live.yml exec app artisan <command>
```

`artisan` here always runs as the site's own user, so it never leaves a file in `data/` the site
cannot write.

| Command | What |
|---|---|
| `tkcrm:create-user you@example.com` | make a login |
| `tkcrm:bill-recurring` | add a line for each due recurring item to its client's open invoice, now. Sends nothing |
| `tkcrm:send-review-digest` | email every login what is waiting for review, if anything is |
| `tkcrm:check-sites` | check every watched site now |
| `tkcrm:import-tktask`, `tkcrm:verify-import` | see [Import from tktask](import-from-tktask.md) |
