<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('the login page renders', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
});

test('a maintainer can log in and is taken to the stats', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])
        ->assertRedirect('/stats');

    $this->assertAuthenticatedAs($user);

    $this->get('/stats')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Stats')
            ->where('auth.user.email', $user->email));
});

test('a wrong password is refused', function () {
    $user = User::factory()->create();

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'nope'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('logging out ends the session', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect();

    $this->assertGuest();
});

test('there is no self-service registration or password reset', function () {
    $this->get('/register')->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
});

test('visitors are told nothing about accounts', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));
});
