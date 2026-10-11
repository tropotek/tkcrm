<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Invoices\InvoiceSearch;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private User $user;

    private Company $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 16 June in Melbourne, which is still the 15th in UTC.
        $this->travelTo('2026-06-16 00:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);

        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->invoice = $this->issued($this->client, 100000);
    }

    private function issued(Company $company, int $cents): Invoice
    {
        $invoice = InvoiceLine::factory()->for(Invoice::factory()->for($company))->create(['unit_price' => $cents])->invoice;
        $invoice->issue();

        return $invoice->fresh();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + ['amount' => '400.00', 'method' => 'eft', 'received_on' => '2026-06-12', 'notes' => 'Deposit'];
    }

    private function pay(array $changes = [])
    {
        return $this->post("/invoices/{$this->invoice->id}/payments", $this->form($changes), self::DIALOG);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $payment = Payment::factory()->for($this->invoice)->create();
        auth()->logout();

        $this->get("/invoices/{$this->invoice->id}/payments/create")->assertRedirect(route('login'));
        $this->post("/invoices/{$this->invoice->id}/payments", $this->form())->assertRedirect(route('login'));
        $this->delete("/payments/{$payment->id}")->assertRedirect(route('login'));

        $this->assertSame(1, Payment::count());
    }

    public function test_the_dialog_begins_with_everything_owing_by_eft_today(): void
    {
        $this->get("/invoices/{$this->invoice->id}")
            ->assertSee('hx-get="'.route('invoices.payments.create', $this->invoice).'" hx-target="#app-modal-content"', false);

        $this->get("/invoices/{$this->invoice->id}/payments/create", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Record payment</h5>', false)
            ->assertSee('<form hx-post="'.route('invoices.payments.store', $this->invoice).'" hx-target="#app-modal-content"', false)
            ->assertSeeInOrder(['Wattle Creek Bakery', '$1,000.00', 'still owing'])
            ->assertSee('name="amount" value="1000.00"', false)
            ->assertSee('<option value="eft" selected>EFT</option>', false)
            ->assertSeeInOrder(['Cash', 'EFT', 'Card', 'Crypto', 'Other'])
            ->assertSee('name="received_on" value="2026-06-16" max="2026-06-16"', false)
            ->assertDontSee('<html', false);
    }

    public function test_a_part_payment_leaves_the_rest_owing(): void
    {
        $this->pay()
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'Payment of $400.00 recorded. $600.00 is still owing.');

        $payment = Payment::sole();
        // The 12th in Melbourne begins at two in the afternoon of the 11th in UTC.
        $this->assertSame(
            [$this->invoice->id, 40000, PaymentMethod::Eft, '2026-06-11 14:00:00', 'Deposit'],
            [$payment->invoice_id, $payment->amount->inCents(), $payment->method, $payment->received_at->toDateTimeString(), $payment->notes],
        );

        $invoice = $this->invoice->fresh();
        $this->assertSame([InvoiceStatus::Unpaid, null, 40000, 60000], [$invoice->status(), $invoice->paid_on, $invoice->paidTotal()->inCents(), $invoice->outstanding()->inCents()]);

        $this->get("/invoices/{$invoice->id}/payments/create", self::DIALOG)
            ->assertSee('name="amount" value="600.00"', false)
            ->assertSeeInOrder(['$600.00', 'still owing of $1,000.00']);

        $this->get("/invoices/{$invoice->id}")
            ->assertSeeInOrder(['Payments', '12 Jun 2026', 'EFT', 'Deposit', '$400.00', 'action="'.route('payments.destroy', $payment).'"', 'Paid', '$400.00', 'Still owing', '$600.00'], false)
            ->assertDontSee('action="'.route('invoices.reopen', $invoice).'"', false);
    }

    public function test_the_invoice_is_paid_dated_by_the_payment_when_payments_reach_its_total(): void
    {
        $this->pay(['amount' => '400', 'received_on' => '2026-06-10']);

        $this->pay(['amount' => '$600.00', 'method' => 'card', 'received_on' => '2026-06-14', 'notes' => ''])
            ->assertNoContent()
            ->assertSessionHas('success', "Payment of \$600.00 recorded. Invoice {$this->invoice->number} is paid.");

        $invoice = $this->invoice->fresh();
        $this->assertSame([InvoiceStatus::Paid, '2026-06-14', 0, 100000], [$invoice->status(), $invoice->paid_on->toDateString(), $invoice->outstanding()->inCents(), $invoice->paidTotal()->inCents()]);
        $this->assertSame([PaymentMethod::Eft, PaymentMethod::Card], $invoice->payments->map->method->all());

        $this->get("/invoices/{$invoice->id}")
            ->assertSeeInOrder(['bg-success">Paid', 'Paid', '14 Jun 2026', 'Payments', '10 Jun 2026', '14 Jun 2026', 'Card', 'Paid', '$1,000.00'], false)
            ->assertDontSee('Still owing')
            ->assertDontSee(route('invoices.payments.create', $invoice), false);
    }

    public function test_one_payment_of_the_whole_amount_pays_it(): void
    {
        $this->pay(['amount' => '1,000.00', 'received_on' => '2026-06-16']);

        $this->assertSame([InvoiceStatus::Paid, '2026-06-16'], [$this->invoice->fresh()->status(), $this->invoice->fresh()->paid_on->toDateString()]);
    }

    public function test_the_amount_must_be_above_zero_and_no_more_than_is_owing(): void
    {
        $this->pay(['amount' => '400']);

        $bad = [
            '600.01' => 'The amount is more than the $600.00 still owing.',
            '1000' => 'The amount is more than the $600.00 still owing.',
            '0' => 'The amount must be above zero.',
            '0.00' => 'The amount must be above zero.',
            '-50' => 'The amount must not be negative.',
            'lots' => 'must be an amount of money',
            '' => __('validation.required', ['attribute' => 'amount']),
        ];

        foreach ($bad as $amount => $message) {
            $this->pay(['amount' => (string) $amount, 'notes' => 'Typed <b>', 'method' => 'cash'])
                ->assertStatus(422)
                ->assertSee('<h5 class="modal-title">Record payment</h5>', false)
                ->assertSee($message)
                ->assertSee('value="Typed &lt;b&gt;"', false)
                ->assertSee('<option value="cash" selected>', false);
        }

        $this->assertSame([1, 60000], [Payment::count(), $this->invoice->fresh()->outstanding()->inCents()]);

        // To the cent: exactly what is owing is not too much.
        $this->pay(['amount' => '600.00'])->assertNoContent();
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status());
    }

    public function test_the_method_is_one_of_five_and_the_date_is_not_in_the_future(): void
    {
        foreach (PaymentMethod::cases() as $method) {
            $this->pay(['amount' => '10', 'method' => $method->value])->assertNoContent();
        }

        $this->assertSame(['cash', 'eft', 'card', 'crypto', 'other'], Payment::orderBy('id')->get()->map(fn (Payment $payment) => $payment->method->value)->all());

        foreach ([['method' => 'cheque'], ['method' => ''], ['received_on' => '2026-06-17'], ['received_on' => '12/06/2026'], ['received_on' => '']] as $bad) {
            $this->pay(['amount' => '10'] + $bad)->assertStatus(422)->assertSee('is-invalid');
        }

        $this->pay(['amount' => '10', 'received_on' => '2026-06-17'])->assertSee('The date received cannot be after today.');
        $this->assertSame(5, Payment::count());
    }

    public function test_deleting_a_payment_returns_a_paid_invoice_to_unpaid(): void
    {
        $this->pay(['amount' => '400']);
        $this->pay(['amount' => '600']);
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status());

        $this->delete('/payments/'.Payment::orderBy('id')->first()->id)
            ->assertRedirect(route('invoices.show', $this->invoice))
            ->assertSessionHas('success', "Payment of \$400.00 deleted. Invoice {$this->invoice->number} is unpaid again.");

        $invoice = $this->invoice->fresh();
        $this->assertSame([InvoiceStatus::Unpaid, null, 40000, 1], [$invoice->status(), $invoice->paid_on, $invoice->outstanding()->inCents(), Payment::count()]);

        // A part payment deleted leaves it unpaid, and with none left it can be re-opened again.
        $this->delete('/payments/'.Payment::sole()->id)->assertSessionHas('success', 'Payment of $600.00 deleted.');
        $this->assertSame([InvoiceStatus::Unpaid, 100000, null], [$invoice->fresh()->status(), $invoice->fresh()->outstanding()->inCents(), $invoice->fresh()->reopenProblem()]);
    }

    public function test_only_an_unpaid_invoice_takes_a_payment(): void
    {
        $open = InvoiceLine::factory()->create()->invoice;
        $paid = $this->issued($this->client, 5000);
        $paid->recordPayment(Money::cents(5000), PaymentMethod::Cash, now());
        $cancelled = $this->issued($this->client, 5000);
        $cancelled->cancel();

        foreach ([$open, $paid->fresh(), $cancelled->fresh()] as $invoice) {
            $this->get("/invoices/{$invoice->id}/payments/create", self::DIALOG)->assertForbidden();
            $this->post("/invoices/{$invoice->id}/payments", $this->form(['amount' => '10']), self::DIALOG)
                ->assertStatus(422)
                ->assertSee('A payment can only be recorded against an unpaid invoice.');
            $this->assertSame('A payment can only be recorded against an unpaid invoice.', $invoice->paymentProblem(Money::cents(1000)));
            $this->assertTrue($invoice->outstanding()->isZero());
        }

        $this->assertSame(1, Payment::count());

        $this->expectException(LogicException::class);
        $open->recordPayment(Money::cents(1000), PaymentMethod::Cash, now());
    }

    public function test_a_payment_recorded_on_the_invoice_itself_sends_nothing(): void
    {
        Mail::fake();
        Queue::fake();

        $this->invoice->recordPayment(Money::cents(100000), PaymentMethod::Eft, now());

        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_the_list_and_the_also_owes_card_show_what_is_still_owing_after_part_payments(): void
    {
        $other = $this->issued($this->client, 25000);
        $this->pay(['amount' => '400']);

        $totals = InvoiceSearch::fromInput([])->totals();
        $this->assertSame([2, 125000, 85000], [$totals['count'], $totals['total']->inCents(), $totals['owing']->inCents()]);

        $this->get('/invoices')
            ->assertSeeInOrder([(string) $other->number, '$250.00', '$250.00', (string) $this->invoice->number, '$1,000.00', '$600.00', '2 invoices', '$1,250.00', '$850.00']);

        $this->get("/invoices/{$other->id}")
            ->assertSeeInOrder(['Wattle Creek Bakery also owes', "Invoice {$this->invoice->number}", '$600.00']);

        // Paid in full, it is owed nothing and drops off the other invoice's page.
        $this->pay(['amount' => '600']);
        $this->assertSame(25000, InvoiceSearch::fromInput(['show' => 'all'])->totals()['owing']->inCents());
        $this->get("/invoices/{$other->id}")->assertDontSee('also owes');
    }
}
