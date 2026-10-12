<?php

namespace Tests\Feature\Import;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\InvoicesPass;
use App\Import\TktaskSource;
use App\Invoices\InvoiceNumber;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use App\Review\ReviewQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InvoicesPassTest extends TestCase
{
    use RefreshDatabase;
    use TktaskDatabase;

    private const TIMES = ['created' => '2024-01-01 03:00:00', 'modified' => '2025-03-01 04:00:00'];

    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October 2026 in Melbourne.
        $this->travelTo('2026-10-15 02:00:00');
        $this->setUpTktask();

        // What the earlier passes have already brought in.
        Company::factory()->create(['id' => 3, 'name' => 'Wattle Creek Bakery', 'address' => "1 Mill Lane\nKyneton VIC 3444", 'email' => 'sam@wattle.example.com']);
        $this->tktaskRows('registry', [['key' => 'site.account.overdue.days', 'value' => '21']]);
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TktaskSource::class));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function invoice(int $id, array $row = []): void
    {
        $this->tktaskRows('invoice', [$row + self::TIMES + [
            'invoice_id' => $id, 'fkey' => 'App\\Db\\Company', 'fid' => 3, 'purchase_order' => '', 'discount' => 0, 'tax' => 0, 'shipping' => 0,
            'billing_address' => '', 'issued_on' => '2025-02-01', 'paid_on' => null, 'cancelled_on' => null, 'notes' => '',
        ]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function item(int $id, int $invoice, int $price, array $row = []): void
    {
        $this->tktaskRows('invoice_item', [$row + self::TIMES + [
            'invoice_item_id' => $id, 'invoice_id' => $invoice, 'product_code' => 'HOST-STD', 'description' => 'Web hosting', 'qty' => 1, 'price' => $price, 'notes' => '',
        ]]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function payment(int $id, int $invoice, int $amount, array $row = []): void
    {
        $this->tktaskRows('payment', [$row + self::TIMES + [
            'payment_id' => $id, 'invoice_id' => $invoice, 'amount' => $amount, 'method' => 'eft', 'received_at' => '2025-02-10 09:30:00', 'notes' => '',
        ]]);
    }

    private function import(): ImportContext
    {
        $context = $this->context();
        (new InvoicesPass)->run($context);

        return $context;
    }

    public function test_an_issued_invoice_takes_its_id_as_its_number_and_its_totals_from_tktasks_view(): void
    {
        $this->invoice(2041, ['purchase_order' => 'PO-77', 'notes' => 'Thanks']);
        $this->item(500, 2041, 33000);
        $this->item(501, 2041, 4500, ['qty' => 2, 'product_code' => '', 'description' => 'Domain renewal']);

        $context = $this->import();

        $invoice = Invoice::sole();
        $this->assertSame(
            [2041, 2041, 3, 'PO-77', 'Thanks', '2025-02-01', '2025-02-22', null, null, 0, 0, 42000, 0, 0, 42000, "1 Mill Lane\nKyneton VIC 3444"],
            [$invoice->id, $invoice->number, $invoice->company_id, $invoice->purchase_order, $invoice->notes, $invoice->issued_on->toDateString(), $invoice->due_on->toDateString(),
                $invoice->paid_on, $invoice->cancelled_on, $invoice->discount, $invoice->gst_rate, $invoice->subtotal->inCents(), $invoice->discount_total->inCents(),
                $invoice->gst_total->inCents(), $invoice->total->inCents(), $invoice->billing_address],
        );
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status());
        $this->assertSame(['2024-01-01 03:00:00', '2025-03-01 04:00:00'], [$invoice->created_at->toDateTimeString(), $invoice->updated_at->toDateTimeString()]);
        // The stored figures are what tkcrm reads from here on.
        $this->assertSame('$420.00', $invoice->totals()->total->format());
        $this->assertContains("Due dates are the issue date plus 21 days, tktask's overdue days.", $context->notes);
        $this->assertSame([], $context->warnings);

        $lines = InvoiceLine::orderBy('id')->get();
        $this->assertSame(
            [[500, 'HOST-STD', 'Web hosting', '1', 33000], [501, null, 'Domain renewal', '2', 4500]],
            $lines->map(fn (InvoiceLine $line) => [$line->id, $line->product_code, $line->description, $line->quantity, $line->unit_price->inCents()])->all(),
        );
        $this->assertSame('$90.00', $lines[1]->total()->format());
    }

    public function test_the_float_discount_and_tax_become_basis_points_and_the_totals_stay_tktasks(): void
    {
        // $1,000.00 less 17% is $830.00, plus 10% tax is $913.00. A FLOAT holds 0.17 as 0.170000002.
        $this->invoice(2041, ['discount' => '0.170000002', 'tax' => '0.1']);
        $this->item(500, 2041, 100000);

        $context = $this->import();

        $invoice = Invoice::sole();
        $this->assertSame([1700, 1000], [$invoice->discount, $invoice->gst_rate]);
        $this->assertSame([100000, 17000, 8300, 91300], [$invoice->subtotal->inCents(), $invoice->discount_total->inCents(), $invoice->gst_total->inCents(), $invoice->total->inCents()]);
        $this->assertTrue($invoice->totals()->chargesGst());
        $this->assertSame([], $context->warnings);
    }

    public function test_a_discount_or_tax_that_is_not_a_whole_number_of_basis_points_is_reported(): void
    {
        $this->invoice(2041, ['discount' => '0.12345']);
        $this->invoice(2042, ['tax' => '0.33333']);
        $this->item(500, 2041, 100000);
        $this->item(501, 2042, 100000);

        $context = $this->import();

        $this->assertSame([1235, 3333], [Invoice::find(2041)->discount, Invoice::find(2042)->gst_rate]);
        // The stored totals are still tktask's own, to the cent.
        $this->assertSame([12345, 33333], [Invoice::find(2041)->discount_total->inCents(), Invoice::find(2042)->gst_total->inCents()]);
        $this->assertSame([
            'Invoice 2041 has a discount of 0.12345, which is not a whole number of hundredths of a percent. It was imported as 12.35%.',
            'Invoice 2042 has a tax of 0.33333, which is not a whole number of hundredths of a percent. It was imported as 33.33%.',
        ], $context->warnings);
    }

    public function test_an_invoice_for_anything_but_a_company_tktask_has_is_reported_and_skipped_with_its_lines_and_payments(): void
    {
        $this->invoice(2041, ['fkey' => 'App\\Db\\User']);
        $this->invoice(2042, ['fid' => 99]);
        $this->invoice(2043);
        $this->item(500, 2041, 1000);
        $this->item(501, 2043, 1000);
        $this->item(502, 7777, 1000);
        $this->payment(900, 2042, 1000);

        $context = $this->import();

        $this->assertSame([2043], Invoice::pluck('id')->all());
        $this->assertSame([501], InvoiceLine::pluck('id')->all());
        $this->assertSame(0, Payment::count());
        $this->assertSame([
            'Invoice 2041 is not for a company tktask has (App\\Db\\User 3). It was not imported, nor its lines and payments.',
            'Invoice 2042 is not for a company tktask has (App\\Db\\Company 99). It was not imported, nor its lines and payments.',
            'Invoice line 500 is on invoice 2041, which was not imported. It was left behind.',
            'Invoice line 502 is on invoice 7777, which was not imported. It was left behind.',
            'Payment 900 is against invoice 2042, which was not imported. It was left behind.',
        ], $context->warnings);
    }

    public function test_open_paid_and_cancelled_invoices_keep_their_status(): void
    {
        $this->invoice(2040, ['issued_on' => null]);
        $this->invoice(2041, ['paid_on' => '2025-02-10']);
        $this->invoice(2042, ['cancelled_on' => '2025-02-05']);
        $this->invoice(2043, ['issued_on' => null, 'cancelled_on' => '2025-02-05']);
        foreach ([2040, 2041, 2042, 2043] as $n => $id) {
            $this->item(500 + $n, $id, 10000);
        }
        $this->payment(900, 2041, 10000);

        $this->import();

        $this->assertSame(
            [InvoiceStatus::Open, InvoiceStatus::Paid, InvoiceStatus::Cancelled, InvoiceStatus::Cancelled],
            Invoice::orderBy('id')->get()->map->status()->all(),
        );

        // An open invoice has no number, due date or stored figures: it is worked out from its lines.
        $open = Invoice::find(2040);
        $this->assertSame([null, null, null, null], [$open->number, $open->due_on, $open->total, $open->billing_address]);
        $this->assertSame('$100.00', $open->totals()->total->format());

        // One cancelled before it was ever issued still has its number and what it came to.
        $never = Invoice::find(2043);
        $this->assertSame([2043, null, 10000], [$never->number, $never->issued_on, $never->total->inCents()]);

        $this->assertSame(['2025-02-10', '$0.00'], [Invoice::find(2041)->paid_on->toDateString(), Invoice::find(2041)->outstanding()->format()]);

        // Every one of them opens on its own page.
        $this->actingAs(User::factory()->create());
        foreach ([2040, 2041, 2042, 2043] as $id) {
            $this->get("/invoices/{$id}")->assertOk();
        }
    }

    public function test_an_invoice_paid_without_being_issued_is_given_the_day_it_was_paid(): void
    {
        $this->invoice(2041, ['issued_on' => null, 'paid_on' => '2025-02-10']);
        $this->item(500, 2041, 10000);
        $this->payment(900, 2041, 10000);

        $context = $this->import();

        $invoice = Invoice::sole();
        $this->assertSame([InvoiceStatus::Paid, 2041, '2025-02-10', '2025-03-03'], [$invoice->status(), $invoice->number, $invoice->issued_on->toDateString(), $invoice->due_on->toDateString()]);
        $this->assertSame(['Invoice 2041 was paid without being issued. Its issue date was set to the day it was paid.'], $context->warnings);
    }

    public function test_shipping_becomes_a_line_and_the_total_is_still_tktasks(): void
    {
        $this->invoice(2041, ['shipping' => 1500, 'tax' => '0.1']);
        $this->item(500, 2041, 10000);

        $context = $this->import();

        $invoice = Invoice::sole();
        // tktask: $100.00 plus $10.00 tax plus $15.00 shipping. The shipping is in the sub-total here.
        $this->assertSame([11500, 1000, 12500], [$invoice->subtotal->inCents(), $invoice->gst_total->inCents(), $invoice->total->inCents()]);
        $shipping = InvoiceLine::where('description', 'Shipping')->sole();
        $this->assertSame([2041, '1', 1500, null], [$shipping->invoice_id, $shipping->quantity, $shipping->unit_price->inCents(), $shipping->product_code]);
        $this->assertGreaterThan(500, $shipping->id);
        $this->assertSame(['Invoice 2041 has shipping of $15.00, which tkcrm does not have. It became a line.'], $context->warnings);
    }

    public function test_a_line_a_task_was_billed_on_is_linked_to_it_and_the_task_to_the_line(): void
    {
        $category = TaskCategory::factory()->create();
        Task::factory()->for(Company::find(3))->create(['id' => 41, 'task_category_id' => $category->id])->forceFill(['closed_at' => now(), 'invoiced_at' => now()])->save();
        Task::factory()->for(Company::find(3))->create(['id' => 42, 'task_category_id' => $category->id]);

        $this->tktaskRows('task', [
            ['task_id' => 41, 'invoice_item_id' => 500],
            ['task_id' => 42, 'invoice_item_id' => 0],
        ]);
        $this->invoice(2041);
        $this->item(500, 2041, 18000, ['product_code' => 'TSK-41', 'description' => 'Build the order form']);
        // The code alone names a task that tktask does not say was billed on it.
        $this->item(501, 2041, 5000, ['product_code' => 'TSK-42']);
        $this->item(502, 2041, 5000, ['product_code' => 'TSK-999']);
        $this->item(503, 2041, 5000);

        $context = $this->import();

        $this->assertSame(
            [[500, 'task', 41], [501, 'task', 42], [502, null, null], [503, null, null]],
            InvoiceLine::orderBy('id')->get()->map(fn (InvoiceLine $line) => [$line->id, $line->source_type, $line->source_id])->all(),
        );
        $this->assertTrue(InvoiceLine::find(500)->source->is(Task::find(41)));
        $this->assertTrue(InvoiceLine::find(500)->isLockedBySource());
        $this->assertSame([500, null], [Task::find(41)->invoice_line_id, Task::find(42)->invoice_line_id]);
        $this->assertTrue(Task::find(41)->invoiceLine->is(InvoiceLine::find(500)));
        $this->assertSame(['Invoice line 502 is for task 999, which was not imported. It was imported with no task.'], $context->warnings);
    }

    public function test_quantities_become_decimal_strings_and_a_negative_one_moves_to_the_price(): void
    {
        $this->invoice(2041);
        $this->item(500, 2041, 12000, ['qty' => 1.5]);
        $this->item(501, 2041, 12000, ['qty' => 0.25]);
        $this->item(502, 2041, -5000, ['description' => 'Goodwill credit']);
        $this->item(503, 2041, 5000, ['qty' => -2]);
        $this->item(504, 2041, 9999, ['qty' => 1.23456789]);
        $this->item(505, 2041, 1000, ['description' => '', 'product_code' => '']);

        $context = $this->import();

        $this->assertSame(
            [['1.5', 12000, 18000], ['0.25', 12000, 3000], ['1', -5000, -5000], ['2', -5000, -10000], ['1.2346', 9999, 12345], ['1', 1000, 1000]],
            InvoiceLine::orderBy('id')->get()->map(fn (InvoiceLine $line) => [$line->quantity, $line->unit_price->inCents(), $line->total()->inCents()])->all(),
        );
        $this->assertSame('No description', InvoiceLine::find(505)->description);
        $this->assertSame([
            'Invoice line 504 has a quantity of 1.23456789, which has more than four decimal places. It was imported as 1.2346.',
            "Invoice line 504 comes to a different amount in tkcrm than in tktask. Its invoice keeps tktask's stored total.",
        ], $context->warnings);
        // The invoice's stored total is tktask's, whatever a line now comes to.
        $this->assertSame(18000 + 3000 - 5000 - 10000 + 12344 + 1000, Invoice::sole()->total->inCents());
    }

    public function test_a_line_that_comes_to_a_different_amount_here_is_reported(): void
    {
        $this->invoice(2041);
        // 1.23456789 × $99.99 is $123.44 in tktask; at 1.2346 it is $123.45 here.
        $this->item(500, 2041, 9999, ['qty' => 1.23456789]);

        $context = $this->import();

        $this->assertContains("Invoice line 500 comes to a different amount in tkcrm than in tktask. Its invoice keeps tktask's stored total.", $context->warnings);
    }

    public function test_payments_keep_their_ids_methods_and_the_moment_they_were_received(): void
    {
        $this->invoice(2041, ['paid_on' => '2025-02-12']);
        $this->item(500, 2041, 30000);
        $this->payment(900, 2041, 10000, ['method' => 'cash', 'received_at' => '2025-01-15 09:30:00', 'notes' => 'Deposit']);
        $this->payment(901, 2041, 20000, ['method' => 'cheque', 'received_at' => '2025-07-15 09:30:00']);

        $context = $this->import();

        $payments = Payment::orderBy('id')->get();
        $this->assertSame(
            // Melbourne's clock: eleven hours ahead of UTC in January, ten in July.
            [[900, 2041, 10000, PaymentMethod::Cash, '2025-01-14 22:30:00', 'Deposit'], [901, 2041, 20000, PaymentMethod::Other, '2025-07-14 23:30:00', null]],
            $payments->map(fn (Payment $payment) => [$payment->id, $payment->invoice_id, $payment->amount->inCents(), $payment->method, $payment->received_at->toDateTimeString(), $payment->notes])->all(),
        );
        $this->assertSame('$300.00', Invoice::sole()->paidTotal()->format());
        $this->assertSame(['Payment 901 was made by "cheque", which tkcrm does not have. It was imported as other.'], $context->warnings);
        $this->assertSame(902, Payment::factory()->for(Invoice::sole())->create()->id);
    }

    public function test_a_part_paid_invoice_owes_the_rest_and_an_overpaid_one_is_reported(): void
    {
        $this->invoice(2041);
        $this->item(500, 2041, 30000);
        $this->payment(900, 2041, 10000);
        $this->invoice(2042, ['paid_on' => '2025-02-12']);
        $this->item(501, 2042, 30000);
        $this->payment(901, 2042, 35000);

        $context = $this->import();

        $this->assertSame('$200.00', Invoice::find(2041)->outstanding()->format());
        $this->assertSame(['Invoice 2042 has payments of $350.00 against a total of $300.00. tkcrm refuses an overpayment, but these were imported as they are.'], $context->warnings);
    }

    public function test_the_number_counter_is_left_above_the_highest_number_not_the_highest_id(): void
    {
        $this->invoice(2041);
        $this->invoice(2139);
        // Opened after the last one issued, and not issued yet.
        $this->invoice(2140, ['issued_on' => null]);
        foreach ([2041, 2139, 2140] as $n => $id) {
            $this->item(500 + $n, $id, 10000);
        }

        $context = $this->import();

        $this->assertSame(2140, InvoiceNumber::next());
        $this->assertContains('The next invoice issued will be number 2140.', $context->notes);

        // So the open one takes the number tktask would have given it.
        Invoice::find(2140)->issue();
        $this->assertSame(2140, Invoice::find(2140)->number);
        // And an invoice made in tkcrm takes the next id, and the next number when it is issued.
        $new = Company::find(3)->openInvoice();
        $new->lines()->create(['description' => 'New work', 'unit_price' => 1000]);
        $new->issue();
        $this->assertSame([2141, 2141], [$new->id, $new->number]);
    }

    public function test_unpaid_invoices_are_not_put_straight_into_the_reminder_queue(): void
    {
        $this->invoice(2041, ['issued_on' => '2025-02-01']);
        $this->invoice(2042, ['issued_on' => '2025-02-01', 'paid_on' => '2025-02-10']);
        $this->item(500, 2041, 10000);
        $this->item(501, 2042, 10000);
        $this->payment(900, 2042, 10000);

        $context = $this->import();

        $unpaid = Invoice::find(2041);
        $this->assertTrue($unpaid->isOverdue());
        $this->assertSame(['2026-10-15', '2026-10-29'], [$unpaid->reminded_on->toDateString(), $unpaid->reminderDueOn()->toDateString()]);
        $this->assertNull(Invoice::find(2042)->reminded_on);
        $this->assertSame(0, ReviewQueue::reminders()->count());
        $this->assertContains('1 unpaid invoice was marked as reminded today, so no overdue reminder is drafted for it until 14 days from now.', $context->notes);
    }

    public function test_an_open_invoice_with_tax_in_tktask_is_reported(): void
    {
        $this->invoice(2040, ['issued_on' => null, 'tax' => '0.1']);
        $this->item(500, 2040, 10000);

        $context = $this->import();

        $this->assertSame(["Open invoice 2040 has tax of 10% in tktask. tkcrm works an open invoice's GST out from its own GST setting."], $context->warnings);
    }

    public function test_without_tktasks_view_of_the_totals_the_pass_will_not_guess(): void
    {
        $this->tktask()->unprepared('DROP VIEW v_invoice');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no v_invoice view');

        $this->import();
    }

    public function test_the_whole_import_brings_invoices_in_after_tasks_and_again_without_doubling(): void
    {
        $names = array_map(fn (string $pass) => (new $pass)->name(), Importer::PASSES);
        $this->assertLessThan(array_search('Invoices', $names), array_search('Tasks', $names));
        $this->assertLessThan(array_search('Settings', $names), array_search('Invoices', $names));

        $this->tktaskRows('company', [self::TIMES + ['company_id' => 3, 'type' => 'Client', 'name' => 'Wattle Creek Bakery', 'credit' => 0, 'active' => 1]]);
        $this->tktaskRows('task_category', [self::TIMES + ['task_category_id' => 2, 'name' => 'Development', 'order_by' => 1, 'active' => 1]]);
        $this->tktaskRows('task', [self::TIMES + ['task_id' => 41, 'company_id' => 3, 'project_id' => 0, 'task_category_id' => 2, 'subject' => 'Build the order form', 'priority' => 5, 'minutes' => 0, 'closed_at' => '2025-02-01 05:00:00', 'invoiced_at' => '2025-02-01 05:00:00', 'invoice_item_id' => 500]]);
        $this->invoice(2041, ['paid_on' => '2025-02-10']);
        $this->item(500, 2041, 18000, ['product_code' => 'TSK-41']);
        $this->payment(900, 2041, 18000);

        $this->artisan('tkcrm:import-tktask', ['--force' => true])->expectsOutputToContain('The next invoice issued will be number 2042.')->assertSuccessful();

        $this->assertSame([1, 1, 1, 500], [Invoice::count(), InvoiceLine::count(), Payment::count(), Task::sole()->invoice_line_id]);
        // The task is closed and billed on an invoice that is no longer open, so it cannot be re-opened.
        $this->assertFalse(Task::sole()->canReopen());

        $this->travelTo('2026-10-16 02:00:00');
        $this->artisan('tkcrm:import-tktask')->assertSuccessful();

        $this->assertSame([1, 1, 1, 500, 2042], [Invoice::count(), InvoiceLine::count(), Payment::count(), Task::sole()->invoice_line_id, InvoiceNumber::next()]);
    }
}
