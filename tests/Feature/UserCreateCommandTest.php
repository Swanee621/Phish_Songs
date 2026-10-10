<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('user:create makes a verified account with a hashed password', function () {
    $this->artisan('user:create', ['email' => 'trey@example.com'])
        ->expectsQuestion('Name', 'Trey')
        ->expectsQuestion('Password', 'correct-horse-battery')
        ->assertSuccessful();

    $user = User::where('email', 'trey@example.com')->firstOrFail();

    expect($user->name)->toBe('Trey')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

test('user:create refuses an address that already has an account', function () {
    User::factory()->create(['email' => 'trey@example.com']);

    $this->artisan('user:create', ['email' => 'trey@example.com'])
        ->assertFailed();

    expect(User::count())->toBe(1);
});
