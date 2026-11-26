<?php

namespace Hibira\Filament\Tests;

use Hibira\Filament\Tests\Fixtures\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class SecretaryTest extends TestCase
{
    public function test_the_panel_loads_the_assistant_for_a_signed_in_user()
    {
        $this->actingAs(User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('src="https://hibira.io/secretary/v1.js"', false)
            ->assertSee('data-key="pk_sec"', false)
            ->assertSee('data-session-url="/admin/hibira/secretary/session"', false)
            ->assertSee('data-navigate-once', false)
            ->assertDontSee('sk_sec');
    }

    public function test_the_server_signs_the_panel_user_in()
    {
        Http::fake(['hibira.io/secretary/v1/sessions' => Http::response(['token' => 'sst_abc', 'expires_at' => now()->addHours(12)->toIso8601String()])]);
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        $this->actingAs($user)->postJson('/admin/hibira/secretary/session')
            ->assertOk()
            ->assertExactJson(['token' => 'sst_abc']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://hibira.io/secretary/v1/sessions'
            && $r->hasHeader('Authorization', 'Bearer sk_sec')
            && $r['user_id'] === 'admin:'.$user->id
            && $r['name'] === 'Ada');
    }

    public function test_a_refused_session_is_not_passed_on()
    {
        Http::fake(['hibira.io/secretary/v1/sessions' => Http::response(['error' => 'That secret key is wrong or was rotated.', 'code' => 'secretary_secret'], 401)]);

        $this->actingAs(User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']))
            ->postJson('/admin/hibira/secretary/session')
            ->assertStatus(502);
    }

    public function test_guests_get_no_session()
    {
        $this->postJson('/admin/hibira/secretary/session')->assertStatus(401);
    }

    public function test_without_keys_the_panel_has_no_assistant()
    {
        config(['hibira-filament.secretary.key' => null]);
        $this->actingAs(User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']));

        $this->get('/admin')->assertOk()->assertDontSee('/secretary/v1.js', false);
    }
}
