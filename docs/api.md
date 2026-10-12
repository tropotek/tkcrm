# API and Claude (MCP)

tkcrm has a read-only JSON API and an MCP server over the same figures, so that a program, or an
assistant such as Claude, can read what you see on the screens. Neither can change a record, issue
an invoice or send an email.

## 1. Make a token

Sign in, open **Profile**, and under **API tokens** give a token a name (what it is for, such as
"Claude") and make it. Copy it from the box that appears: it is shown once. Revoke it from the same
list and it stops working at once. A token does not expire, and it is not a login to the screens.

## 2. Use the API

Every endpoint is a GET under `/api/v1` and takes the token as a bearer token:

```bash
curl -H "Authorization: Bearer YOUR-TOKEN" -H "Accept: application/json" https://tkcrm.example.com/api/v1/outstanding
```

| Endpoint | Returns |
|---|---|
| `/api/v1` | the list of endpoints |
| `/api/v1/clients` | companies, as the Companies screen lists them |
| `/api/v1/invoices` | invoices, with their status and totals |
| `/api/v1/outstanding` | what each client owes on its unpaid invoices |
| `/api/v1/tasks` | tasks |
| `/api/v1/time-logs` | time logs |
| `/api/v1/recurring` | recurring items, with what each comes to a year |
| `/api/v1/expenses` | expenses |
| `/api/v1/profit-loss/{year}` | a financial year's profit and loss. The year is the one it ends in: `2027` is 2026–27. Without one, the current year |
| `/api/v1/sites` | the watched sites and where each stands |

Each list takes the same filters as its screen, by the same names, and is paged with `page` and
`per_page`. Amounts are decimal strings in AUD, never JSON numbers. Days are `YYYY-MM-DD` and
moments are in UTC. A token's user may make 120 requests a minute.

## 3. Connect Claude

Register the MCP server with Claude Code, with the same token:

```bash
claude mcp add --transport http tkcrm https://tkcrm.example.com/mcp --header "Authorization: Bearer YOUR-TOKEN"
```

Use your own address in place of `tkcrm.example.com` (in development, `http://localhost:8780/mcp`).
The **Profile** page shows the same line with the site's own address filled in. Add `--scope user`
to have it in every project. `claude mcp list` shows whether it connected, and
`claude mcp remove tkcrm` takes it away.

For another MCP client, the server is streamable HTTP at `/mcp`, with the header
`Authorization: Bearer YOUR-TOKEN`.

Claude then has nine tools, one for each endpoint above: `clients`, `invoices`, `outstanding`,
`tasks`, `time_logs`, `recurring`, `expenses`, `profit_loss` and `sites`. Ask it something like
"who owes me money, and which of those invoices are overdue?".
