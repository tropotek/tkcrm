<?php

namespace Tests\Feature;

use App\Enums\Cycle;
use App\Models\Company;
use App\Models\Product;
use App\Models\Recurring;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;
use ValueError;

class RecurringSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_has_the_expected_columns_and_no_issue_switch(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'company_id', 'product_id', 'price', 'cycle', 'start_on', 'end_on', 'previous_on',
                'next_on', 'count', 'active', 'description', 'notes', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('recurrings'),
        );
    }

    public function test_a_new_item_is_active_unbilled_and_first_due_on_its_start_day(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->cycle(Cycle::Month)->create();

        $recurring = Recurring::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'cycle' => Cycle::Month,
            'start_on' => '2026-01-31',
        ]);

        foreach ([$recurring, $recurring->fresh()] as $read) {
            $this->assertTrue($read->active);
            $this->assertSame(0, $read->count);
            $this->assertNull($read->price);
            $this->assertNull($read->end_on);
            $this->assertNull($read->previous_on);
            $this->assertSame('2026-01-31', $read->next_on->toDateString());
        }
    }

    public function test_the_column_defaults_match(): void
    {
        $company = Company::factory()->create();
        DB::table('recurrings')->insert(['company_id' => $company->id, 'start_on' => '2026-07-01', 'next_on' => '2026-07-01']);

        $recurring = Recurring::sole();
        $this->assertSame(Cycle::Year, $recurring->cycle);
        $this->assertSame(0, $recurring->count);
        $this->assertTrue($recurring->active);
        $this->assertNull($recurring->price);
        $this->assertNull($recurring->product_id);
    }

    public function test_the_dates_are_days_that_do_not_shift_with_a_timezone(): void
    {
        $recurring = Recurring::factory()->create([
            'start_on' => '2026-01-31',
            'end_on' => '2027-01-31',
            'previous_on' => '2026-02-28',
            'next_on' => '2026-03-31',
        ])->fresh();

        foreach (['start_on' => '2026-01-31', 'end_on' => '2027-01-31', 'previous_on' => '2026-02-28', 'next_on' => '2026-03-31'] as $column => $day) {
            $this->assertInstanceOf(CarbonImmutable::class, $recurring->$column);
            $this->assertSame($day, $recurring->$column->toDateString());
        }

        $this->assertSame(1, Recurring::whereDate('next_on', '<=', '2026-03-31')->count());
        $this->assertSame(0, Recurring::whereDate('next_on', '<=', '2026-03-30')->count());
    }

    public function test_what_the_run_keeps_cannot_be_set_from_a_form(): void
    {
        $recurring = Recurring::factory()->create(['start_on' => '2026-01-31']);

        $recurring->update(['count' => 5, 'previous_on' => '2026-02-28', 'next_on' => '2030-01-01']);

        $recurring->refresh();
        $this->assertSame(0, $recurring->count);
        $this->assertNull($recurring->previous_on);
        $this->assertSame('2026-01-31', $recurring->next_on->toDateString());
    }

    public function test_an_empty_price_follows_the_products_price(): void
    {
        $product = Product::factory()->create(['price' => 33000]);
        $recurring = Recurring::factory()->for($product)->create();

        $this->assertNull(DB::table('recurrings')->value('price'));
        $this->assertTrue($recurring->followsProductPrice());
        $this->assertSame('$330.00', $recurring->billedPrice()->format());

        $product->update(['price' => 35000]);

        $this->assertSame('$350.00', $recurring->fresh()->billedPrice()->format());
    }

    public function test_a_price_of_its_own_is_kept_in_cents_and_wins_over_the_products(): void
    {
        $product = Product::factory()->create(['price' => 33000]);
        $recurring = Recurring::factory()->for($product)->create(['price' => Money::parse('$275.50')]);

        $this->assertSame(27550, DB::table('recurrings')->value('price'));
        $this->assertFalse($recurring->fresh()->followsProductPrice());
        $this->assertSame('$275.50', $recurring->fresh()->billedPrice()->format());

        $product->update(['price' => 99900]);

        $this->assertSame('$275.50', $recurring->fresh()->billedPrice()->format());
    }

    public function test_a_price_of_zero_is_a_price_not_an_empty_one(): void
    {
        $recurring = Recurring::factory()->for(Product::factory()->create(['price' => 33000]))->create(['price' => 0]);

        $this->assertFalse($recurring->fresh()->followsProductPrice());
        $this->assertTrue($recurring->fresh()->billedPrice()->isZero());
    }

    public function test_an_item_with_no_product_bills_its_own_price(): void
    {
        $recurring = Recurring::factory()->withoutProduct()->create(['price' => 12000, 'description' => 'Site care plan']);

        $this->assertNull($recurring->fresh()->product);
        $this->assertFalse($recurring->fresh()->followsProductPrice());
        $this->assertSame('$120.00', $recurring->fresh()->billedPrice()->format());
    }

    public function test_an_item_needs_a_product_or_a_price_of_its_own(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A recurring item needs a product or a price of its own.');

        Recurring::factory()->withoutProduct()->create(['price' => null]);
    }

    public function test_the_cycle_is_any_but_each(): void
    {
        $this->assertSame(
            ['week', 'fortnight', 'month', 'quarter', 'year', 'biannual'],
            array_column(Cycle::recurring(), 'value'),
        );

        foreach (Cycle::recurring() as $cycle) {
            $recurring = Recurring::factory()->cycle($cycle)->create();

            $this->assertDatabaseHas('recurrings', ['id' => $recurring->id, 'cycle' => $cycle->value]);
            $this->assertSame($cycle, $recurring->fresh()->cycle);
        }
    }

    public function test_a_one_off_cycle_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A recurring item cannot have the cycle "each".');

        Recurring::factory()->cycle(Cycle::Each)->create();
    }

    public function test_a_cycle_that_is_not_one_of_them_is_refused(): void
    {
        $this->expectException(ValueError::class);

        Recurring::factory()->create(['cycle' => 'daily']);
    }

    public function test_it_belongs_to_a_company_and_a_product_which_list_it(): void
    {
        $company = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $product = Product::factory()->create(['code' => 'HOST-STD']);
        $recurring = Recurring::factory()->for($company)->for($product)->create();
        Recurring::factory()->create();

        $this->assertSame('Wattle Creek Bakery', $recurring->fresh()->company->name);
        $this->assertSame('HOST-STD', $recurring->fresh()->product->code);
        $this->assertSame([$recurring->id], $company->recurrings()->pluck('id')->all());
        $this->assertSame([$recurring->id], $product->recurrings()->pluck('id')->all());
    }

    public function test_a_product_with_a_recurring_item_is_in_use(): void
    {
        $product = Product::factory()->create();
        $this->assertFalse($product->isInUse());

        Recurring::factory()->for($product)->inactive()->create();

        $this->assertTrue($product->isInUse());
    }

    public function test_a_company_or_product_with_a_recurring_item_cannot_be_deleted(): void
    {
        $recurring = Recurring::factory()->create();

        foreach ([$recurring->company, $recurring->product] as $record) {
            try {
                $record->delete();
                $this->fail('The '.class_basename($record).' was deleted.');
            } catch (QueryException) {
                $this->assertModelExists($record);
            }
        }

        $this->assertSame(1, Recurring::count());
    }

    public function test_it_can_be_narrowed_to_active_ones(): void
    {
        $recurring = Recurring::factory()->create();
        Recurring::factory()->inactive()->create();

        $this->assertSame([$recurring->id], Recurring::active()->pluck('id')->all());
    }

    public function test_its_dates_come_from_its_start_and_cycle(): void
    {
        $recurring = Recurring::factory()->cycle(Cycle::Month)->create(['start_on' => '2026-01-31'])->fresh();

        $this->assertSame('2026-01-31', $recurring->dates()->date(0)->toDateString());
        $this->assertSame('2026-02-28', $recurring->dates()->date(1)->toDateString());
        $this->assertSame('28 Feb 2026 to 30 Mar 2026', $recurring->dates()->period(1)->label());
    }

    public function test_an_invoice_line_can_name_it_as_its_source(): void
    {
        $this->assertSame('recurring', (new Recurring)->getMorphClass());
        $this->assertSame(Recurring::class, Relation::getMorphedModel('recurring'));
    }

    public function test_an_id_can_be_given_so_an_imported_item_keeps_its_own(): void
    {
        Recurring::factory()->create(['id' => 40]);

        $this->assertSame(41, Recurring::factory()->create()->id);
    }
}
