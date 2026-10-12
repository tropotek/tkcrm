<?php

namespace Tests\Feature;

use App\Enums\Cycle;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MailLog;
use App\Models\Product;
use App\Models\Recurring;
use App\Models\User;
use App\Recurrings\RecurringRun;
use App\Recurrings\RecurringRunResult;
use App\Support\NotificationFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecurringRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Product $hosting;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 June 2026, at noon in Melbourne.
        $this->travelTo('2026-06-15 02:00:00');

        $this->user = User::factory()->create();
        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->hosting = Product::factory()->cycle(Cycle::Year)->create(['name' => 'Web hosting', 'code' => 'HOST-STD', 'price' => 33000]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(string $start, Cycle $cycle = Cycle::Year, array $attributes = []): Recurring
    {
        return Recurring::factory()->for($this->client)->for($this->hosting)->cycle($cycle)->create(['start_on' => $start] + $attributes);
    }

    private function bill(?string $today = null): RecurringRunResult
    {
        return app(RecurringRun::class)->run($today ? CarbonImmutable::parse($today) : null);
    }

    /**
     * @return list<string>
     */
    private function descriptions(): array
    {
        return InvoiceLine::orderBy('id')->pluck('description')->all();
    }

    public function test_a_due_item_adds_one_line_for_its_period_to_the_clients_open_invoice_and_moves_on(): void
    {
        $recurring = $this->item('2026-06-15', attributes: ['description' => 'Hosting for the bakery site']);

        $result = $this->bill();

        $invoice = Invoice::sole();
        $line = $invoice->lines()->sole();

        $this->assertTrue($invoice->isOpen());
        $this->assertSame($this->client->id, $invoice->company_id);
        $this->assertSame(
            ['HOST-STD', 'Hosting for the bakery site — 15 Jun 2026 to 14 Jun 2027', '1', 33000],
            [$line->product_code, $line->description, $line->quantity, $line->unit_price->inCents()],
        );
        $this->assertTrue($line->source->is($recurring));

        $recurring->refresh();
        $this->assertSame(['2026-06-15', '2027-06-15', 1], [$recurring->previous_on->toDateString(), $recurring->next_on->toDateString(), $recurring->count]);
        $this->assertTrue($recurring->active);
        $this->assertSame([1, 0, 0], [$result->lines->count(), $result->skipped, $result->ended]);
    }

    public function test_an_item_not_yet_due_is_left_alone(): void
    {
        $recurring = $this->item('2026-06-16');

        $this->bill();

        $this->assertSame(0, Invoice::count());
        $this->assertSame(['2026-06-16', 0], [$recurring->fresh()->next_on->toDateString(), $recurring->fresh()->count]);
        $this->assertNull($recurring->fresh()->previous_on);
    }

    public function test_today_is_the_day_where_the_business_is_not_utcs(): void
    {
        $recurring = $this->item('2026-06-16');

        // 15 June in UTC is already the 16th in Melbourne.
        $this->travelTo('2026-06-15 15:00:00');
        $this->bill();

        $this->assertSame(1, $recurring->fresh()->count);
    }

    public function test_it_bills_once_per_cycle_however_often_it_runs(): void
    {
        $recurring = $this->item('2026-06-15', Cycle::Month);

        $this->bill();
        $this->bill();
        $this->bill('2026-07-14');

        $this->assertSame(1, InvoiceLine::count());

        $this->bill('2026-07-15');
        $this->bill('2026-07-15');

        $this->assertSame(
            ['Web hosting — 15 Jun 2026 to 14 Jul 2026', 'Web hosting — 15 Jul 2026 to 14 Aug 2026'],
            $this->descriptions(),
        );
        $this->assertSame(1, Invoice::count());
        $this->assertSame(['2026-07-15', '2026-08-15', 2], [$recurring->fresh()->previous_on->toDateString(), $recurring->fresh()->next_on->toDateString(), $recurring->fresh()->count]);
    }

    public function test_a_copy_of_an_item_read_before_another_run_billed_it_does_not_bill_it_again(): void
    {
        $recurring = $this->item('2026-06-15', Cycle::Month);
        $stale = Recurring::find($recurring->id);

        $this->bill();

        $this->assertNull(app(RecurringRun::class)->advance($stale));
        $this->assertSame(1, InvoiceLine::count());
        $this->assertSame(1, $recurring->fresh()->count);
    }

    public function test_an_item_several_cycles_behind_gets_one_line_per_cycle(): void
    {
        $recurring = $this->item('2026-01-31', Cycle::Month);

        $result = $this->bill();

        $this->assertSame(
            [
                'Web hosting — 31 Jan 2026 to 27 Feb 2026',
                'Web hosting — 28 Feb 2026 to 30 Mar 2026',
                'Web hosting — 31 Mar 2026 to 29 Apr 2026',
                'Web hosting — 30 Apr 2026 to 30 May 2026',
                'Web hosting — 31 May 2026 to 29 Jun 2026',
            ],
            $this->descriptions(),
        );
        $this->assertSame(['2026-05-31', '2026-06-30', 5], [$recurring->fresh()->previous_on->toDateString(), $recurring->fresh()->next_on->toDateString(), $recurring->fresh()->count]);
        $this->assertSame(5, $result->lines->count());
        $this->assertSame('$1,650.00', Invoice::sole()->totals()->total->format());
    }

    public function test_the_price_is_the_items_own_or_else_the_products_on_the_day_and_is_copied(): void
    {
        $following = $this->item('2026-06-15');
        $own = $this->item('2026-06-15', attributes: ['price' => 27550]);
        $loose = Recurring::factory()->for($this->client)->withoutProduct()->cycle(Cycle::Quarter)->create(['start_on' => '2026-06-15', 'price' => 18000, 'description' => 'Site care plan']);

        $this->hosting->update(['price' => 36000]);
        $this->bill();

        $this->assertSame(
            [[$following->id, 'HOST-STD', 36000], [$own->id, 'HOST-STD', 27550], [$loose->id, null, 18000]],
            InvoiceLine::orderBy('id')->get()->map(fn (InvoiceLine $line) => [$line->source_id, $line->product_code, $line->unit_price->inCents()])->all(),
        );
        $this->assertSame('Site care plan — 15 Jun 2026 to 14 Sep 2026', $this->descriptions()[2]);

        // A later price change does not reach what is already on the invoice.
        $this->hosting->update(['price' => 99900]);

        $this->assertSame(36000, InvoiceLine::orderBy('id')->first()->unit_price->inCents());
    }

    public function test_an_inactive_item_moves_on_with_no_line(): void
    {
        $recurring = $this->item('2026-04-15', Cycle::Month, ['active' => false]);

        $result = $this->bill();

        $this->assertSame(0, Invoice::count());
        $recurring->refresh();
        $this->assertSame(['2026-06-15', '2026-07-15', 0], [$recurring->previous_on->toDateString(), $recurring->next_on->toDateString(), $recurring->count]);
        $this->assertFalse($recurring->active);
        $this->assertTrue($recurring->hasRun());
        $this->assertSame([0, 3], [$result->lines->count(), $result->skipped]);
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_an_item_past_its_end_date_is_made_inactive(): void
    {
        $ended = $this->item('2025-06-15', attributes: ['previous_on' => '2025-06-15', 'next_on' => '2026-06-15', 'count' => 1, 'end_on' => '2026-06-14']);
        $endsToday = $this->item('2025-06-15', attributes: ['previous_on' => '2025-06-15', 'next_on' => '2026-06-15', 'count' => 1, 'end_on' => '2026-06-15']);
        $notDue = $this->item('2026-01-01', attributes: ['previous_on' => '2026-01-01', 'next_on' => '2027-01-01', 'count' => 1, 'end_on' => '2026-06-01']);
        $endsLater = $this->item('2026-01-01', attributes: ['previous_on' => '2026-01-01', 'next_on' => '2027-01-01', 'count' => 1, 'end_on' => '2026-06-30']);

        $result = $this->bill();

        // Nothing is billed for a day after the end date, and the item stays where it stopped.
        $this->assertSame([false, '2026-06-15', 1], [$ended->fresh()->active, $ended->fresh()->next_on->toDateString(), $ended->fresh()->count]);
        // The end date is the last day it may bill on.
        $this->assertSame([true, '2027-06-15', 2], [$endsToday->fresh()->active, $endsToday->fresh()->next_on->toDateString(), $endsToday->fresh()->count]);
        $this->assertFalse($notDue->fresh()->active);
        $this->assertTrue($endsLater->fresh()->active);
        $this->assertSame([$endsToday->id], InvoiceLine::pluck('source_id')->all());
        $this->assertSame(2, $result->ended);

        // The one that billed on its last day goes the day after.
        $this->assertSame(1, $this->bill('2026-06-16')->ended);
        $this->assertFalse($endsToday->fresh()->active);
        $this->assertSame(1, InvoiceLine::count());
    }

    public function test_an_item_behind_that_has_ended_is_billed_up_to_its_end_date_only(): void
    {
        $recurring = $this->item('2026-03-15', Cycle::Month, ['end_on' => '2026-04-30']);

        $this->bill();

        $this->assertSame(['Web hosting — 15 Mar 2026 to 14 Apr 2026', 'Web hosting — 15 Apr 2026 to 14 May 2026'], $this->descriptions());
        $this->assertSame([false, '2026-05-15', 2], [$recurring->fresh()->active, $recurring->fresh()->next_on->toDateString(), $recurring->fresh()->count]);

        $this->bill('2026-08-01');

        $this->assertSame(2, InvoiceLine::count());
        $this->assertSame('2026-05-15', $recurring->fresh()->next_on->toDateString());
    }

    public function test_a_next_date_that_has_drifted_off_the_items_dates_is_billed_once_and_put_back_on_them(): void
    {
        // As tktask left it: started on the 31st, and its next date has slid to the 2nd.
        $recurring = $this->item('2026-01-31', Cycle::Month, ['previous_on' => '2026-05-02', 'next_on' => '2026-06-02', 'count' => 4]);

        $this->bill();

        $this->assertSame(['Web hosting — 31 May 2026 to 29 Jun 2026'], $this->descriptions());
        $this->assertSame(['2026-05-31', '2026-06-30', 5], [$recurring->fresh()->previous_on->toDateString(), $recurring->fresh()->next_on->toDateString(), $recurring->fresh()->count]);
    }

    public function test_lines_join_an_open_invoice_the_client_already_has_and_each_client_has_its_own(): void
    {
        $other = Company::factory()->create(['name' => 'Ironbark Surveying']);
        $open = $this->client->openInvoice();
        $open->lines()->create(['description' => 'Typed by hand', 'unit_price' => 5000]);

        $this->item('2026-06-15');
        $this->item('2026-06-01', Cycle::Month);
        Recurring::factory()->for($other)->for($this->hosting)->cycle(Cycle::Year)->create(['start_on' => '2026-06-10']);

        $this->bill();

        $this->assertSame(2, Invoice::count());
        $this->assertSame(3, $open->lines()->count());
        $this->assertSame(1, $other->openInvoice()->lines()->count());
        $this->assertSame('$710.00', $open->fresh()->totals()->total->format());
    }

    public function test_every_login_is_told_once_per_client_with_new_lines(): void
    {
        $second = User::factory()->create();
        $other = Company::factory()->create(['name' => 'Ironbark Surveying']);

        $this->item('2026-06-15');
        $this->item('2026-05-15', Cycle::Month);
        Recurring::factory()->for($other)->for($this->hosting)->cycle(Cycle::Year)->create(['start_on' => '2026-06-10']);
        Recurring::factory()->for(Company::factory()->create(['name' => 'Saltbush Yoga Studio']))->for($this->hosting)->inactive()->create(['start_on' => '2026-06-10']);

        $this->bill();

        $notices = $this->user->notifications()->get()->map(fn ($notification) => NotificationFeed::present($notification));

        $this->assertSame(2, $notices->count());
        $this->assertSame(2, $second->notifications()->count());

        $bakery = $notices->firstWhere('url', route('invoices.show', $this->client->openInvoice(), false));
        $this->assertSame('Recurring items billed', $bakery->title);
        $this->assertSame('Wattle Creek Bakery: 3 lines added to its open invoice, $990.00. Nothing has been issued or sent.', $bakery->text);

        $ironbark = $notices->firstWhere('url', route('invoices.show', $other->openInvoice(), false));
        $this->assertSame('Ironbark Surveying: 1 line added to its open invoice, $330.00. Nothing has been issued or sent.', $ironbark->text);

        // A run with nothing to bill tells nobody anything.
        $this->bill();
        $this->assertSame(2, $this->user->notifications()->count());
    }

    public function test_it_never_issues_an_invoice_and_never_sends_mail(): void
    {
        Mail::fake();
        Queue::fake();

        $this->item('2026-01-15', Cycle::Month);
        $this->item('2026-06-15');
        $issued = Invoice::factory()->for($this->client)->create();
        $issued->lines()->create(['description' => 'Earlier work', 'unit_price' => 10000]);
        $issued->issue();

        $this->bill();
        $this->artisan('tkcrm:bill-recurring')->assertSuccessful();

        $open = Invoice::open()->sole();
        $this->assertSame(7, $open->lines()->count());
        $this->assertNull($open->number);
        $this->assertNull($open->issued_on);
        $this->assertSame(1, Invoice::whereNotNull('issued_on')->count());
        $this->assertSame(1, $issued->fresh()->lines()->count());

        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        $this->assertSame(0, MailLog::count());
    }

    public function test_the_command_bills_what_is_due_and_says_what_it_did(): void
    {
        $this->item('2026-06-15');
        $this->item('2026-06-15', attributes: ['active' => false]);
        $this->item('2025-01-01', attributes: ['previous_on' => '2026-01-01', 'next_on' => '2027-01-01', 'count' => 2, 'end_on' => '2026-06-01']);

        $this->artisan('tkcrm:bill-recurring')
            ->expectsOutputToContain('1 line added to 1 open invoice, 1 inactive cycle passed over, 1 ended item made inactive.')
            ->assertSuccessful();

        $this->artisan('tkcrm:bill-recurring')
            ->expectsOutputToContain('Nothing was due.')
            ->assertSuccessful();

        $this->assertSame(1, InvoiceLine::count());
    }

    public function test_a_line_from_a_recurring_item_can_be_changed_or_deleted_on_its_open_invoice(): void
    {
        $recurring = $this->item('2026-06-15');
        $this->bill();
        $line = InvoiceLine::sole();

        $this->assertTrue($line->isFromSource());
        $this->assertFalse($line->isLockedBySource());

        $this->actingAs($this->user)
            ->get(route('invoices.show', $line->invoice_id))
            ->assertOk()
            ->assertSee(route('lines.edit', $line), false)
            ->assertSee(route('recurrings.edit', $recurring), false)
            ->assertDontSee('From its task');

        $this->actingAs($this->user)->get(route('lines.edit', $line))->assertOk();
        $this->actingAs($this->user)->delete(route('lines.destroy', $line))->assertRedirect();

        // Taken off for this cycle: the item has moved on and does not bill it again.
        $this->bill();
        $this->assertSame(0, InvoiceLine::count());
        $this->assertSame('2027-06-15', $recurring->fresh()->next_on->toDateString());
    }
}
