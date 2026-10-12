<?php

namespace Tests\Feature\Auth;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoutesNeedLoginTest extends TestCase
{
    public function test_every_route_needs_a_login_except_the_sign_in_pages_and_the_health_check(): void
    {
        $open = collect(Route::getRoutes()->getRoutes())
            // A screen needs a signed-in user; an API route needs a token.
            ->reject(fn (RoutingRoute $route) => array_intersect(['auth', 'auth:sanctum'], $route->gatherMiddleware()) !== [])
            ->map(fn (RoutingRoute $route) => implode('|', $route->methods()).' '.$route->uri())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'GET|HEAD forgot-password',
            'GET|HEAD login',
            'GET|HEAD reset-password/{token}',
            'GET|HEAD up',
            'POST forgot-password',
            'POST login',
            'POST reset-password',
        ], $open);
    }

    public function test_every_api_route_is_a_get_that_needs_a_token_with_the_read_ability(): void
    {
        $api = collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'));

        $this->assertGreaterThan(5, $api->count());

        foreach ($api as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware(), $route->uri());
            $this->assertContains('abilities:read', $route->gatherMiddleware(), $route->uri());
            $this->assertNotContains('auth', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_there_is_no_registration_or_social_sign_in(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();

        foreach (['google', 'microsoft', 'facebook'] as $provider) {
            $this->get("/auth/{$provider}")->assertNotFound();
            $this->get("/login/{$provider}")->assertNotFound();
        }
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
