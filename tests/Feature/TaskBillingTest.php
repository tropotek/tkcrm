<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TaskBillingTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Task $task;

    private Product $labour;

    private Product $afterHours;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 02:00:00');

        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->task = Task::factory()->for($this->client)->create(['subject' => 'Build the order form']);
        $this->labour = Product::factory()->create(['name' => 'Labour', 'price' => 12000]);
        $this->afterHours = Product::factory()->create(['name' => 'After hours', 'price' => 18000]);
    }

    private function log(int $minutes, ?Product $product = null, bool $billable = true, ?Task $task = null): TaskLog
    {
        return TaskLog::factory()->for($task ?? $this->task)->for($product ?? $this->labour)->create(['minutes' => $minutes, 'billable' => $billable]);
    }

    public function test_closing_puts_one_line_on_the_clients_open_invoice_priced_from_each_logs_rate(): void
    {
        // 50 minutes at $120 is $100.00 and 50 at $180 is $150.00. The same 100 minutes at either
        // rate alone, or at their average, would not come to $250.00 by way of rounded hours.
        $this->log(50);
        $this->log(50, $this->afterHours);
        $this->log(20, billable: false);

        // A price change after the work was logged does not reach the bill.
        $this->labour->update(['price' => 99900]);

        $this->task->close();

        $task = $this->task->fresh();
        $invoice = Invoice::sole();
        $line = InvoiceLine::sole();

        $this->assertSame([$this->client->id, true], [$invoice->company_id, $invoice->isOpen()]);
        $this->assertSame(
            [$invoice->id, "TSK-{$task->id}", 'Build the order form — 1h 40m', '1', 25000],
            [$line->invoice_id, $line->product_code, $line->description, $line->quantity, $line->unit_price->inCents()],
        );
        $this->assertTrue($line->source->is($task));
        $this->assertSame(
            [TaskStatus::Closed, '2026-06-15 02:00:00', '2026-06-15 02:00:00', $line->id],
            [$task->status(), $task->closed_at->toDateTimeString(), $task->invoiced_at->toDateTimeString(), $task->invoice_line_id],
        );
        $this->assertTrue($task->invoiceLine->is($line));
    }

    public function test_tasks_closed_for_one_client_share_its_open_invoice_and_another_client_gets_its_own(): void
    {
        $waiting = Invoice::factory()->for($this->client)->create();
        Invoice::factory()->for($this->client)->issued()->create();

        $second = Task::factory()->for($this->client)->create(['subject' => 'Connect the gateway']);
        $elsewhere = Task::factory()->create(['subject' => 'Restore a mailbox']);

        $this->log(60);
        $this->log(30, task: $second);
        $this->log(15, task: $elsewhere);

        $this->task->close();
        $second->close();
        $elsewhere->close();

        $this->assertSame(
            ['Build the order form — 1h' => 12000, 'Connect the gateway — 30m' => 6000],
            $waiting->lines()->get()->mapWithKeys(fn (InvoiceLine $line) => [$line->description => $line->unit_price->inCents()])->all(),
        );

        $other = $elsewhere->fresh()->invoiceLine->invoice;
        $this->assertSame([$elsewhere->company_id, 1, 3], [$other->company_id, $other->lines()->count(), Invoice::count()]);
    }

    public function test_a_task_with_no_billable_time_closes_without_a_line(): void
    {
        $this->log(45, billable: false);
        $empty = Task::factory()->for($this->client)->create();

        $this->task->close();
        $empty->close();

        foreach ([$this->task->fresh(), $empty->fresh()] as $task) {
            $this->assertSame([TaskStatus::Closed, null, null], [$task->status(), $task->invoiced_at, $task->invoice_line_id]);
        }

        $this->assertSame([0, 0], [InvoiceLine::count(), Invoice::count()]);
    }

    public function test_billable_time_at_no_charge_still_gets_its_line(): void
    {
        $this->log(30, Product::factory()->create(['price' => 0]));

        $this->task->close();

        $this->assertSame(['Build the order form — 30m', 0], [InvoiceLine::sole()->description, InvoiceLine::sole()->unit_price->inCents()]);
    }

    public function test_cancelling_bills_nothing(): void
    {
        $this->log(60);

        $this->task->cancel();

        $task = $this->task->fresh();
        $this->assertSame([TaskStatus::Cancelled, null, null, 0, 0], [$task->status(), $task->invoiced_at, $task->invoice_line_id, InvoiceLine::count(), Invoice::count()]);
    }

    public function test_reopening_takes_the_line_off_an_open_invoice(): void
    {
        $other = Task::factory()->for($this->client)->create();
        $this->log(60);
        $this->log(30, task: $other);
        $this->task->close();
        $other->close();

        $this->assertFalse($this->task->fresh()->isLockedByInvoice());
        $this->assertTrue($this->task->fresh()->canReopen());

        $this->task->fresh()->reopen();

        $task = $this->task->fresh();
        $this->assertSame([TaskStatus::Open, null, null, null], [$task->status(), $task->closed_at, $task->invoiced_at, $task->invoice_line_id]);
        $this->assertSame(["TSK-{$other->id}"], Invoice::sole()->lines()->pluck('product_code')->all());

        // More work, and it is billed afresh when it closes again.
        $this->log(60, $this->afterHours);
        $task->close();

        $this->assertSame(
            ["TSK-{$other->id}" => 6000, "TSK-{$task->id}" => 30000],
            Invoice::sole()->lines()->get()->mapWithKeys(fn (InvoiceLine $line) => [$line->product_code => $line->unit_price->inCents()])->all(),
        );
    }

    public function test_a_task_whose_line_is_on_an_issued_invoice_cannot_be_reopened(): void
    {
        $this->log(60);
        $this->task->close();
        Invoice::sole()->forceFill(['number' => 1001, 'issued_on' => '2026-06-15', 'total' => 12000])->save();

        $task = $this->task->fresh();
        $this->assertTrue($task->isLockedByInvoice());
        $this->assertFalse($task->canReopen());

        try {
            $task->reopen();
            $this->fail('It was re-opened.');
        } catch (LogicException) {
            $this->assertSame([TaskStatus::Closed, 1], [$task->fresh()->status(), InvoiceLine::count()]);
        }

        // A cancelled invoice is as fixed as an issued one.
        Invoice::sole()->forceFill(['issued_on' => null, 'cancelled_on' => '2026-06-16'])->save();
        $this->assertTrue($task->fresh()->isLockedByInvoice());

        // Back to open, as a re-opened invoice is, and the task can follow.
        Invoice::sole()->forceFill(['cancelled_on' => null])->save();
        $this->assertTrue($task->fresh()->canReopen());
    }

    public function test_a_closed_task_with_no_line_reopens_and_only_an_open_task_closes(): void
    {
        $this->task->close();
        $this->task->reopen();
        $this->assertTrue($this->task->fresh()->isOpen());

        $this->task->cancel();
        $this->expectException(LogicException::class);
        $this->task->close();
    }

    public function test_the_page_says_what_closing_billed_and_what_reopening_undoes(): void
    {
        $user = User::factory()->create();
        $this->log(90);

        $this->actingAs($user)
            ->post("/tasks/{$this->task->id}/close")
            ->assertRedirect(route('tasks.show', $this->task))
            ->assertSessionHas('success', 'Build the order form closed, and its 1h 30m ($180.00) added to the open invoice for Wattle Creek Bakery.');

        $this->actingAs($user)
            ->get("/tasks/{$this->task->id}")
            ->assertSee('Billed')
            ->assertSee('Re-opening it takes its line off the open invoice.')
            ->assertSee('action="'.route('tasks.reopen', $this->task).'"', false);

        $this->actingAs($user)
            ->post("/tasks/{$this->task->id}/reopen")
            ->assertSessionHas('success', 'Build the order form re-opened, and its line taken off the open invoice.');
        $this->assertSame(0, InvoiceLine::count());

        $quiet = Task::factory()->for($this->client)->create(['subject' => 'Quick question']);

        $this->actingAs($user)
            ->post("/tasks/{$quiet->id}/close")
            ->assertSessionHas('success', 'Quick question closed. It has no billable time, so nothing was billed.');
        $this->actingAs($user)->get("/tasks/{$quiet->id}")->assertDontSee('takes its line off');
        $this->actingAs($user)->post("/tasks/{$quiet->id}/reopen")->assertSessionHas('success', 'Quick question re-opened.');

        $this->actingAs($user)->post("/tasks/{$this->task->id}/close");
        Invoice::sole()->forceFill(['number' => 1001, 'issued_on' => '2026-06-15', 'total' => 18000])->save();

        $this->actingAs($user)
            ->get("/tasks/{$this->task->id}")
            ->assertSee('Its time is on an issued invoice, so it cannot be re-opened.')
            ->assertDontSee('action="'.route('tasks.reopen', $this->task).'"', false);

        $this->actingAs($user)
            ->post("/tasks/{$this->task->id}/reopen")
            ->assertSessionHas('error', 'Build the order form is on an issued invoice, so it cannot be re-opened.');
    }
}
