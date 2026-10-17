<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

function becomeGuest(): void
{
    app('auth')->forgetGuards();
}

test('guests must authenticate before using the dashboard, log viewer, or protected API', function (): void {
    becomeGuest();

    $this->get('/')->assertRedirect(route('login'));
    $this->get('/log-viewer')->assertRedirect(route('login'));
    $this->postJson('/api/settlements', [])->assertUnauthorized();
});

test('an operator can sign in and access the log viewer', function (): void {
    becomeGuest();
    $operator = User::factory()->create([
        'email' => 'operator@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $this->post('/login', [
        'email' => $operator->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($operator);
    $this->get('/log-viewer')->assertOk();
});

test('signing out invalidates the operator session', function (): void {
    $this->post('/logout')->assertRedirect(route('login'));

    $this->assertGuest();
    $this->get('/')->assertRedirect(route('login'));
});

test('signed provider webhooks remain available without an operator session', function (): void {
    becomeGuest();

    $this->postJson('/api/webhooks/arc', [
        'eventId' => 'operator-auth-public-webhook',
    ])->assertOk()
        ->assertJson(['status' => 'ignored']);
});
