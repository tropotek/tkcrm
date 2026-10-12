# Development

Requires Docker. PHP 8.4, Composer and Node are in the dev image.

```bash
cp .env.example .env
docker compose up -d                                  # builds the image, installs PHP and Node packages
docker compose exec app php artisan key:generate
docker compose exec app composer fresh                # migrate and add the development data
docker compose exec app composer test                 # run the test suite
docker compose exec app vendor/bin/pint               # code style
```

- App: http://localhost:8780, signed in as `owner@example.com` with the password `password`
- Vite dev server: http://localhost:5780
- Mailpit (all outgoing mail): http://localhost:8781

The ports are set in `.env`.

The queue and the scheduler run in a `worker` container that is off by default, so a dev site
never bills, mails or pings on a timer. Start it with `docker compose --profile worker up -d`.
Without it, queued email waits; `docker compose exec app php artisan queue:work --once` sends one.

`CLAUDE.md` holds the conventions the code follows.

## The shipped image

```bash
docker build -t tkcrm .
```

builds the image the live compose file runs. To run it from that file, set `TKCRM_IMAGE=tkcrm` and
`TKCRM_PULL=never` in the `.env` beside it.

## Release

`bin/release v1.2.3` checks the code style and runs the tests, tags the commit and offers to push
the tag. The tag starts the `release` workflow, which builds the image and publishes it to
`ghcr.io/tropotek/tkcrm` as `1.2.3` and `latest`. The `ci` workflow runs the code style check and
the tests on every push to `main` and every pull request.
