<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceEditTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private User $user;

    private Company $client;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 02:00:00');

        $this->user = User::factory()->create();
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery', 'address' => "1 Mill Lane\nWattle Creek"]);
        $this->invoice = Invoice::factory()->for($this->client)->create();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function line(array $changes = []): array
    {
        return $changes + ['product_code' => 'HOST-STD', 'description' => 'Web hosting', 'quantity' => '1.5', 'unit_price' => '1,200.00', 'notes' => 'To June 2027'];
    }

    private function issue(Invoice $invoice, int $total = 10000): Invoice
    {
        $invoice->forceFill(['number' => (Invoice::max('number') ?? 1000) + 1, 'issued_on' => '2026-06-01', 'due_on' => '2026-06-10', 'gst_rate' => 0, 'subtotal' => $total, 'discount_total' => 0, 'gst_total' => 0, 'total' => $total])->save();

        return $invoice;
    }

    private function taskLine(): InvoiceLine
    {
        $task = Task::factory()->for($this->client)->create(['subject' => 'Build the order form']);
        TaskLog::factory()->for($task)->for(Product::factory()->create(['price' => 12000]))->create(['minutes' => 90]);

        return $task->close();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $line = InvoiceLine::factory()->for($this->invoice)->create();

        $this->get("/invoices/{$this->invoice->id}")->assertRedirect(route('login'));
        $this->get("/invoices/{$this->invoice->id}/edit")->assertRedirect(route('login'));
        $this->post('/invoices', ['company_id' => $this->client->id])->assertRedirect(route('login'));
        $this->put("/invoices/{$this->invoice->id}", ['notes' => 'Nope'])->assertRedirect(route('login'));
        $this->delete("/invoices/{$this->invoice->id}")->assertRedirect(route('login'));
        $this->get("/invoices/{$this->invoice->id}/lines/create")->assertRedirect(route('login'));
        $this->post("/invoices/{$this->invoice->id}/lines", $this->line())->assertRedirect(route('login'));
        $this->get("/lines/{$line->id}/edit")->assertRedirect(route('login'));
        $this->put("/lines/{$line->id}", $this->line())->assertRedirect(route('login'));
        $this->delete("/lines/{$line->id}")->assertRedirect(route('login'));

        $this->assertSame([1, 1, null], [Invoice::count(), InvoiceLine::count(), $this->invoice->fresh()->notes]);
    }

    public function test_a_clients_open_invoice_is_found_or_started_from_its_page(): void
    {
        $this->actingAs($this->user)
            ->get("/companies/{$this->client->id}")
            ->assertSee('action="'.route('invoices.store').'"', false);

        $this->actingAs($this->user)
            ->post('/invoices', ['company_id' => $this->client->id])
            ->assertRedirect(route('invoices.show', $this->invoice));

        $other = Company::factory()->create();
        $this->actingAs($this->user)->post('/invoices', ['company_id' => $other->id])->assertRedirect(route('invoices.show', $other->invoices()->sole()));
        $this->actingAs($this->user)->post('/invoices', ['company_id' => 9999])->assertNotFound();

        $this->assertSame(2, Invoice::count());
    }

    public function test_the_page_shows_an_open_invoice_with_its_lines_and_totals(): void
    {
        $this->invoice->update(['purchase_order' => 'PO-88', 'discount' => 1000, 'notes' => 'Thanks for your business.']);
        $typed = InvoiceLine::factory()->for($this->invoice)->create(['product_code' => 'HOST-STD', 'description' => 'Web hosting', 'quantity' => '1.5', 'unit_price' => 120000, 'notes' => 'To June 2027']);
        $fromTask = $this->taskLine();

        $this->actingAs($this->user)
            ->get("/invoices/{$this->invoice->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Open invoice</h4>', false)
            ->assertSee('hx-get="'.route('invoices.edit', $this->invoice).'" hx-target="#app-modal-content"', false)
            ->assertSee('hx-get="'.route('invoices.lines.create', $this->invoice).'" hx-target="#app-modal-content"', false)
            ->assertSeeInOrder([
                'bg-secondary">Open', 'Wattle Creek Bakery', $this->client->account_number, '1 Mill Lane', 'The client&#039;s own address.', 'PO-88', 'Thanks for your business.',
                'HOST-STD', 'Web hosting', 'To June 2027', '1.5', '$1,200.00', '$1,800.00', 'hx-get="'.route('lines.edit', $typed).'"', 'action="'.route('lines.destroy', $typed).'"',
                'href="'.route('tasks.show', $fromTask->source).'"', "TSK-{$fromTask->source_id}", 'Build the order form — 1h 30m', '$180.00', 'From its task',
                'Sub-total', '$1,980.00', 'Discount (10%)', '-$198.00', 'Total', '$1,782.00',
            ], false)
            ->assertDontSee(route('lines.edit', $fromTask), false)
            ->assertDontSee('GST')
            ->assertDontSee('action="'.route('invoices.destroy', $this->invoice).'"', false);
    }

    public function test_gst_is_shown_on_the_page_only_when_the_invoice_charges_it(): void
    {
        InvoiceLine::factory()->for($this->invoice)->create(['quantity' => 2, 'unit_price' => 5000]);

        $this->actingAs($this->user)->get("/invoices/{$this->invoice->id}")->assertDontSee('GST')->assertDontSee('Sub-total')->assertSee('$100.00');

        Settings::set('gst_enabled', true);
        $this->app->forgetScopedInstances();

        $this->actingAs($this->user)
            ->get("/invoices/{$this->invoice->id}")
            ->assertSeeInOrder(['Sub-total', '$100.00', 'GST (10%)', '$10.00', 'Total', '$110.00', 'until the invoice is issued']);

        // An invoice issued without GST stays without it, whatever the setting says now.
        $this->issue($this->invoice);
        $this->actingAs($this->user)->get("/invoices/{$this->invoice->id}")->assertDontSee('GST')->assertSee('$100.00');
    }

    public function test_the_details_are_edited_in_the_dialog_while_the_invoice_is_open(): void
    {
        $this->invoice->update(['discount' => 1250]);

        $this->actingAs($this->user)
            ->get("/invoices/{$this->invoice->id}/edit", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Invoice details</h5>', false)
            ->assertSee('<form hx-put="'.route('invoices.update', $this->invoice).'" hx-target="#app-modal-content"', false)
            ->assertSee('name="discount" value="12.5"', false)
            ->assertSee('placeholder="1 Mill Lane', false)
            ->assertDontSee('<html', false);

        $this->actingAs($this->user)
            ->put("/invoices/{$this->invoice->id}", ['purchase_order' => 'PO-88', 'discount' => '7.5', 'billing_address' => "Accounts\nPO Box 4", 'notes' => 'Thanks.'], self::DIALOG)
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'Invoice saved.');

        $invoice = $this->invoice->fresh();
        $this->assertSame(['PO-88', 750, "Accounts\nPO Box 4", 'Thanks.'], [$invoice->purchase_order, $invoice->discount, $invoice->billing_address, $invoice->notes]);

        $this->actingAs($this->user)->get("/invoices/{$this->invoice->id}")->assertSeeInOrder(['Accounts', 'PO Box 4'])->assertDontSee('own address');

        // Emptied, there is no discount, and the client's address is used again.
        $this->actingAs($this->user)->put("/invoices/{$this->invoice->id}", ['purchase_order' => '', 'discount' => '', 'billing_address' => '', 'notes' => ''], self::DIALOG)->assertNoContent();
        $invoice = $this->invoice->fresh();
        $this->assertSame([null, 0, null, null], [$invoice->purchase_order, $invoice->discount, $invoice->billing_address, $invoice->notes]);

        foreach (['101', '-1', '7.555', 'ten'] as $bad) {
            $this->actingAs($this->user)
                ->put("/invoices/{$this->invoice->id}", ['discount' => $bad, 'notes' => 'Typed <b>'], self::DIALOG)
                ->assertStatus(422)
                ->assertSee('<h5 class="modal-title">Invoice details</h5>', false)
                ->assertSee('is-invalid')
                ->assertSee('>Typed &lt;b&gt;</textarea>', false);
        }

        $this->assertSame(0, $this->invoice->fresh()->discount);
    }

    public function test_a_line_is_added_in_the_dialog_which_a_product_can_fill(): void
    {
        $product = Product::factory()->create(['name' => 'Web hosting', 'code' => 'HOST-STD']);
        Product::factory()->inactive()->create(['name' => 'Legacy hosting']);

        $this->actingAs($this->user)
            ->get("/invoices/{$this->invoice->id}/lines/create", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Add line</h5>', false)
            ->assertSee('<form hx-post="'.route('invoices.lines.store', $this->invoice).'" hx-target="#app-modal-content"', false)
            ->assertSee('<option value="'.$product->id.'">Web hosting (HOST-STD)</option>', false)
            ->assertSee('/lookup', false)
            ->assertSee('name="quantity" value="1"', false)
            ->assertDontSee('Legacy hosting')
            ->assertDontSee('GST')
            ->assertDontSee('<html', false);

        // What the dialog asks for when a product is chosen.
        $this->actingAs($this->user)->getJson("/products/{$product->id}/lookup")->assertJsonFragment(['code' => 'HOST-STD', 'name' => 'Web hosting', 'price' => '50.00']);

        $this->actingAs($this->user)
            ->post("/invoices/{$this->invoice->id}/lines", $this->line(), self::DIALOG)
            ->assertNoContent()
            ->assertHeader('HX-Refresh', 'true')
            ->assertSessionHas('success', 'Line added.');

        $line = $this->invoice->lines()->sole();
        $this->assertSame(
            ['HOST-STD', 'Web hosting', '1.5', 120000, 'To June 2027', null, 180000],
            [$line->product_code, $line->description, $line->quantity, $line->unit_price->inCents(), $line->notes, $line->source_id, $line->total()->inCents()],
        );

        // A line that takes money off, with no code.
        $this->actingAs($this->user)
            ->post("/invoices/{$this->invoice->id}/lines", ['description' => 'Goodwill credit', 'quantity' => '1', 'unit_price' => '-50'], self::DIALOG)
            ->assertNoContent();
        $this->assertSame(175000, $this->invoice->fresh()->totals()->total->inCents());
    }

    public function test_a_line_is_edited_and_deleted_while_the_invoice_is_open(): void
    {
        $line = InvoiceLine::factory()->for($this->invoice)->create(['product_code' => 'HOST-STD', 'description' => 'Web hosting', 'quantity' => '1.5', 'unit_price' => 120000]);

        $this->actingAs($this->user)
            ->get("/lines/{$line->id}/edit", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Edit line</h5>', false)
            ->assertSee('<form hx-put="'.route('lines.update', $line).'"', false)
            ->assertSee('name="product_code" x-model="code" value="HOST-STD"', false)
            ->assertSee('name="quantity" value="1.5"', false)
            ->assertSee('value="1200.00"', false)
            ->assertSee('>Web hosting</textarea>', false);

        $this->actingAs($this->user)
            ->put("/lines/{$line->id}", $this->line(['description' => 'Web hosting, two sites', 'quantity' => '2', 'unit_price' => '$1,100', 'product_code' => '']), self::DIALOG)
            ->assertNoContent()
            ->assertSessionHas('success', 'Line saved.');

        $line->refresh();
        $this->assertSame([null, 'Web hosting, two sites', '2', 110000], [$line->product_code, $line->description, $line->quantity, $line->unit_price->inCents()]);

        $this->actingAs($this->user)
            ->delete("/lines/{$line->id}")
            ->assertRedirect(route('invoices.show', $this->invoice))
            ->assertSessionHas('success', 'Line deleted.');

        $this->assertSame(0, InvoiceLine::count());
    }

    public function test_a_bad_line_comes_back_in_the_dialog_with_what_was_typed(): void
    {
        $bad = [
            'description' => '',
            'quantity' => ['0', '0.00', '-1', '1.23456', 'two', '', '1e3'],
            'unit_price' => ['', 'lots', '12.345'],
        ];

        foreach ($bad as $field => $values) {
            foreach ((array) $values as $value) {
                $this->actingAs($this->user)
                    ->post("/invoices/{$this->invoice->id}/lines", $this->line([$field => $value, 'notes' => 'Typed <b>']), self::DIALOG)
                    ->assertStatus(422)
                    ->assertSee('<h5 class="modal-title">Add line</h5>', false)
                    ->assertSee('is-invalid')
                    ->assertSee('>Typed &lt;b&gt;</textarea>', false);
            }
        }

        $line = InvoiceLine::factory()->for($this->invoice)->create(['description' => 'As it was']);

        $this->actingAs($this->user)
            ->put("/lines/{$line->id}", $this->line(['quantity' => 'two']), self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Edit line</h5>', false)
            ->assertSee('The quantity must be a number above zero with up to four decimal places, such as 1.5.')
            ->assertSee('<form hx-put="'.route('lines.update', $line).'"', false);

        $this->assertSame([1, 'As it was'], [InvoiceLine::count(), $line->fresh()->description]);
    }

    public function test_a_tasks_line_is_not_edited_or_deleted_on_the_invoice_so_its_task_is_never_left_billed_without_one(): void
    {
        $line = $this->taskLine();
        $task = $line->source;

        $this->actingAs($this->user)->get("/lines/{$line->id}/edit", self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->put("/lines/{$line->id}", $this->line(), self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->delete("/lines/{$line->id}")->assertForbidden();

        $this->assertSame([18000, $line->id], [$line->fresh()->unit_price->inCents(), $task->fresh()->invoice_line_id]);
        $this->assertNotNull($task->fresh()->invoiced_at);

        // An invoice with its line cannot be deleted from under it either.
        $this->actingAs($this->user)
            ->delete("/invoices/{$this->invoice->id}")
            ->assertRedirect(route('invoices.show', $this->invoice))
            ->assertSessionHas('error', 'Take the lines off this invoice before deleting it.');
        $this->assertSame(1, Invoice::count());

        // Re-opening the task is how the line comes off.
        $this->actingAs($this->user)->post("/tasks/{$task->id}/reopen");
        $this->assertSame([0, null], [InvoiceLine::count(), $task->fresh()->invoiced_at]);
    }

    public function test_an_empty_open_invoice_can_be_deleted(): void
    {
        $this->actingAs($this->user)
            ->get("/invoices/{$this->invoice->id}")
            ->assertSee('Nothing on this invoice yet.')
            ->assertSee('action="'.route('invoices.destroy', $this->invoice).'"', false);

        $this->actingAs($this->user)
            ->delete("/invoices/{$this->invoice->id}")
            ->assertRedirect(route('companies.show', $this->client))
            ->assertSessionHas('success', 'The empty invoice was deleted.');

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_issued_invoice_is_read_only(): void
    {
        $line = InvoiceLine::factory()->for($this->invoice)->create(['description' => 'Web hosting', 'quantity' => 2, 'unit_price' => 5000]);
        $this->issue($this->invoice);
        Payment::factory()->for($this->invoice)->create(['amount' => 4000, 'received_at' => '2026-06-05 01:00:00', 'notes' => 'Deposit']);

        $this->actingAs($this->user)
            ->get("/invoices/{$this->invoice->id}")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Invoice '.$this->invoice->number.'</h4>', false)
            ->assertSeeInOrder(['bg-warning">Unpaid', 'Issued', '1 Jun 2026', 'Due', '10 Jun 2026', 'Web hosting', '$100.00', 'Payments', '5 Jun 2026', 'EFT', 'Deposit', '$40.00'], false)
            ->assertDontSee(route('invoices.edit', $this->invoice), false)
            ->assertDontSee(route('invoices.lines.create', $this->invoice), false)
            ->assertDontSee(route('lines.edit', $line), false)
            ->assertDontSee('action="'.route('lines.destroy', $line).'"', false)
            ->assertDontSee('action="'.route('invoices.destroy', $this->invoice).'"', false);

        $this->actingAs($this->user)->get("/invoices/{$this->invoice->id}/edit", self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->put("/invoices/{$this->invoice->id}", ['notes' => 'Changed'], self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->delete("/invoices/{$this->invoice->id}")->assertForbidden();
        $this->actingAs($this->user)->get("/invoices/{$this->invoice->id}/lines/create", self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->post("/invoices/{$this->invoice->id}/lines", $this->line(), self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->get("/lines/{$line->id}/edit", self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->put("/lines/{$line->id}", $this->line(), self::DIALOG)->assertForbidden();
        $this->actingAs($this->user)->delete("/lines/{$line->id}")->assertForbidden();

        $this->assertSame([null, 1, 'Web hosting'], [$this->invoice->fresh()->notes, InvoiceLine::count(), $line->fresh()->description]);

        // The stored total is shown, whatever the line would now come to.
        $line->forceFill(['unit_price' => 99900])->save();
        $this->actingAs($this->user)->get("/invoices/{$this->invoice->id}")->assertSeeInOrder(['Total', '$100.00']);
    }

    public function test_the_page_lists_the_clients_other_unpaid_invoices(): void
    {
        $overdue = $this->issue(Invoice::factory()->for($this->client)->create(), 25000);
        $paid = $this->issue(Invoice::factory()->for($this->client)->create(), 7700);
        $paid->forceFill(['paid_on' => '2026-06-12'])->save();
        $cancelled = $this->issue(Invoice::factory()->for($this->client)->create(), 8800);
        $cancelled->forceFill(['cancelled_on' => '2026-06-12'])->save();
        $stillOpen = Invoice::factory()->for($this->client)->create();
        $elsewhere = $this->issue(Invoice::factory()->create(), 9900);

        $this->actingAs($this->user)
            ->get("/invoices/{$this->invoice->id}")
            ->assertSeeInOrder(['Wattle Creek Bakery also owes', 'href="'.route('invoices.show', $overdue).'"', "Invoice {$overdue->number}", 'text-danger', 'Due 10 Jun 2026', '$250.00'], false)
            ->assertDontSee('href="'.route('invoices.show', $paid).'"', false)
            ->assertDontSee('href="'.route('invoices.show', $cancelled).'"', false)
            ->assertDontSee('href="'.route('invoices.show', $stillOpen).'"', false)
            ->assertDontSee('href="'.route('invoices.show', $elsewhere).'"', false);

        // Its own page does not list itself, and a client that owes nothing else has no such list.
        $this->actingAs($this->user)->get("/invoices/{$overdue->id}")->assertDontSee('also owes');
        $this->actingAs($this->user)->get("/invoices/{$elsewhere->id}")->assertDontSee('also owes');
    }

    public function test_a_billed_task_links_to_its_invoice(): void
    {
        $line = $this->taskLine();

        $this->actingAs($this->user)
            ->get("/tasks/{$line->source_id}")
            ->assertSee('href="'.route('invoices.show', $this->invoice).'"', false);
    }
}
