<?php

use App\Models\User;
use App\Services\Credits\CreditService;
use Illuminate\Auth\Events\Registered;

it('grants signup credits when a user registers', function () {
    config(['credits.signup' => 20]);
    $user = User::factory()->create();

    event(new Registered($user));

    expect(app(CreditService::class)->balance($user))->toBe(20);
});

it('grants signup credits through the registration form', function () {
    config(['credits.signup' => 20]);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $user = User::where('email', 'new@example.com')->firstOrFail();

    expect(app(CreditService::class)->balance($user))->toBe(20);
});
