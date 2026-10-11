<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Facades\Settings;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Statements\Statement;
use App\Statements\StatementEntry;
use App\Statements\StatementPdf;
use App\Statements\UnpaidInvoice;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class StatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 20 October in Melbourne.
        $this->travelTo('2026-10-19 23:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));

        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
    }

    private function issued(int $number, int $total, string $issuedOn, string $dueOn, ?Company $company = null): Invoice
    {
        $invoice = Invoice::factory()->for($company ?? $this->client)->issued($total)->make();
        $invoice->forceFill(['number' => $number, 'issued_on' => $issuedOn, 'due_on' => $dueOn])->save();

        return $invoice;
    }

    /**
     * @param  string  $receivedAt  UTC
     */
    private function pay(Invoice $invoice, int $cents, string $receivedAt, PaymentMethod $method = PaymentMethod::Eft): void
    {
        $invoice->recordPayment(Money::cents($cents), $method, new \DateTimeImmutable($receivedAt));
    }

    /**
     * Each entry as date, description, charge, payment and balance, in cents.
     *
     * @return list<array{0: string, 1: string, 2: ?int, 3: ?int, 4: int}>
     */
    private function rows(Statement $statement): array
    {
        return array_map(fn (StatementEntry $entry) => [
            $entry->date->toDateString(),
            $entry->description,
            $entry->charge?->inCents(),
            $entry->payment?->inCents(),
            $entry->balance->inCents(),
        ], $statement->entries);
    }

    /**
     * Each unpaid invoice as number, owing in cents and days overdue.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function unpaid(Statement $statement): array
    {
        return array_map(fn (UnpaidInvoice $unpaid) => [$unpaid->invoice->number, $unpaid->owing->inCents(), $unpaid->daysOverdue], $statement->unpaid);
    }

    public function test_invoices_and_payments_are_listed_in_date_order_with_a_running_balance(): void
    {
        $first = $this->issued(1001, 50000, '2026-07-10', '2026-07-24');
        $second = $this->issued(1002, 12345, '2026-08-03', '2026-08-17');
        $this->issued(1003, 20000, '2026-09-01', '2026-09-15');

        $this->pay($first, 50000, '2026-07-20 02:00:00');
        $this->pay($second, 5000, '2026-09-10 02:00:00', PaymentMethod::Cash);

        $statement = Statement::for($this->client, '2026-07-01', '2026-09-30');

        $this->assertSame(0, $statement->opening->inCents());
        $this->assertSame([
            ['2026-07-10', 'Invoice 1001', 50000, null, 50000],
            ['2026-07-20', 'Payment for invoice 1001, EFT', null, 50000, 0],
            ['2026-08-03', 'Invoice 1002', 12345, null, 12345],
            ['2026-09-01', 'Invoice 1003', 20000, null, 32345],
            ['2026-09-10', 'Payment for invoice 1002, Cash', null, 5000, 27345],
        ], $this->rows($statement));
        $this->assertSame(82345, $statement->charges()->inCents());
        $this->assertSame(55000, $statement->payments()->inCents());
        $this->assertSame(27345, $statement->closing->inCents());
    }

    public function test_a_part_payment_before_the_range_is_in_the_opening_balance_and_the_rest_is_listed(): void
    {
        $invoice = $this->issued(1001, 100000, '2026-06-10', '2026-06-24');
        $this->pay($invoice, 40000, '2026-06-25 02:00:00');
        $this->pay($invoice, 35000, '2026-07-15 02:00:00');
        $this->pay($invoice, 25000, '2026-08-05 02:00:00');

        // An older one paid off before the range adds nothing to it.
        $old = $this->issued(1000, 7700, '2026-05-01', '2026-05-15');
        $this->pay($old, 7700, '2026-05-10 02:00:00');

        $statement = Statement::for($this->client, '2026-07-01', '2026-07-31');

        $this->assertSame(60000, $statement->opening->inCents());
        $this->assertSame([
            ['2026-07-15', 'Payment for invoice 1001, EFT', null, 35000, 25000],
        ], $this->rows($statement));
        $this->assertSame(25000, $statement->closing->inCents());

        // The next month opens where this one closed.
        $next = Statement::for($this->client, '2026-08-01', '2026-08-31');

        $this->assertSame(25000, $next->opening->inCents());
        $this->assertSame(0, $next->closing->inCents());
        $this->assertSame([], $next->unpaid);
    }

    public function test_the_first_and_last_days_of_the_range_are_in_it(): void
    {
        $this->issued(1001, 1000, '2026-06-30', '2026-07-14');
        $this->issued(1002, 2000, '2026-07-01', '2026-07-15');
        $this->issued(1003, 4000, '2026-07-31', '2026-08-14');
        $this->issued(1004, 8000, '2026-08-01', '2026-08-15');

        $statement = Statement::for($this->client, '2026-07-01', '2026-07-31');

        $this->assertSame(1000, $statement->opening->inCents());
        $this->assertSame(['Invoice 1002', 'Invoice 1003'], array_column($this->rows($statement), 1));
        $this->assertSame(7000, $statement->closing->inCents());
    }

    public function test_a_payment_is_dated_the_day_it_was_received_where_the_reader_is(): void
    {
        $invoice = $this->issued(1001, 30000, '2026-06-10', '2026-06-24');

        // Half past eight in the morning on 1 July in Melbourne, still 30 June in UTC.
        $this->pay($invoice, 10000, '2026-06-30 22:30:00');

        $statement = Statement::for($this->client, '2026-07-01', '2026-07-31');

        $this->assertSame(30000, $statement->opening->inCents());
        $this->assertSame([['2026-07-01', 'Payment for invoice 1001, EFT', null, 10000, 20000]], $this->rows($statement));
    }

    public function test_on_one_day_an_invoice_comes_before_a_payment(): void
    {
        $later = $this->issued(1002, 5000, '2026-07-10', '2026-07-24');
        $earlier = $this->issued(1001, 3000, '2026-07-10', '2026-07-24');

        $this->pay($later, 5000, '2026-07-10 01:00:00');
        $this->pay($earlier, 1000, '2026-07-10 05:00:00');

        $this->assertSame([
            ['2026-07-10', 'Invoice 1001', 3000, null, 3000],
            ['2026-07-10', 'Invoice 1002', 5000, null, 8000],
            ['2026-07-10', 'Payment for invoice 1002, EFT', null, 5000, 3000],
            ['2026-07-10', 'Payment for invoice 1001, EFT', null, 1000, 2000],
        ], $this->rows(Statement::for($this->client, '2026-07-01', '2026-07-31')));
    }

    public function test_cancelled_and_open_invoices_and_other_clients_are_left_out(): void
    {
        $this->issued(1001, 10000, '2026-07-10', '2026-07-24');
        $this->issued(1002, 20000, '2026-07-11', '2026-07-25')->cancel();
        $this->issued(1003, 40000, '2026-06-01', '2026-06-15')->cancel();
        Invoice::factory()->for($this->client)->create();

        // Issued, then re-opened to be corrected: it keeps its number but is not owed yet.
        $reopened = $this->issued(1004, 80000, '2026-07-12', '2026-07-26');
        $reopened->reopen();

        $other = Company::factory()->create();
        $this->pay($this->issued(1005, 160000, '2026-07-13', '2026-07-27', $other), 5000, '2026-07-14 02:00:00');

        $statement = Statement::for($this->client, '2026-07-01', '2026-07-31');

        $this->assertSame(0, $statement->opening->inCents());
        $this->assertSame([['2026-07-10', 'Invoice 1001', 10000, null, 10000]], $this->rows($statement));
        $this->assertSame([[1001, 10000, 7]], $this->unpaid($statement));
    }

    public function test_unpaid_invoices_are_listed_with_what_is_owing_and_the_days_overdue(): void
    {
        $this->pay($this->issued(1001, 50000, '2026-08-01', '2026-08-15'), 20000, '2026-09-01 02:00:00');
        $this->issued(1002, 10000, '2026-10-06', '2026-10-20');
        $this->issued(1003, 7000, '2026-10-05', '2026-10-19');
        $this->pay($this->issued(1004, 9000, '2026-09-01', '2026-09-15'), 9000, '2026-09-20 02:00:00');

        // Today is 20 October: the range runs past it, so the age is as at today.
        $statement = Statement::for($this->client, '2026-07-01', '2027-06-30');

        $this->assertSame('2026-10-20', $statement->asAt->toDateString());
        $this->assertSame([
            [1001, 30000, 66],
            // Due yesterday: one day overdue.
            [1003, 7000, 1],
            // Due today: not overdue.
            [1002, 10000, 0],
        ], $this->unpaid($statement));
        $this->assertSame(47000, $statement->closing->inCents());
    }

    public function test_a_past_statement_lists_what_was_unpaid_at_its_end(): void
    {
        $paidSince = $this->issued(1001, 50000, '2026-08-01', '2026-08-15');
        $this->pay($paidSince, 20000, '2026-08-20 02:00:00');
        $this->pay($paidSince, 30000, '2026-09-25 02:00:00');
        $this->issued(1002, 10000, '2026-09-20', '2026-10-04');

        $statement = Statement::for($this->client, '2026-08-01', '2026-08-31');

        $this->assertSame('2026-08-31', $statement->asAt->toDateString());
        $this->assertSame([[1001, 30000, 16]], $this->unpaid($statement));

        // What is unpaid always adds up to the closing balance.
        $this->assertSame($statement->closing->inCents(), array_sum(array_column($this->unpaid($statement), 1)));
    }

    public function test_a_client_with_nothing_has_an_empty_statement(): void
    {
        $statement = Statement::for($this->client, '2026-07-01', '2026-07-31');

        $this->assertTrue($statement->opening->isZero());
        $this->assertTrue($statement->closing->isZero());
        $this->assertSame([], $statement->entries);
        $this->assertSame([], $statement->unpaid);
    }

    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Statement::for($this->client, '2026-08-01', '2026-07-31');
    }

    public function test_the_document_prints_the_statement(): void
    {
        $owner = Company::factory()->create(['name' => 'Acme Books', 'abn' => '11 111 111 111']);
        Settings::set('owner_company_id', $owner->id);
        Settings::set('payment_instructions', "Pay by transfer.\nBSB 000-000");

        $invoice = $this->issued(1001, 100000, '2026-06-10', '2026-06-24');
        $this->pay($invoice, 40000, '2026-06-25 02:00:00');
        $this->pay($invoice, 35000, '2026-07-15 02:00:00');
        $this->issued(1002, 12345, '2026-07-20', '2026-08-03');

        $document = app(StatementPdf::class);
        $statement = Statement::for($this->client, '2026-07-01', '2026-07-31');
        $html = $document->html($statement);

        $this->assertSame('Statement 1 Jul 2026 to 31 Jul 2026', $document->title($statement));
        $this->assertSame("statement-{$this->client->account_number}-2026-07-01-to-2026-07-31.pdf", $document->filename($statement));

        foreach ([
            'Acme Books', 'ABN 11 111 111 111', 'Wattle Creek Bakery', $this->client->account_number,
            'Opening balance', '$600.00',
            '15 Jul 2026', 'Payment for invoice 1001, EFT', '$350.00', '$250.00',
            '20 Jul 2026', 'Invoice 1002', '$123.45',
            'Closing balance', '$373.45',
            'Unpaid at 31 Jul 2026', '37 days overdue', 'Not yet due',
            "Pay by transfer.<br />\nBSB 000-000",
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // It stands alone: no script, nothing fetched.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<link', $html);
        $this->assertStringNotContainsString('src=', $html);

        $this->assertStringStartsWith('%PDF-', $document->pdf($statement));
    }

    public function test_a_statement_with_nothing_owing_says_so_and_asks_for_no_payment(): void
    {
        Settings::set('payment_instructions', 'Pay by transfer.');

        $this->pay($this->issued(1001, 10000, '2026-07-10', '2026-07-24'), 10000, '2026-07-20 02:00:00');

        $html = app(StatementPdf::class)->html(Statement::for($this->client, '2026-07-01', '2026-07-31'));

        $this->assertStringContainsString('Nothing is owing', $html);
        $this->assertStringNotContainsString('Pay by transfer.', $html);
        $this->assertStringNotContainsString('Unpaid at', $html);
    }
}
