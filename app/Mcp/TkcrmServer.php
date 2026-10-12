<?php

namespace App\Mcp;

use App\Mcp\Tools\ClientsTool;
use App\Mcp\Tools\ExpensesTool;
use App\Mcp\Tools\InvoicesTool;
use App\Mcp\Tools\OutstandingTool;
use App\Mcp\Tools\ProfitLossTool;
use App\Mcp\Tools\RecurringTool;
use App\Mcp\Tools\SitesTool;
use App\Mcp\Tools\TasksTool;
use App\Mcp\Tools\TimeLogsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The MCP server at /mcp: the read-only API as tools, one an endpoint, for an assistant such as
 * Claude to read the business's figures with. It is reached with the same personal access token
 * as the API.
 *
 * Every tool is read-only. Nothing here changes a record, issues an invoice or sends mail.
 */
#[Name('tkcrm')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
tkcrm is one owner-operator's business records: clients, tasks and time, invoices and payments, recurring billing, expenses, a profit and loss for each financial year, and the client sites that are watched. These tools read it; none can change a record, issue an invoice or send an email.

- Money is in Australian dollars. Amounts are decimal strings ("1234.50"), never numbers: do arithmetic on them as decimals.
- A day is written 2026-10-15. A moment is ISO 8601 in UTC, ending in Z.
- A financial year runs 1 July to 30 June and is named by the year it ends in: 2027 is 2026–27.
- An invoice is open until it is issued, then unpaid, paid or cancelled. Only an issued invoice has a number. Income is what was received, not what was invoiced.
- A list comes a page at a time: "meta" says which page, how many there are, and adds up the whole list where it says "totals". Ask for the next page rather than assuming one page is everything.
- A client is given as {id, name} inside other records; pass the id as "client" to narrow a list to it.
- The profit and loss and its tax estimate are working figures, not tax advice.
TEXT)]
class TkcrmServer extends Server
{
    protected array $tools = [
        ClientsTool::class,
        InvoicesTool::class,
        OutstandingTool::class,
        TasksTool::class,
        TimeLogsTool::class,
        RecurringTool::class,
        ExpensesTool::class,
        ProfitLossTool::class,
        SitesTool::class,
    ];
}
