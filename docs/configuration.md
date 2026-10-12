# Configuration

Settings go in a `.env` file beside `docker-compose-live.yml`. All are optional. After a change,
run `docker compose -f docker-compose-live.yml up -d` to recreate the containers.

| Setting | Default | What |
|---|---|---|
| `TKCRM_TAG` | `latest` | the version of the image to run, such as `1.0.0` |
| `TKCRM_PORT` | `8080` | the host port the site is published on |
| `TKCRM_WORKER` | `1` | `0` runs the site alone: nothing is billed, emailed or checked |
| `TKCRM_IMAGE`, `TKCRM_PULL` | `ghcr.io/tropotek/tkcrm`, `always` | an image built locally: its name, and `never` |
| `APP_URL` | `http://localhost:8080` | the address people reach it at; every link in an email starts with it |
| `TRUSTED_PROXIES` | none | the reverse proxy in front whose forwarded headers are believed: its address, a comma list, or `*` |
| `SESSION_SECURE_COOKIE` | off | `true` when the site is served over HTTPS |
| `SERVER_NAME` | `:80` | a host name turns on HTTPS from the container itself; `:80`, or none, is plain HTTP only |
| `TKCRM_TLS` | none | `internal` for a certificate signed by Caddy's own authority, when the name is not public |
| `TKCRM_TLS_CERT`, `TKCRM_TLS_KEY` | none | a certificate and key of your own, in place of the one Caddy gets |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | no email is sent | the SMTP server: see [Mail](#mail) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `hello@example.com`, `tkcrm` | who email is from until an owner company with an email is chosen |
| `MAIL_REDIRECT_TO` | none | one address that gets every email instead of whoever it was for |
| `APP_KEY` | generated into `data/private/.app-key` | the key that signs and encrypts sessions |
| `TKCRM_LOGIN_MAX_ATTEMPTS`, `TKCRM_LOGIN_LOCKOUT_MINUTES` | `5`, `15` | failed logins before an email is locked out from an address, and for how long |
| `TKCRM_NOTIFICATIONS_KEEP_READ_DAYS` | `30` | how long a read notification is kept |
| `TKCRM_API_PER_MINUTE` | `120` | requests a minute for one API token's user |
| `LOG_LEVEL`, `LOG_DAILY_DAYS` | `info`, `30` | what is logged to `data/logs/`, and for how many days |
| `TKTASK_DB_HOST`, `TKTASK_DB_PORT`, `TKTASK_DB_DATABASE`, `TKTASK_DB_USERNAME`, `TKTASK_DB_PASSWORD` | none | the tktask database the import reads |

For `TRUSTED_PROXIES`, `SERVER_NAME` and the certificate settings, see [HTTPS](installation.md#https).

## Mail

tkcrm sends through an SMTP server you give it:

```bash
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=accounts@example.com
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=accounts@example.com
```

With no `MAIL_MAILER`, no email leaves the site: a message is marked sent and goes nowhere. Set it
before the first invoice.

- **Who it is from.** The owner company's name and email, once one is chosen under **Settings**.
  That address has to be one the SMTP server will send for, and the domain's SPF and DKIM records
  have to cover the server, or clients' mail servers will mark invoices as spam.
- **Who it goes to.** A client's email, with a copy to its accounts email. Tick "Send the owner
  a blind copy of every email to a client" under **Settings** to get each one too.
- **What was sent.** The **Mail log** lists every message with its state: queued, sent or failed. A
  failed send is tried three more times, after one, five and fifteen minutes. Then every login
  is told, and it can be sent again from the log.
- **The worker sends it.** A message is queued by the site and sent by the worker container. With
  the worker stopped, messages wait.
- **A trial run.** `MAIL_REDIRECT_TO=you@example.com` sends every message to that one address,
  with who it was for in the subject. Use it when trying the site with real client data.

The wording of the invoice, receipt, statement and reminder emails is set under **Settings**.

## In the site

The rest is under **Settings**: the site name, the owner company, the next invoice number, the
days until an invoice is overdue, the payment instructions on an invoice, the default labour
product, GST and its rate, the rate of the tax estimate on the profit and loss, and the email
wording.
