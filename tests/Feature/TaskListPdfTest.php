<?php

namespace Tests\Feature;

use App\Invoices\TaskListPdf;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TaskListPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private Product $labour;

    protected function setUp(): void
    {
        parent::setUp();

        // Ten in the morning on 16 June in Melbourne.
        $this->travelTo('2026-06-16 00:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);

        $this->client = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $this->labour = Product::factory()->create(['price' => 12000]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string, 3?: bool}>  $logs  started (UTC), minutes, comment, billable
     */
    private function closedTask(string $subject, string $category, array $logs): Task
    {
        $task = Task::factory()->for($this->client)->for(TaskCategory::factory()->create(['name' => $category]), 'category')->create(['subject' => $subject]);

        foreach ($logs as $log) {
            TaskLog::factory()->for($task)->for($this->labour)->create(['started_at' => $log[0], 'minutes' => $log[1], 'comment' => $log[2], 'billable' => $log[3] ?? true]);
        }

        $task->close();

        return $task;
    }

    /**
     * An open invoice with two tasks' lines and one typed by hand.
     */
    private function invoice(): Invoice
    {
        $this->closedTask('Build the order form', 'Development', [
            // Nine in the morning on the 10th in Melbourne is the evening of the 9th in UTC.
            ['2026-06-09 23:00:00', 90, 'Cart and totals'],
            ['2026-06-11 01:00:00', 45, "Checkout page\nand its errors"],
            ['2026-06-11 03:00:00', 30, 'Chasing a login I broke', false],
        ]);
        $this->closedTask('Fix the opening hours', 'Support', [['2026-06-12 02:00:00', 20, 'Contact page']]);

        $invoice = $this->client->openInvoice();
        InvoiceLine::factory()->for($invoice)->create(['product_code' => 'HOST-STD', 'description' => 'Web hosting, typed by hand']);

        return $invoice;
    }

    private function text(Invoice $invoice): string
    {
        $body = preg_replace('#<(style|title).*?</\\1>#s', '', app(TaskListPdf::class)->html($invoice->fresh()));

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $body)))));
    }

    private function assertInOrder(array $parts, string $text): void
    {
        $from = 0;

        foreach ($parts as $part) {
            $at = strpos($text, $part, $from);
            $this->assertNotFalse($at, "'{$part}' is missing or out of order in: {$text}");
            $from = $at + strlen($part);
        }
    }

    public function test_it_lists_each_task_with_its_billable_logs_dates_durations_and_comments(): void
    {
        $invoice = $this->invoice();
        $text = $this->text($invoice);
        [$first, $second] = Task::orderBy('id')->get()->all();

        $this->assertInOrder([
            'Task list for a draft invoice', 'Wattle Creek Bakery', 'Account '.$this->client->account_number,
            'Build the order form', "TSK-{$first->id} · Development", '2h 15m', '$270.00',
            '10 Jun 2026', 'Cart and totals', '1h 30m',
            '11 Jun 2026', 'Checkout page', 'and its errors', '45m',
            'Fix the opening hours', "TSK-{$second->id} · Support", '20m', '$40.00',
            '12 Jun 2026', 'Contact page', '20m',
            '2 tasks', '2h 35m', '$310.00',
        ], $text);

        // Time that was not billed, a line typed by hand, and the rate are not the client's to see.
        $this->assertStringNotContainsString('Chasing a login', $text);
        $this->assertStringNotContainsString('typed by hand', $text);
        $this->assertStringNotContainsString('$120.00', $text);
    }

    public function test_an_issued_invoice_is_named_by_its_number_and_date(): void
    {
        $invoice = $this->invoice();
        $invoice->issue();
        $invoice->refresh();

        $this->assertInOrder(["Task list for invoice {$invoice->number}", 'Invoice dated 16 Jun 2026', 'Build the order form'], $this->text($invoice));
        $this->assertSame("invoice-{$invoice->number}-tasks.pdf", app(TaskListPdf::class)->filename($invoice));
        $this->assertSame("draft-invoice-{$invoice->id}-tasks.pdf", app(TaskListPdf::class)->filename(Invoice::factory()->create()->forceFill(['id' => $invoice->id])));
    }

    public function test_it_is_built_in_process_by_dompdf_and_served_as_a_pdf(): void
    {
        $invoice = $this->invoice();

        $this->assertTrue(app(TaskListPdf::class)->has($invoice));
        $this->assertStringStartsWith('%PDF-', app(TaskListPdf::class)->pdf($invoice));

        $this->get("/invoices/{$invoice->id}")->assertSee('href="'.route('invoices.task-list', $invoice).'"', false);

        $response = $this->get("/invoices/{$invoice->id}/task-list")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="draft-invoice-'.$invoice->id.'-tasks.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $this->get("/invoices/{$invoice->id}/task-list?download=1")
            ->assertHeader('Content-Disposition', 'attachment; filename="draft-invoice-'.$invoice->id.'-tasks.pdf"');
    }

    public function test_there_is_none_for_an_invoice_with_no_lines_from_tasks(): void
    {
        $typed = InvoiceLine::factory()->create()->invoice;
        $empty = Invoice::factory()->create();

        foreach ([$typed, $empty] as $invoice) {
            $this->assertFalse(app(TaskListPdf::class)->has($invoice));
            $this->get("/invoices/{$invoice->id}/task-list")->assertNotFound();
            $this->get("/invoices/{$invoice->id}")->assertDontSee(route('invoices.task-list', $invoice), false);
        }

        $this->expectException(LogicException::class);
        app(TaskListPdf::class)->html($typed);
    }

    public function test_what_was_typed_cannot_put_markup_in_the_document(): void
    {
        $this->closedTask('<b>Bold</b> subject', 'Support', [['2026-06-12 02:00:00', 20, '<script>alert(1)</script>']]);

        $html = app(TaskListPdf::class)->html($this->client->openInvoice());

        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; subject', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_a_guest_does_not_get_it(): void
    {
        $invoice = $this->invoice();
        auth()->logout();

        $this->get("/invoices/{$invoice->id}/task-list")->assertRedirect(route('login'));
    }
}
