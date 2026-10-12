<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Task;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class InvoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tables_have_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'company_id', 'number', 'purchase_order', 'discount', 'billing_address', 'issued_on', 'due_on', 'paid_on', 'cancelled_on',
                'notes', 'gst_rate', 'subtotal', 'discount_total', 'gst_total', 'total', 'reminded_on', 'reminders_off', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('invoices'),
        );

        $this->assertEqualsCanonicalizing(
            ['id', 'invoice_id', 'product_code', 'description', 'quantity', 'unit_price', 'notes', 'source_type', 'source_id', 'created_at', 'updated_at'],
            Schema::getColumnListing('invoice_lines'),
        );

        $this->assertEqualsCanonicalizing(
            ['id', 'invoice_id', 'amount', 'method', 'received_at', 'notes', 'created_at', 'updated_at'],
            Schema::getColumnListing('payments'),
        );
    }

    public function test_a_new_invoice_belongs_to_a_company_and_is_open_with_no_number_due_date_or_totals(): void
    {
        $company = Company::factory()->create();
        $invoice = Invoice::create(['company_id' => $company->id]);

        foreach ([$invoice, $invoice->fresh()] as $read) {
            $this->assertTrue($read->company->is($company));
            $this->assertTrue($read->isOpen());
            $this->assertSame(0, $read->discount);
            $this->assertSame(
                [null, null, null, null, null, null, null, null, null, null],
                [$read->number, $read->issued_on, $read->due_on, $read->paid_on, $read->cancelled_on, $read->gst_rate, $read->subtotal, $read->discount_total, $read->gst_total, $read->total],
            );
        }

        $this->assertTrue($company->invoices()->sole()->is($invoice));
    }

    public function test_what_issuing_sets_cannot_be_set_from_a_form(): void
    {
        $invoice = Invoice::factory()->create();

        $invoice->update([
            'number' => 7, 'issued_on' => '2026-06-01', 'due_on' => '2026-06-15', 'paid_on' => '2026-06-02', 'cancelled_on' => '2026-06-03',
            'gst_rate' => 1000, 'subtotal' => 100, 'discount_total' => 0, 'gst_total' => 10, 'total' => 110,
            'purchase_order' => 'PO-88', 'discount' => 1000, 'billing_address' => "1 Mill Lane\nWattle Creek", 'notes' => 'Thanks.',
        ]);

        $invoice = $invoice->fresh();
        $this->assertTrue($invoice->isOpen());
        $this->assertSame([null, null, null, null], [$invoice->number, $invoice->due_on, $invoice->gst_rate, $invoice->total]);
        $this->assertSame(['PO-88', 1000, "1 Mill Lane\nWattle Creek", 'Thanks.'], [$invoice->purchase_order, $invoice->discount, $invoice->billing_address, $invoice->notes]);
    }

    public function test_an_issued_invoice_keeps_its_number_dates_and_totals_as_they_were_stored(): void
    {
        $invoice = Invoice::factory()->create();
        $invoice->forceFill([
            'number' => 1042, 'issued_on' => '2026-06-01', 'due_on' => '2026-06-15',
            'gst_rate' => 1000, 'subtotal' => 100000, 'discount_total' => 10000, 'gst_total' => 9000, 'total' => 99000,
        ])->save();

        $invoice = $invoice->fresh();
        $this->assertFalse($invoice->isOpen());
        $this->assertSame([1042, 1000], [$invoice->number, $invoice->gst_rate]);
        $this->assertInstanceOf(CarbonImmutable::class, $invoice->issued_on);
        $this->assertSame(['2026-06-01', '2026-06-15'], [$invoice->issued_on->toDateString(), $invoice->due_on->toDateString()]);
        $this->assertInstanceOf(Money::class, $invoice->total);
        $this->assertSame([100000, 10000, 9000, 99000], [$invoice->subtotal->inCents(), $invoice->discount_total->inCents(), $invoice->gst_total->inCents(), $invoice->total->inCents()]);
        $this->assertSame(99000, DB::table('invoices')->value('total'));
    }

    public function test_the_open_scope_agrees_with_the_model(): void
    {
        $open = Invoice::factory()->create();
        $issued = Invoice::factory()->issued()->create();
        $cancelled = Invoice::factory()->create();
        $cancelled->forceFill(['cancelled_on' => '2026-06-01'])->save();

        $this->assertSame([true, false, false], [$open->isOpen(), $issued->fresh()->isOpen(), $cancelled->fresh()->isOpen()]);
        $this->assertSame([$open->id], Invoice::open()->pluck('id')->all());
    }

    public function test_two_invoices_cannot_share_a_number_but_many_can_have_none(): void
    {
        Invoice::factory()->count(2)->create();
        $first = Invoice::factory()->issued()->create();

        $this->expectException(QueryException::class);

        Invoice::factory()->create()->forceFill(['number' => $first->number])->save();
    }

    public function test_a_companys_open_invoice_is_found_or_made(): void
    {
        $company = Company::factory()->create();
        Invoice::factory()->for($company)->issued()->create();
        Invoice::factory()->create();

        $made = $company->openInvoice();

        $this->assertTrue($made->exists);
        $this->assertTrue($made->isOpen());
        $this->assertSame($company->id, $made->company_id);
        $this->assertSame(2, $company->invoices()->count());

        $this->assertTrue($company->openInvoice()->is($made));
        $this->assertSame(2, $company->invoices()->count());

        // Once that one is issued the next thing billed starts another, and the oldest open one is used.
        $made->forceFill(['issued_on' => '2026-06-01'])->save();
        $next = $company->openInvoice();
        Invoice::factory()->for($company)->create();

        $this->assertFalse($next->is($made));
        $this->assertTrue($company->openInvoice()->is($next));
    }

    public function test_a_line_has_a_decimal_string_quantity_and_a_price_in_cents(): void
    {
        $invoice = Invoice::factory()->create();
        $line = $invoice->lines()->create(['product_code' => 'LAB', 'description' => 'Labour', 'quantity' => '1.25', 'unit_price' => Money::parse('120.00'), 'notes' => 'June']);

        $line = $line->fresh();
        $this->assertSame('1.25', $line->quantity);
        $this->assertSame('1.25', DB::table('invoice_lines')->value('quantity'));
        $this->assertSame(12000, $line->unit_price->inCents());
        $this->assertSame(['LAB', 'Labour', 'June'], [$line->product_code, $line->description, $line->notes]);
        $this->assertSame(15000, $line->unit_price->multiply($line->quantity)->inCents());
        $this->assertNull($line->source);
        $this->assertTrue($line->invoice->is($invoice));

        $plain = $invoice->lines()->create(['description' => 'Call-out fee', 'unit_price' => -2500])->fresh();
        $this->assertSame(['1', -2500, null], [$plain->quantity, $plain->unit_price->inCents(), $plain->product_code]);
        $this->assertSame('3', $invoice->lines()->create(['description' => 'Domains', 'quantity' => 3, 'unit_price' => 100])->fresh()->quantity);
        $this->assertSame([$line->id, $plain->id], $invoice->lines()->limit(2)->pluck('id')->all());
    }

    public function test_a_quantity_that_is_a_float_negative_or_not_a_number_is_refused(): void
    {
        foreach ([1.25, '-1', '1.23456', 'two', '', null] as $quantity) {
            try {
                InvoiceLine::factory()->make(['quantity' => $quantity]);
                $this->fail('Accepted '.var_export($quantity, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('A quantity is', $e->getMessage());
            }
        }
    }

    public function test_a_line_can_point_at_the_record_it_was_made_from(): void
    {
        $task = Task::factory()->create();
        $line = InvoiceLine::factory()->make();
        $line->source()->associate($task)->save();

        $this->assertSame(['task', $task->id], [DB::table('invoice_lines')->value('source_type'), $line->fresh()->source_id]);
        $this->assertTrue($line->fresh()->source->is($task));
    }

    public function test_a_billed_task_points_at_its_line_and_loses_it_when_the_line_is_deleted(): void
    {
        $task = Task::factory()->create();
        $line = InvoiceLine::factory()->create();
        $task->forceFill(['invoice_line_id' => $line->id])->save();

        $this->assertTrue($task->fresh()->invoiceLine->is($line));

        $line->delete();
        $this->assertNull($task->fresh()->invoice_line_id);

        $this->expectException(QueryException::class);
        $task->forceFill(['invoice_line_id' => 9999])->save();
    }

    public function test_deleting_an_open_invoice_takes_its_lines_and_frees_their_tasks(): void
    {
        $invoice = Invoice::factory()->create();
        $line = InvoiceLine::factory()->for($invoice)->create();
        InvoiceLine::factory()->for($invoice)->create();
        $task = Task::factory()->create();
        $task->forceFill(['invoice_line_id' => $line->id])->save();

        $invoice->delete();

        $this->assertSame(0, InvoiceLine::count());
        $this->assertNull($task->fresh()->invoice_line_id);
    }

    public function test_a_payment_has_an_amount_a_method_and_when_it_was_received(): void
    {
        $invoice = Invoice::factory()->issued(20000)->create();
        $later = Payment::create(['invoice_id' => $invoice->id, 'amount' => 15000, 'method' => PaymentMethod::Card, 'received_at' => '2026-06-10 03:00:00']);
        $first = Payment::create(['invoice_id' => $invoice->id, 'amount' => Money::cents(5000), 'method' => 'eft', 'received_at' => '2026-06-02 01:30:00', 'notes' => 'Deposit']);

        $payment = $first->fresh();
        $this->assertSame([5000, PaymentMethod::Eft, '2026-06-02 01:30:00', 'Deposit'], [$payment->amount->inCents(), $payment->method, $payment->received_at->toDateTimeString(), $payment->notes]);
        $this->assertInstanceOf(CarbonImmutable::class, $payment->received_at);
        $this->assertTrue($payment->invoice->is($invoice));
        $this->assertSame([$first->id, $later->id], $invoice->payments()->pluck('id')->all());
        $this->assertSame(20000, Money::sum($invoice->payments->map->amount)->inCents());
    }

    public function test_the_payment_methods_are_cash_eft_card_crypto_and_other(): void
    {
        $this->assertSame(
            ['cash' => 'Cash', 'eft' => 'EFT', 'card' => 'Card', 'crypto' => 'Crypto', 'other' => 'Other'],
            collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [$method->value => $method->label()])->all(),
        );

        $this->expectException(\ValueError::class);
        Payment::factory()->create(['method' => 'cheque']);
    }

    public function test_an_invoice_with_a_payment_and_a_company_with_an_invoice_cannot_be_deleted(): void
    {
        $payment = Payment::factory()->create();

        try {
            $payment->invoice->delete();
            $this->fail('The invoice was deleted.');
        } catch (QueryException) {
            $this->assertSame(1, Invoice::count());
        }

        $this->expectException(QueryException::class);
        $payment->invoice->company->delete();
    }

    public function test_the_factories_make_an_open_invoice_a_line_and_a_payment(): void
    {
        $line = InvoiceLine::factory()->create();
        $issued = Invoice::factory()->issued(12345)->create();
        $next = Invoice::factory()->issued()->create();

        $this->assertTrue($line->invoice->isOpen());
        $this->assertSame(['1', 5000], [$line->quantity, $line->unit_price->inCents()]);
        $this->assertSame([12345, false], [$issued->fresh()->total->inCents(), $issued->fresh()->isOpen()]);
        $this->assertSame($issued->number + 1, $next->number);
        $this->assertFalse(Payment::factory()->create()->invoice->isOpen());
    }
}
