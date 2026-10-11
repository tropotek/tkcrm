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
            ->reject(fn (RoutingRoute $route) => in_array('auth', $route->gatherMiddleware(), true))
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
