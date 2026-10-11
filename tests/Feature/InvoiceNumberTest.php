<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Invoices\InvoiceNumber;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class InvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    private function allocate(): int
    {
        return DB::transaction(fn () => InvoiceNumber::allocate());
    }

    public function test_the_counter_is_a_setting_that_starts_at_one_and_moves_on_with_each_number(): void
    {
        $this->assertSame(1, Settings::get('next_invoice_number'));
        $this->assertSame(1, InvoiceNumber::next());

        $this->assertSame([1, 2, 3], [$this->allocate(), $this->allocate(), $this->allocate()]);

        $this->assertSame(4, Settings::get('next_invoice_number'));
        $this->assertSame(4, InvoiceNumber::next());
        $this->assertSame('4', DB::table('settings')->where('key', 'next_invoice_number')->value('value'));
    }

    public function test_a_number_is_only_allocated_inside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        // RefreshDatabase wraps the test in one transaction of its own; a second level is the issuing one.
        DB::rollBack();
        InvoiceNumber::allocate();
    }

    public function test_a_number_goes_back_when_the_issuing_transaction_fails(): void
    {
        Settings::set('next_invoice_number', 1001);

        try {
            DB::transaction(function () {
                $this->assertSame(1001, InvoiceNumber::allocate());

                throw new RuntimeException('The invoice could not be issued.');
            });
        } catch (RuntimeException) {
            // The number was not used.
        }

        Settings::refresh();
        $this->assertSame(1001, Settings::get('next_invoice_number'));
        $this->assertSame(1001, $this->allocate());
    }

    public function test_the_number_comes_from_the_database_not_from_settings_read_earlier_in_the_request(): void
    {
        Settings::set('next_invoice_number', 1001);
        $this->assertSame(1001, Settings::get('next_invoice_number'));

        // Another request issues an invoice while this one still holds the settings it read.
        DB::table('settings')->where('key', 'next_invoice_number')->update(['value' => '1002']);

        $this->assertSame(1002, $this->allocate());
        $this->assertSame(1003, Settings::get('next_invoice_number'));
    }

    public function test_a_number_already_on_an_invoice_is_never_given_out_again(): void
    {
        Invoice::factory()->create()->forceFill(['number' => 1500, 'issued_on' => '2026-06-01'])->save();
        Settings::set('next_invoice_number', 1200);

        $this->assertSame(1500, InvoiceNumber::highestUsed());
        $this->assertSame(1501, InvoiceNumber::next());
        $this->assertSame(1501, $this->allocate());
        $this->assertSame(1502, Settings::get('next_invoice_number'));
    }

    public function test_the_importer_can_start_the_counter_above_the_highest_number_it_brought_in(): void
    {
        InvoiceNumber::startAbove(2480);
        $this->assertSame(2481, Settings::get('next_invoice_number'));
        $this->assertSame(2481, $this->allocate());

        // It never moves the counter back.
        InvoiceNumber::startAbove(100);
        $this->assertSame(2482, $this->allocate());
    }

    public function test_the_settings_page_can_raise_the_counter_but_not_put_it_back_over_a_number_in_use(): void
    {
        $user = User::factory()->create();
        $form = ['site_name' => 'tkcrm', 'overdue_days' => '14', 'tax_estimate_rate' => '20', 'gst_rate' => '10'];
        Invoice::factory()->create()->forceFill(['number' => 1500, 'issued_on' => '2026-06-01'])->save();
        Settings::set('next_invoice_number', 1501);
        $this->app->forgetScopedInstances();

        $this->actingAs($user)->get('/settings')->assertSee('name="next_invoice_number" value="1501"', false);

        $this->actingAs($user)->put('/settings', $form + ['next_invoice_number' => '2000'])->assertSessionHasNoErrors();
        $this->app->forgetScopedInstances();
        $this->assertSame(2000, Settings::get('next_invoice_number'));

        foreach (['1500', '1', '0', '-5', '12.5', 'abc', ''] as $bad) {
            $this->actingAs($user)->put('/settings', $form + ['next_invoice_number' => $bad])->assertSessionHasErrors('next_invoice_number');
        }

        $this->actingAs($user)
            ->from('/settings')
            ->put('/settings', $form + ['next_invoice_number' => '1500'])
            ->assertSessionHasErrors(['next_invoice_number' => 'The next invoice number must be above 1500, the highest already used.']);

        // A form saved without the field, or after an invoice was issued under it, leaves the counter alone.
        $this->actingAs($user)->put('/settings', $form)->assertSessionHasNoErrors();
        $this->app->forgetScopedInstances();
        $this->assertSame(2000, Settings::get('next_invoice_number'));
    }
}
