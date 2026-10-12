<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class ExpenseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tables_have_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'description', 'claim_ratio', 'active', 'created_at', 'updated_at'],
            Schema::getColumnListing('expense_categories'),
        );

        $this->assertEqualsCanonicalizing(
            [
                'id', 'expense_category_id', 'company_id', 'invoice_number', 'receipt_number', 'description',
                'purchased_on', 'total', 'gst_included', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('expenses'),
        );
    }

    public function test_a_new_category_is_active_and_claimed_in_full(): void
    {
        $category = ExpenseCategory::create(['name' => 'Hosting']);

        foreach ([$category, $category->fresh()] as $read) {
            $this->assertSame(10000, $read->claim_ratio);
            $this->assertTrue($read->active);
        }

        DB::table('expense_categories')->insert(['name' => 'Software']);
        $this->assertSame([10000, true], [ExpenseCategory::where('name', 'Software')->sole()->claim_ratio, ExpenseCategory::where('name', 'Software')->sole()->active]);
    }

    public function test_the_claim_ratio_is_basis_points_from_nothing_to_all_of_it(): void
    {
        $this->assertSame(5000, ExpenseCategory::factory()->claiming(5000)->create()->fresh()->claim_ratio);
        $this->assertSame(0, ExpenseCategory::factory()->claiming(0)->create()->fresh()->claim_ratio);
        $this->assertSame(10000, ExpenseCategory::factory()->claiming(10000)->create()->fresh()->claim_ratio);

        foreach ([-1, 10001] as $ratio) {
            try {
                ExpenseCategory::factory()->claiming($ratio)->create();
                $this->fail("A claim ratio of {$ratio} was saved.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('A claim ratio is between 0 and 10000 basis points.', $e->getMessage());
            }
        }
    }

    public function test_an_expense_has_a_supplier_its_numbers_a_day_and_a_total_with_the_gst_in_it(): void
    {
        $supplier = Company::factory()->supplier()->create(['name' => 'Bluegum Hosting']);
        $category = ExpenseCategory::factory()->create(['name' => 'Hosting']);

        $expense = Expense::create([
            'expense_category_id' => $category->id,
            'company_id' => $supplier->id,
            'invoice_number' => 'BG-20417',
            'receipt_number' => 'R-88',
            'description' => 'Server, one year',
            'purchased_on' => '2026-06-30',
            'total' => Money::parse('$1,320.00'),
            'gst_included' => Money::parse('$120.00'),
        ])->fresh();

        $this->assertSame([132000, 12000], [DB::table('expenses')->value('total'), DB::table('expenses')->value('gst_included')]);
        $this->assertSame(['$1,320.00', '$120.00', '$1,200.00'], [$expense->total->format(), $expense->gst_included->format(), $expense->net()->format()]);
        $this->assertSame(['BG-20417', 'R-88', 'Server, one year'], [$expense->invoice_number, $expense->receipt_number, $expense->description]);
        $this->assertSame('Bluegum Hosting', $expense->company->name);
        $this->assertSame('Hosting', $expense->category->name);
        $this->assertSame([$expense->id], $supplier->expenses()->pluck('id')->all());
        $this->assertSame([$expense->id], $category->expenses()->pluck('id')->all());
    }

    public function test_only_what_an_expense_needs_is_needed(): void
    {
        $expense = Expense::create([
            'expense_category_id' => ExpenseCategory::factory()->create()->id,
            'company_id' => Company::factory()->supplier()->create()->id,
            'purchased_on' => '2026-07-01',
            'total' => 5000,
        ]);

        foreach ([$expense, $expense->fresh()] as $read) {
            $this->assertTrue($read->gst_included->isZero());
            $this->assertNull($read->invoice_number);
            $this->assertNull($read->receipt_number);
            $this->assertSame('$50.00', $read->net()->format());
        }
    }

    public function test_the_purchase_day_is_a_day_that_does_not_shift_with_a_timezone(): void
    {
        $expense = Expense::factory()->create(['purchased_on' => '2026-06-30'])->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $expense->purchased_on);
        $this->assertSame('2026-06-30', $expense->purchased_on->toDateString());
        $this->assertSame(1, Expense::whereDate('purchased_on', '<=', '2026-06-30')->count());
        $this->assertSame(0, Expense::whereDate('purchased_on', '>=', '2026-07-01')->count());
    }

    public function test_what_can_be_claimed_is_the_categorys_share_of_the_amount_without_gst(): void
    {
        $half = ExpenseCategory::factory()->claiming(5000)->create();
        $third = ExpenseCategory::factory()->claiming(3333)->create();

        // No GST: half of $99.99 is $49.995, which rounds half up.
        $this->assertSame('$50.00', Expense::factory()->for($half, 'category')->create(['total' => 9999])->claimable()->format());
        // With GST in it, the share is of what is left: half of $1,200.00.
        $this->assertSame('$600.00', Expense::factory()->for($half, 'category')->create(['total' => 132000, 'gst_included' => 12000])->claimable()->format());
        $this->assertSame('$33.33', Expense::factory()->for($third, 'category')->create(['total' => 10000])->claimable()->format());
        $this->assertSame('$100.00', Expense::factory()->create(['total' => 10000])->claimable()->format());
        $this->assertTrue(Expense::factory()->for(ExpenseCategory::factory()->claiming(0)->create(), 'category')->create()->claimable()->isZero());
    }

    public function test_a_change_to_the_ratio_changes_what_its_expenses_claim(): void
    {
        $category = ExpenseCategory::factory()->create();
        $expense = Expense::factory()->for($category, 'category')->create(['total' => 10000]);

        $category->update(['claim_ratio' => 2500]);

        $this->assertSame('$25.00', $expense->fresh()->claimable()->format());
    }

    public function test_a_refund_is_a_negative_expense(): void
    {
        $refund = Expense::factory()->create(['total' => -11000, 'gst_included' => -1000])->fresh();

        $this->assertSame(['-$110.00', '-$100.00', '-$100.00'], [$refund->total->format(), $refund->net()->format(), $refund->claimable()->format()]);
    }

    public function test_the_gst_in_an_expense_cannot_be_more_than_it_or_of_the_other_sign(): void
    {
        foreach ([[10000, 10001], [10000, -1], [-10000, 1], [0, 1]] as [$total, $gst]) {
            try {
                Expense::factory()->create(['total' => $total, 'gst_included' => $gst]);
                $this->fail("GST of {$gst} in {$total} was saved.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('The GST included in an expense cannot be more than its total.', $e->getMessage());
            }
        }

        $this->assertSame(0, Expense::count());
    }

    public function test_a_float_is_refused_as_an_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Expense::factory()->create(['total' => 12.5]);
    }

    public function test_a_category_or_supplier_with_expenses_cannot_be_deleted(): void
    {
        $expense = Expense::factory()->create();

        foreach ([$expense->category, $expense->company] as $record) {
            try {
                $record->delete();
                $this->fail('The '.class_basename($record).' was deleted.');
            } catch (QueryException) {
                $this->assertModelExists($record);
            }
        }
    }

    public function test_categories_can_be_narrowed_to_active_ones(): void
    {
        $category = ExpenseCategory::factory()->create();
        ExpenseCategory::factory()->inactive()->create();

        $this->assertSame([$category->id], ExpenseCategory::active()->pluck('id')->all());
    }

    public function test_an_expense_can_have_receipts_attached(): void
    {
        $expense = Expense::factory()->create();

        $this->assertSame('expense', $expense->getMorphClass());
        $this->assertSame(Expense::class, Relation::getMorphedModel('expense'));
        $this->assertTrue($expense->canChangeAttachments());
        $this->assertSame(0, $expense->attachments()->count());
    }

    public function test_ids_can_be_given_so_imported_rows_keep_theirs(): void
    {
        $category = ExpenseCategory::factory()->create(['id' => 12]);
        Expense::factory()->for($category, 'category')->create(['id' => 40]);

        // The expense made next brings a category of its own, which is 13.
        $this->assertSame(41, Expense::factory()->create()->id);
        $this->assertSame(14, ExpenseCategory::factory()->create()->id);
    }
}
