<?php

namespace Tests\Feature;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyCastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('priced_things', function (Blueprint $table) {
            $table->id();
            $table->integer('price');
            $table->integer('override')->nullable();
        });
    }

    public function test_money_is_stored_as_an_integer_of_cents_and_read_back_as_money(): void
    {
        $thing = PricedThing::create(['price' => Money::parse('$1,234.50')]);

        $this->assertSame(123450, DB::table('priced_things')->value('price'));
        $this->assertSame('integer', gettype(DB::table('priced_things')->value('price')));

        $read = PricedThing::sole();
        $this->assertInstanceOf(Money::class, $read->price);
        $this->assertTrue($read->price->equals(Money::cents(123450)));
        $this->assertSame($thing->id, $read->id);
    }

    public function test_a_whole_number_of_cents_can_be_set(): void
    {
        PricedThing::create(['price' => 995]);

        $this->assertSame('$9.95', PricedThing::sole()->price->format());
    }

    public function test_a_null_column_is_null(): void
    {
        $thing = PricedThing::create(['price' => Money::zero(), 'override' => null]);

        $this->assertNull($thing->fresh()->override);
        $this->assertTrue($thing->fresh()->price->isZero());

        $thing->update(['override' => Money::cents(-250)]);
        $this->assertSame(-250, $thing->fresh()->override->inCents());
    }

    public function test_a_string_or_a_float_cannot_be_set(): void
    {
        foreach (['12.50', '1250', 12.5, true] as $value) {
            try {
                new PricedThing(['price' => $value]);
                $this->fail('Set '.var_export($value, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('price takes Money or a whole number of cents', $e->getMessage());
            }
        }
    }

    public function test_a_model_with_money_serialises_it_as_a_decimal_string(): void
    {
        $thing = PricedThing::create(['price' => Money::cents(123450)]);

        $this->assertSame('1234.50', $thing->fresh()->toArray()['price']->toDecimal());
        $this->assertStringContainsString('"price":"1234.50"', $thing->fresh()->toJson());
    }

    public function test_a_column_of_money_can_be_summed_and_compared_in_a_query(): void
    {
        PricedThing::create(['price' => Money::cents(1000)]);
        PricedThing::create(['price' => Money::cents(250)]);

        $this->assertSame(1250, (int) PricedThing::sum('price'));
        $this->assertSame(1, PricedThing::where('price', '>', Money::cents(500)->inCents())->count());
    }
}

class PricedThing extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => MoneyCast::class, 'override' => MoneyCast::class];
    }
}
