<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_has_the_expected_columns_and_no_status_or_lead(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'company_id', 'name', 'quote', 'start_on', 'end_on', 'cancelled_on', 'description', 'notes', 'created_at', 'updated_at'],
            Schema::getColumnListing('projects'),
        );
    }

    public function test_there_is_no_member_table(): void
    {
        foreach (['project_user', 'project_users', 'project_members', 'project_member'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }

    public function test_a_project_belongs_to_a_company_which_lists_its_projects(): void
    {
        $company = Company::factory()->create(['name' => 'Wattle Creek Bakery']);
        $project = Project::factory()->for($company)->create();
        Project::factory()->create();

        $this->assertSame('Wattle Creek Bakery', $project->fresh()->company->name);
        $this->assertSame([$project->id], $company->projects()->pluck('id')->all());
    }

    public function test_a_company_with_a_project_cannot_be_deleted(): void
    {
        $project = Project::factory()->create();

        $this->expectException(QueryException::class);

        $project->company->delete();
    }

    public function test_the_quote_is_stored_in_cents_and_read_as_money(): void
    {
        $project = Project::factory()->create(['quote' => Money::parse('$12,500')]);

        $this->assertSame(1250000, DB::table('projects')->value('quote'));
        $this->assertSame('$12,500.00', $project->fresh()->quote->format());
        $this->assertTrue(Project::create(['company_id' => $project->company_id, 'name' => 'No quote'])->fresh()->quote->isZero());
    }

    public function test_the_dates_are_days_with_no_time_and_may_be_empty(): void
    {
        $project = Project::factory()->create(['start_on' => '2026-05-01', 'end_on' => CarbonImmutable::parse('2026-07-31 18:30:00')]);
        $read = $project->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $read->start_on);
        $this->assertSame('2026-05-01', $read->start_on->toDateString());
        $this->assertSame('2026-07-31 00:00:00', $read->end_on->toDateTimeString());
        $this->assertNull($read->cancelled_on);
    }

    /**
     * Today is 15 June 2026 in each.
     *
     * @return array<string, array{?string, ?string, ?string, ProjectStatus}>
     */
    public static function dates(): array
    {
        return [
            'no dates' => [null, null, null, ProjectStatus::Pending],
            'starts tomorrow' => ['2026-06-16', null, null, ProjectStatus::Pending],
            'starts later, with an end' => ['2026-07-01', '2026-08-01', null, ProjectStatus::Pending],
            'an end date but no start' => [null, '2026-08-01', null, ProjectStatus::Pending],
            'starts today' => ['2026-06-15', null, null, ProjectStatus::Active],
            'started, no end' => ['2026-01-10', null, null, ProjectStatus::Active],
            'started, ends later' => ['2026-01-10', '2026-12-31', null, ProjectStatus::Active],
            'ends today' => ['2026-01-10', '2026-06-15', null, ProjectStatus::Active],
            'one day long, today' => ['2026-06-15', '2026-06-15', null, ProjectStatus::Active],
            'ended yesterday' => ['2026-01-10', '2026-06-14', null, ProjectStatus::Completed],
            'ended, with no start' => [null, '2026-03-01', null, ProjectStatus::Completed],
            'cancelled while active' => ['2026-01-10', '2026-12-31', '2026-06-01', ProjectStatus::Cancelled],
            'cancelled before it started' => ['2026-07-01', null, '2026-06-10', ProjectStatus::Cancelled],
            'cancelled after it ended' => ['2026-01-10', '2026-03-01', '2026-04-01', ProjectStatus::Cancelled],
            'cancelled with no other dates' => [null, null, '2026-06-15', ProjectStatus::Cancelled],
            'cancelled on a day still to come' => ['2026-01-10', null, '2026-07-01', ProjectStatus::Cancelled],
        ];
    }

    #[DataProvider('dates')]
    public function test_the_status_is_derived_from_the_dates(?string $start, ?string $end, ?string $cancelled, ProjectStatus $expected): void
    {
        $today = CarbonImmutable::parse('2026-06-15');
        $project = Project::factory()->create(['start_on' => $start, 'end_on' => $end, 'cancelled_on' => $cancelled])->fresh();

        $this->assertSame($expected, $project->status($today));

        // The query scope agrees with the model for every status.
        foreach (ProjectStatus::cases() as $status) {
            $this->assertSame(
                $status === $expected ? 1 : 0,
                Project::withStatus($status, $today)->count(),
                "withStatus({$status->value})",
            );
        }
    }

    public function test_the_status_changes_as_the_days_pass(): void
    {
        $project = Project::factory()->create(['start_on' => '2026-06-10', 'end_on' => '2026-06-20']);

        $statuses = array_map(
            fn (string $day) => $project->status(CarbonImmutable::parse($day))->value,
            ['2026-06-09', '2026-06-10', '2026-06-20', '2026-06-21'],
        );

        $this->assertSame(['pending', 'active', 'active', 'completed'], $statuses);
    }

    public function test_today_is_the_signed_in_users_day_not_utcs(): void
    {
        $project = Project::factory()->create(['start_on' => '2026-06-16']);

        // 15 June, 11pm in UTC: already the 16th in Melbourne, still the 15th in Honolulu.
        $this->travelTo('2026-06-15 23:00:00');

        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Melbourne']));
        $this->assertSame(ProjectStatus::Active, $project->status());
        $this->assertSame(1, Project::withStatus(ProjectStatus::Active)->count());

        $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Honolulu']));
        $this->assertSame(ProjectStatus::Pending, $project->status());
        $this->assertSame(1, Project::withStatus(ProjectStatus::Pending)->count());
    }

    public function test_each_status_has_a_label_and_a_colour(): void
    {
        $this->assertSame(
            ['Pending' => 'primary', 'Active' => 'success', 'Completed' => 'info', 'Cancelled' => 'danger'],
            collect(ProjectStatus::cases())->mapWithKeys(fn (ProjectStatus $status) => [$status->label() => $status->colour()])->all(),
        );
    }

    public function test_the_factory_makes_a_project_of_each_status(): void
    {
        $this->assertSame(ProjectStatus::Pending, Project::factory()->create()->status());
        $this->assertSame(ProjectStatus::Active, Project::factory()->active()->create()->status());
        $this->assertSame(ProjectStatus::Completed, Project::factory()->completed()->create()->status());
        $this->assertSame(ProjectStatus::Cancelled, Project::factory()->cancelled()->create()->status());
    }

    public function test_an_id_can_be_given_so_an_imported_project_keeps_its_own(): void
    {
        Project::factory()->create(['id' => 58]);

        $this->assertSame(59, Project::factory()->create()->id);
    }
}
