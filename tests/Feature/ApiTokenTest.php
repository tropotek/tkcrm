<?php

namespace Tests\Feature;

use App\Http\Controllers\ApiTokenController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // One in the afternoon on 15 October in Melbourne.
        $this->travelTo('2026-10-15 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);

        // Somewhere a token is asked for, as the API will: with the read ability.
        Route::middleware(['auth:sanctum', 'abilities:'.ApiTokenController::ABILITY])
            ->get('/_test/whoami', fn () => ['email' => request()->user()->email]);
    }

    /**
     * Start the next request as a fresh one: a request that used a token leaves its user, and
     * the token guard as the default, on the test's one application.
     */
    private function nextRequest(): void
    {
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
    }

    public function test_a_guest_can_make_and_revoke_nothing(): void
    {
        $token = $this->user->createToken('Claude', ['read'])->accessToken;

        $this->post('/profile/tokens', ['token_name' => 'Sneaky'])->assertRedirect(route('login'));
        $this->delete("/profile/tokens/{$token->id}")->assertRedirect(route('login'));

        $this->assertSame(['Claude'], PersonalAccessToken::pluck('name')->all());
    }

    public function test_the_profile_page_has_the_tokens_panel_and_says_when_there_are_none(): void
    {
        $this->actingAs($this->user)
            ->get('/profile')
            ->assertOk()
            ->assertSeeInOrder(['API tokens', 'It can read and nothing else', 'action="'.route('profile.tokens.store').'"', 'Make a token', 'No tokens yet.'], false)
            ->assertDontSee('Your new token.');
    }

    public function test_a_token_is_made_shown_once_and_only_its_hash_is_kept(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/profile/tokens', ['token_name' => 'Claude'])
            ->assertRedirect(route('profile.edit').'#api-tokens')
            ->assertSessionHas('success', 'Made the token "Claude". Copy it now: it is not shown again.');

        $plain = $response->getSession()->get('api_token');
        $token = PersonalAccessToken::sole();

        $this->assertMatchesRegularExpression('/^\d+\|[A-Za-z0-9]{40,}$/', $plain);
        $this->assertSame(['Claude', ['read'], $this->user->id], [$token->name, $token->abilities, $token->tokenable_id]);
        // What is stored is a hash of it, not the token.
        $this->assertSame(hash('sha256', explode('|', $plain)[1]), $token->token);
        $this->assertStringNotContainsString(explode('|', $plain)[1], json_encode($token->getAttributes()));

        // The page it lands on shows it, with the token in the list.
        $this->actingAs($this->user)->withSession(['api_token' => $plain])
            ->get('/profile')
            ->assertSeeInOrder(['Your new token.', 'value="'.$plain.'"', 'Claude', '15 Oct 2026, 1:00pm', 'Never'], false);

        // And the next time it is opened, it is gone.
        $this->actingAs($this->user)->get('/profile')->assertDontSee($plain)->assertDontSee('Your new token.')->assertSee('Claude');
    }

    public function test_a_token_needs_a_name(): void
    {
        $this->actingAs($this->user)
            ->from('/profile')
            ->post('/profile/tokens', ['token_name' => ''])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('apiToken', 'token_name');

        $this->actingAs($this->user)
            ->post('/profile/tokens', ['token_name' => str_repeat('a', 101)])
            ->assertSessionHasErrorsIn('apiToken', 'token_name');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_token_signs_its_user_in_where_a_token_is_asked_for_and_nowhere_else(): void
    {
        $plain = $this->user->createToken('Claude', ['read'])->plainTextToken;

        $this->getJson('/_test/whoami')->assertUnauthorized();
        $this->getJson('/_test/whoami', ['Authorization' => 'Bearer nonsense'])->assertUnauthorized();
        $this->getJson('/_test/whoami', ['Authorization' => "Bearer {$plain}"])->assertOk()->assertExactJson(['email' => $this->user->email]);

        // It is not a login to the screens.
        $this->nextRequest();
        $this->get('/profile', ['Authorization' => "Bearer {$plain}"])->assertRedirect(route('login'));
        $this->post('/profile/tokens', ['token_name' => 'More'], ['Authorization' => "Bearer {$plain}"])->assertRedirect(route('login'));
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_a_token_without_the_read_ability_is_refused(): void
    {
        $plain = $this->user->createToken('Odd', ['something-else'])->plainTextToken;

        $this->getJson('/_test/whoami', ['Authorization' => "Bearer {$plain}"])->assertForbidden();
    }

    public function test_each_token_shows_when_it_was_last_used(): void
    {
        $used = $this->user->createToken('Claude', ['read']);
        $this->user->createToken('Spare', ['read']);

        $this->actingAs($this->user)->get('/profile')->assertSeeInOrder(['Spare', 'Never', 'Claude', 'Never'], false);
        $this->nextRequest();

        $this->travelTo('2026-10-16 05:30:00');
        $this->getJson('/_test/whoami', ['Authorization' => "Bearer {$used->plainTextToken}"])->assertOk();

        $this->assertSame('2026-10-16 05:30:00', $used->accessToken->fresh()->last_used_at->toDateTimeString());

        $this->nextRequest();
        $this->actingAs($this->user)->get('/profile')->assertSeeInOrder(['Spare', 'Never', 'Claude', '16 Oct 2026, 4:30pm'], false);
    }

    public function test_a_revoked_token_stops_working_at_once(): void
    {
        $token = $this->user->createToken('Claude', ['read']);
        $this->getJson('/_test/whoami', ['Authorization' => "Bearer {$token->plainTextToken}"])->assertOk();
        $this->nextRequest();

        $this->actingAs($this->user)
            ->delete("/profile/tokens/{$token->accessToken->id}")
            ->assertRedirect(route('profile.edit').'#api-tokens')
            ->assertSessionHas('success', 'Revoked the token "Claude".');

        $this->assertSame(0, PersonalAccessToken::count());

        $this->nextRequest();
        $this->getJson('/_test/whoami', ['Authorization' => "Bearer {$token->plainTextToken}"])->assertUnauthorized();
    }

    public function test_one_login_cannot_see_or_revoke_anothers_tokens(): void
    {
        $other = User::factory()->create();
        $theirs = $other->createToken('Theirs', ['read'])->accessToken;

        $this->actingAs($this->user)->get('/profile')->assertDontSee('Theirs');
        $this->actingAs($this->user)->delete("/profile/tokens/{$theirs->id}")->assertNotFound();

        $this->assertSame(['Theirs'], PersonalAccessToken::pluck('name')->all());
    }

    public function test_a_token_does_not_expire_by_itself(): void
    {
        $this->assertNull(config('sanctum.expiration'));
        // The web session is never an API login.
        $this->assertSame([], config('sanctum.guard'));
    }
}
