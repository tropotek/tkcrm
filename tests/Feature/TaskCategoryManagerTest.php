<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCategoryManagerTest extends TestCase
{
    use RefreshDatabase;

    private const DIALOG = ['HX-Request' => 'true', 'HX-Target' => 'app-modal-content'];

    private const SAVED = ['modal-close' => true, 'categories-changed' => true];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $category = TaskCategory::factory()->create();

        $this->get('/task-categories')->assertRedirect(route('login'));
        $this->get('/task-categories/create')->assertRedirect(route('login'));
        $this->post('/task-categories', ['name' => 'Nope'])->assertRedirect(route('login'));
        $this->put("/task-categories/{$category->id}", ['name' => 'Nope'])->assertRedirect(route('login'));
        $this->delete("/task-categories/{$category->id}")->assertRedirect(route('login'));

        $this->assertSame(1, TaskCategory::count());
    }

    public function test_the_page_lists_every_category_in_order_with_how_many_tasks_it_has(): void
    {
        $support = TaskCategory::factory()->create(['name' => 'Support', 'description' => 'Fixes and questions', 'position' => 1]);
        TaskCategory::factory()->inactive()->create(['name' => 'Retired']);
        TaskCategory::factory()->create(['name' => 'Administration', 'position' => 2]);
        Task::factory()->count(2)->for($support, 'category')->create();
        Task::factory()->for($support, 'category')->closed()->create();

        $this->actingAs($this->user)
            ->get('/task-categories')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Task categories</h4>', false)
            ->assertSeeInOrder(['Support', 'Fixes and questions', route('tasks.index', ['category' => $support->id, 'show' => 'all']), '>3</a>'], false)
            ->assertSeeInOrder(['Retired', 'Inactive', 'Support', 'Administration'])
            ->assertSeeInOrder(['<li class="menuitem-active">', 'href="'.route('tasks.index').'" class="active"', 'Tasks'], false);
    }

    public function test_htmx_gets_the_table_alone_after_a_save(): void
    {
        TaskCategory::factory()->create(['name' => 'Support']);

        $this->actingAs($this->user)
            ->get('/task-categories', ['HX-Request' => 'true', 'HX-Target' => 'category-table'])
            ->assertOk()
            ->assertSee('<div id="category-table"', false)
            ->assertSee('Support')
            ->assertDontSee('<html', false);
    }

    public function test_the_add_form_opens_in_the_dialog_and_saves(): void
    {
        $this->actingAs($this->user)
            ->get('/task-categories/create', self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Add category</h5>', false)
            ->assertSee('<form hx-post="'.route('task-categories.store').'" hx-target="#app-modal-content"', false)
            ->assertSee('name="active" value="1" class="form-check-input" checked', false)
            ->assertDontSee('<html', false);

        $response = $this->actingAs($this->user)
            ->post('/task-categories', ['name' => 'Support', 'description' => 'Fixes and questions', 'position' => '3', 'active' => '1'], self::DIALOG);

        $response->assertNoContent();
        $this->assertSame(self::SAVED, json_decode($response->headers->get('HX-Trigger'), true));

        $category = TaskCategory::sole();
        $this->assertSame(['Support', 'Fixes and questions', 3, true], [$category->name, $category->description, $category->position, $category->active]);
    }

    public function test_the_edit_form_opens_in_the_dialog_and_saves(): void
    {
        $category = TaskCategory::factory()->create(['name' => 'Support', 'description' => 'Old', 'position' => 4]);

        $this->actingAs($this->user)
            ->get("/task-categories/{$category->id}/edit", self::DIALOG)
            ->assertOk()
            ->assertSee('<h5 class="modal-title">Edit category</h5>', false)
            ->assertSee('<form hx-put="'.route('task-categories.update', $category).'"', false)
            ->assertSee('value="Support"', false)
            ->assertSee('name="position" value="4"', false)
            ->assertSee('>Old</textarea>', false);

        $response = $this->actingAs($this->user)
            ->put("/task-categories/{$category->id}", ['name' => 'Web hosting', 'description' => ''], self::DIALOG);

        $response->assertNoContent();
        $this->assertSame(self::SAVED, json_decode($response->headers->get('HX-Trigger'), true));

        $category->refresh();
        $this->assertSame(['Web hosting', null, 0, false], [$category->name, $category->description, $category->position, $category->active]);
    }

    public function test_the_dialog_comes_back_with_its_errors_and_what_was_typed(): void
    {
        $category = TaskCategory::factory()->create(['name' => 'Support', 'description' => 'Old']);

        $this->actingAs($this->user)
            ->put("/task-categories/{$category->id}", ['name' => '', 'description' => 'Typed <b>', 'position' => '-1'], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Edit category</h5>', false)
            ->assertSee('<form hx-put="'.route('task-categories.update', $category).'"', false)
            ->assertSee(__('validation.required', ['attribute' => 'name']))
            ->assertSee('>Typed &lt;b&gt;</textarea>', false)
            ->assertSee('name="position" value="-1"', false)
            ->assertDontSee('value="Support"', false)
            ->assertDontSee('checked', false);

        $this->actingAs($this->user)
            ->post('/task-categories', ['name' => ''], self::DIALOG)
            ->assertStatus(422)
            ->assertSee('<h5 class="modal-title">Add category</h5>', false);

        $this->assertSame('Support', $category->fresh()->name);
        $this->assertSame(1, TaskCategory::count());
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = TaskCategory::factory()->create(['name' => 'Mistake']);

        $this->actingAs($this->user)
            ->get('/task-categories')
            ->assertSee('action="'.route('task-categories.destroy', $category).'"', false);

        $this->actingAs($this->user)
            ->delete("/task-categories/{$category->id}")
            ->assertRedirect(route('task-categories.index'))
            ->assertSessionHas('success', 'Mistake deleted.');

        $this->assertSame(0, TaskCategory::count());
    }

    public function test_a_category_with_tasks_cannot_be_deleted(): void
    {
        $category = TaskCategory::factory()->create(['name' => 'Support']);
        Task::factory()->for($category, 'category')->cancelled()->create();

        $this->actingAs($this->user)
            ->get('/task-categories')
            ->assertDontSee('action="'.route('task-categories.destroy', $category).'"', false);

        $this->actingAs($this->user)
            ->delete("/task-categories/{$category->id}")
            ->assertRedirect(route('task-categories.index'))
            ->assertSessionHas('error', 'Support has tasks in it, so it cannot be deleted. Make it inactive instead.');

        $this->assertSame(1, TaskCategory::count());
    }
}
