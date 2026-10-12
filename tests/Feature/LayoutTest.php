<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_app_layout_shows_the_shell_around_the_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Dashboard | '.config('app.name').'</title>', false)
            ->assertSee('class="navbar-custom"', false)
            ->assertSee('class="left-side-menu"', false)
            ->assertSee('<footer class="footer">', false)
            ->assertSee('<h4 class="page-title">Dashboard</h4>', false);
    }

    public function test_the_sidebar_marks_the_current_page(): void
    {
        $this->get('/')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'class="active"', 'Dashboard'], false);
    }

    public function test_a_screen_that_is_not_built_yet_is_listed_greyed_and_is_not_a_link(): void
    {
        $this->get('/')
            ->assertSeeInOrder(['<a class="disabled" aria-disabled="true" title="Not built yet">', 'Domains'], false)
            ->assertDontSee('href=""', false);
    }

    public function test_the_sidebar_lists_every_planned_screen(): void
    {
        $this->get('/')->assertSeeInOrder([
            'Dashboard', 'Review queue', 'Tasks', 'Projects',
            'Invoices', 'Recurring', 'Expenses',
            'Companies', 'Products', 'Domains',
            // BAS summary is listed only while GST is on.
            'Profit and loss', 'Sales',
            'Mail log', 'Settings',
        ]);
    }

    public function test_the_page_has_the_empty_shared_dialog(): void
    {
        $this->get('/')
            ->assertSee('id="app-modal"', false)
            ->assertSee('<div class="modal-content" id="app-modal-content"></div>', false)
            ->assertSee('<meta name="csrf-token"', false);
    }

    public function test_a_dialog_with_an_action_is_a_form_posted_back_into_the_dialog(): void
    {
        $this->blade(
            '<x-modal title="Add payment" action="/payments" size="lg"><input name="amount">'
            .'<x-slot:footer><button type="submit">Save</button></x-slot:footer></x-modal>'
        )
            ->assertSee('data-modal-size="lg"', false)
            ->assertSee('<h5 class="modal-title">Add payment</h5>', false)
            ->assertSeeInOrder([
                '<form hx-post="/payments" hx-target="#app-modal-content"',
                '<div class="modal-body">', '<input name="amount">',
                '<div class="modal-footer">', '<button type="submit">Save</button>',
                '</form>',
            ], false);
    }

    public function test_a_dialog_can_use_another_method_or_have_no_form(): void
    {
        $this->blade('<x-modal title="Delete" action="/payments/3" method="delete">Sure?</x-modal>')
            ->assertSee('<form hx-delete="/payments/3"', false)
            ->assertDontSee('modal-footer', false);

        $this->blade('<x-modal title="Preview">Text</x-modal>')
            ->assertSee('Text')
            ->assertDontSee('<form', false);
    }

    public function test_the_top_bar_has_the_light_and_dark_switch(): void
    {
        $this->get('/')->assertSee('id="light-dark-mode"', false);
    }

    public function test_flash_messages_are_shown(): void
    {
        $this->withSession(['success' => 'Invoice saved.', 'error' => 'Send failed.'])
            ->get('/')
            ->assertSeeInOrder(['alert-success', 'Invoice saved.'], false)
            ->assertSeeInOrder(['alert-danger', 'Send failed.'], false);
    }

    public function test_a_status_message_is_shown_as_a_success(): void
    {
        $this->withSession(['status' => 'Password updated.'])
            ->get('/')
            ->assertSeeInOrder(['alert-success', 'Password updated.'], false);
    }

    public function test_no_alert_is_shown_without_a_flash_message(): void
    {
        $this->get('/')->assertDontSee('alert-dismissible', false);
    }

    public function test_the_guest_layout_wraps_the_page_in_a_card_without_the_shell(): void
    {
        $this->blade('<x-layouts.guest title="Log in"><form id="login"></form></x-layouts.guest>')
            ->assertSee('<title>Log in | '.config('app.name').'</title>', false)
            ->assertSee('account-pages', false)
            ->assertSee('<form id="login"></form>', false)
            ->assertDontSee('left-side-menu', false)
            ->assertDontSee('navbar-custom', false);
    }
}
