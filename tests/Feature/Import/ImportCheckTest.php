<?php

namespace Tests\Feature\Import;

use App\Import\CheckResult;
use App\Import\ImportCheck;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportCheckTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    private const TIMES = ['created' => '2024-01-01 03:00:00', 'modified' => '2025-03-01 04:00:00'];

    /**
     * A small made-up tktask with something in every table the check reads: two clients and a
     * supplier, a paid invoice with a discount and tax, an unpaid one with shipping and a part
     * payment, a cancelled one, an open one, and expenses in two financial years.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October 2026 in Melbourne.
        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();

        $this->tktaskRows('user', [self::TIMES + ['user_id' => 1, 'type' => 'staff', 'given_name' => 'Olive', 'family_name' => 'Example']]);
        $this->tktaskRows('auth', [[
            'auth_id' => 101, 'fkey' => 'App\\Db\\User', 'fid' => 1, 'username' => 'olive', 'email' => 'olive@example.com',
            'password' => password_hash('correct horse', PASSWORD_DEFAULT), 'timezone' => 'Australia/Melbourne', 'active' => 1,
        ]]);
        $this->tktaskRows('registry', [['key' => 'site.account.overdue.days', 'value' => '14']]);

        foreach ([3 => ['Client', 'Wattle Creek Bakery'], 4 => ['Client', 'Ironbark Surveying'], 9 => ['Supplier', 'Bluegum Hosting']] as $id => [$type, $name]) {
            $this->tktaskRows('company', [self::TIMES + ['company_id' => $id, 'type' => $type, 'name' => $name, 'credit' => 0, 'active' => 1]]);
        }

        $this->tktaskRows('product_category', [self::TIMES + ['product_category_id' => 1, 'name' => 'Labour', 'description' => '', 'active' => 1]]);
        $this->tktaskRows('product', [self::TIMES + ['product_id' => 7, 'product_category_id' => 1, 'cycle' => 'each', 'name' => 'Labour', 'code' => 'LAB', 'price' => 9000, 'active' => 1]]);
        $this->tktaskRows('task_category', [self::TIMES + ['task_category_id' => 2, 'name' => 'Development', 'description' => '', 'order_by' => 1, 'active' => 1]]);
        $this->tktaskRows('task', [self::TIMES + [
            'task_id' => 41, 'company_id' => 3, 'project_id' => 0, 'task_category_id' => 2, 'creator_user_id' => 1, 'assigned_user_id' => 1, 'closed_user_id' => 0,
            'subject' => 'Tidy the menu page', 'comments' => '', 'priority' => 5, 'minutes' => 120, 'invoice_item_id' => 0,
        ]]);
        $this->tktaskRows('task_log', [self::TIMES + [
            'task_log_id' => 100, 'task_id' => 41, 'user_id' => 1, 'product_id' => 7, 'billable' => 1, 'start_at' => '2025-01-15 09:30:00', 'minutes' => 90, 'comment' => '',
        ]]);

        // Paid: $1,000.00 less 17% is $830.00, plus 10% is $913.00.
        $this->invoice(2041, 3, ['discount' => '0.170000002', 'tax' => '0.1', 'issued_on' => '2025-02-01', 'paid_on' => '2025-02-10']);
        $this->item(500, 2041, 100000);
        $this->payment(900, 2041, 91300, '2025-02-10 09:30:00');

        // Unpaid: 1.5 hours at $90.00 and $12.50 shipping is $147.50, with $47.50 paid.
        $this->invoice(2042, 4, ['shipping' => 1250, 'issued_on' => '2026-08-01']);
        $this->item(501, 2042, 9000, ['qty' => '1.5']);
        $this->payment(901, 2042, 4750, '2026-08-20 14:00:00');

        // Cancelled, and one still open.
        $this->invoice(2043, 3, ['issued_on' => '2026-08-05', 'cancelled_on' => '2026-08-06']);
        $this->item(502, 2043, 5000);
        $this->invoice(2044, 4, ['issued_on' => null]);
        $this->item(503, 2044, 7000);

        $this->tktaskRows('expense_category', [
            self::TIMES + ['expense_category_id' => 5, 'name' => 'Hosting', 'description' => '', 'claim' => 1, 'active' => 1],
            self::TIMES + ['expense_category_id' => 6, 'name' => 'Phone', 'description' => '', 'claim' => '0.5', 'active' => 1],
        ]);
        $this->expense(70, 5, 132000, '2025-03-01');
        $this->expense(71, 6, 4999, '2026-08-01');
        $this->expense(72, 6, 2001, '2026-09-01');

        $this->tktaskRows('domain', [self::TIMES + ['domain_id' => 1, 'company_id' => 3, 'url' => 'https://wattle.example.com', 'notes' => '', 'active' => 1]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function invoice(int $id, int $company, array $row = []): void
    {
        $this->tktaskRows('invoice', [$row + self::TIMES + [
            'invoice_id' => $id, 'fkey' => 'App\\Db\\Company', 'fid' => $company, 'purchase_order' => '', 'discount' => 0, 'tax' => 0, 'shipping' => 0,
            'billing_address' => '', 'issued_on' => '2025-02-01', 'paid_on' => null, 'cancelled_on' => null, 'notes' => '',
        ]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function item(int $id, int $invoice, int $price, array $row = []): void
    {
        $this->tktaskRows('invoice_item', [$row + self::TIMES + [
            'invoice_item_id' => $id, 'invoice_id' => $invoice, 'product_code' => 'LAB', 'description' => 'Labour', 'qty' => 1, 'price' => $price, 'notes' => '',
        ]]);
    }

    private function payment(int $id, int $invoice, int $amount, string $received): void
    {
        $this->tktaskRows('payment', [self::TIMES + ['payment_id' => $id, 'invoice_id' => $invoice, 'amount' => $amount, 'method' => 'eft', 'received_at' => $received, 'notes' => '']]);
    }

    private function expense(int $id, int $category, int $total, string $day): void
    {
        $this->tktaskRows('expense', [self::TIMES + [
            'expense_id' => $id, 'expense_category_id' => $category, 'company_id' => 9, 'invoice_no' => "BG-{$id}", 'receipt_no' => '', 'description' => 'Made up',
            'purchased_on' => $day, 'total' => $total,
        ]]);
    }

    private function import(): void
    {
        $this->artisan('tkcrm:import-tktask', ['--force' => true])->assertSuccessful();
    }

    /**
     * The checks by name.
     *
     * @return array<string, CheckResult>
     */
    private function check(): array
    {
        return collect(app(ImportCheck::class)->run())->keyBy(fn (CheckResult $result) => $result->name)->all();
    }

    /**
     * @return list<string>
     */
    private function differences(): array
    {
        return collect($this->check())->flatMap(fn (CheckResult $result) => $result->differences)->values()->all();
    }

    public function test_a_fresh_import_agrees_with_tktask_in_every_check(): void
    {
        $this->import();

        $checks = $this->check();

        $this->assertSame(
            ['Rows in each table', "Invoices against tktask's v_invoice", "Stored figures against tkcrm's own calculator", 'Outstanding by client', 'Profit and loss for each financial year'],
            array_keys($checks),
        );
        $this->assertSame([], $this->differences());
        // Four invoices in the view, three with stored figures, one client owing, and the years ending 2025, 2026 and 2027.
        $this->assertSame([4, 3, 1, 3], [
            $checks["Invoices against tktask's v_invoice"]->compared, $checks["Stored figures against tkcrm's own calculator"]->compared,
            $checks['Outstanding by client']->compared, $checks['Profit and loss for each financial year']->compared,
        ]);
        $this->assertContains('In all: $100.00 in tktask, $100.00 in tkcrm.', $checks['Outstanding by client']->notes);
    }

    public function test_the_command_says_everything_agrees_and_succeeds(): void
    {
        $this->import();

        $this->artisan('tkcrm:verify-import')
            ->expectsOutputToContain('Rows in each table')
            ->expectsOutputToContain('Profit and loss for each financial year')
            ->expectsOutputToContain('Everything agrees with tktask.')
            ->assertSuccessful();
    }

    public function test_the_check_changes_neither_database(): void
    {
        $this->import();
        $before = [DB::table('invoices')->get()->toJson(), DB::table('payments')->get()->toJson(), $this->tktask()->table('invoice')->get()->toJson()];

        $this->artisan('tkcrm:verify-import')->assertSuccessful();

        $this->assertSame($before, [DB::table('invoices')->get()->toJson(), DB::table('payments')->get()->toJson(), $this->tktask()->table('invoice')->get()->toJson()]);
    }

    public function test_a_stored_figure_that_is_not_tktasks_is_a_difference_and_the_command_fails(): void
    {
        $this->import();
        DB::table('invoices')->where('id', 2041)->update(['total' => 91301]);

        $this->assertSame([
            'Invoice 2041: total is $913.00 in tktask and $913.01 in tkcrm.',
            'Invoice 2041: unpaid is $0.00 in tktask and $0.01 in tkcrm.',
            'Invoice 2041: its stored total is $913.01, and its lines come to $913.00.',
        ], $this->differences());

        $this->artisan('tkcrm:verify-import')
            ->expectsOutputToContain('Invoice 2041: total is $913.00 in tktask and $913.01 in tkcrm.')
            ->expectsOutputToContain('There are 3 differences. Do not cut over until each is explained.')
            ->assertFailed();
    }

    public function test_each_figure_of_an_invoice_is_compared(): void
    {
        $this->import();
        DB::table('invoices')->where('id', 2041)->update(['subtotal' => 100100, 'discount_total' => 17100, 'gst_total' => 8400]);

        $differences = $this->check()["Invoices against tktask's v_invoice"]->differences;

        $this->assertSame([
            'Invoice 2041: sub-total is $1,000.00 in tktask and $1,001.00 in tkcrm.',
            'Invoice 2041: discount is $170.00 in tktask and $171.00 in tkcrm.',
            'Invoice 2041: tax is $83.00 in tktask and $84.00 in tkcrm.',
        ], $differences);
    }

    public function test_shipping_is_in_tkcrms_sub_total_and_beside_tktasks(): void
    {
        $this->import();

        $invoice = Invoice::findOrFail(2042);
        $this->assertSame([14750, 14750], [$invoice->subtotal->inCents(), $invoice->total->inCents()]);
        $this->assertSame(2, $invoice->lines()->count());
        $this->assertSame([], $this->differences());
    }

    public function test_a_status_that_differs_is_reported(): void
    {
        $this->import();
        DB::table('invoices')->where('id', 2043)->update(['cancelled_on' => null]);

        $this->assertContains('Invoice 2043 is cancelled in tktask and unpaid in tkcrm.', $this->differences());
    }

    public function test_a_line_changed_since_the_import_no_longer_comes_to_the_stored_figure(): void
    {
        $this->import();
        DB::table('invoice_lines')->where('id', 500)->update(['unit_price' => 100001]);

        $checks = $this->check();

        // tktask's figures are still the stored ones: only tkcrm's own sum has moved.
        $this->assertTrue($checks["Invoices against tktask's v_invoice"]->agrees());
        $this->assertSame([
            'Invoice 2041: its stored sub-total is $1,000.00, and its lines come to $1,000.01.',
            'Invoice 2041: its stored total is $913.00, and its lines come to $913.01.',
        ], $checks["Stored figures against tkcrm's own calculator"]->differences);
    }

    public function test_a_missing_payment_shows_in_the_invoice_what_is_owing_and_the_years_income(): void
    {
        $this->import();
        Payment::findOrFail(901)->delete();

        $this->assertSame([
            "payments: 1 row is in tktask's payment and not in tkcrm (id 901).",
            'Invoice 2042: paid is $47.50 in tktask and $0.00 in tkcrm.',
            'Invoice 2042: unpaid is $100.00 in tktask and $147.50 in tkcrm.',
            'Company 4 owes $100.00 in tktask and $147.50 in tkcrm.',
            '2026–27: income is $47.50 in tktask and $0.00 in tkcrm.',
            '2026–27: net profit is -$22.50 in tktask and -$70.00 in tkcrm.',
        ], $this->differences());
    }

    public function test_a_row_made_in_tkcrm_that_tktask_has_not_got_is_a_difference(): void
    {
        $this->import();
        Task::factory()->create(['id' => 4000, 'company_id' => 3, 'task_category_id' => 2]);

        $this->assertSame(["tasks: 1 row is in tkcrm and not in tktask's task (id 4000)."], $this->differences());
    }

    public function test_a_tktask_row_with_no_parent_is_noted_and_is_not_a_difference(): void
    {
        $this->item(1, 2000, 5000);
        $this->import();

        $rows = $this->check()['Rows in each table'];

        $this->assertTrue($rows->agrees());
        $this->assertContains('invoice_item: 1 row points at something tktask does not have, and could not be imported (id 1).', $rows->notes);
        $this->assertNull(InvoiceLine::find(1));
    }

    public function test_attached_files_that_did_not_come_over_are_a_difference(): void
    {
        $this->tktaskRows('file', [['file_id' => 1, 'user_id' => 1, 'fkey' => 'App\\Db\\Task', 'fid' => 41, 'label' => '', 'filename' => 'task/41/brief.pdf', 'bytes' => 10, 'mime' => 'application/pdf', 'created' => '2024-01-01 03:00:00']]);
        $this->import();

        $this->assertSame(
            ["attachments: tktask's file table has 1 attached file and tkcrm has 0. Run the import with --data=<path to tktask's data directory>."],
            $this->differences(),
        );
    }

    public function test_an_expense_changed_since_the_import_shows_in_its_years_profit_and_loss(): void
    {
        $this->import();
        Expense::findOrFail(71)->update(['total' => 5999]);

        // Half of $49.99 is claimed as $25.00 and half of $20.01 as $10.01, each rounded on its own.
        $this->assertSame([
            '2026–27: expenses is $70.00 in tktask and $80.00 in tkcrm.',
            '2026–27: claimable expenses is $35.01 in tktask and $40.01 in tkcrm.',
            '2026–27: net profit is -$22.50 in tktask and -$32.50 in tkcrm.',
        ], $this->differences());
    }

    public function test_a_payment_is_counted_in_the_year_tktasks_clock_puts_it_in(): void
    {
        // Half past eleven at night on 30 June in Melbourne is still the year ending 2026, though it is 1:30 pm UTC.
        $this->invoice(2050, 3, ['issued_on' => '2026-06-01', 'paid_on' => '2026-06-30']);
        $this->item(510, 2050, 20000);
        $this->payment(910, 2050, 20000, '2026-06-30 23:30:00');
        $this->import();

        $this->assertSame([], $this->differences());
    }

    public function test_a_category_tktasks_report_leaves_out_is_noted_and_counted_on_both_sides(): void
    {
        $this->tktaskRows('expense_category', [self::TIMES + ['expense_category_id' => 8, 'name' => 'Refunds', 'description' => '', 'claim' => 1, 'active' => 1]]);
        $this->expense(80, 8, -2500, '2026-08-10');
        $this->import();

        $report = $this->check()['Profit and loss for each financial year'];

        $this->assertTrue($report->agrees());
        $this->assertContains("2026–27: tktask's report leaves out Refunds, which comes to -\$25.00. It is counted here on both sides.", $report->notes);
    }

    public function test_a_tktask_that_cannot_be_read_fails_the_command(): void
    {
        config(['database.connections.tktask' => ['driver' => 'sqlite', 'database' => '/nowhere/tktask.sqlite']]);
        DB::purge('tktask');

        $this->artisan('tkcrm:verify-import')
            ->expectsOutputToContain('The tktask database could not be read')
            ->assertFailed();
    }
}
