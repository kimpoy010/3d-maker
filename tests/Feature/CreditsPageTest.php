<?php

use App\Enums\LedgerReason;
use App\Models\User;
use App\Services\Credits\CreditService;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->credits = app(CreditService::class);
    $this->credits->grant($this->user, 20, LedgerReason::Signup);
});

it('shows the balance and ledger history, newest first', function () {
    $this->credits->grant($this->user, 5, LedgerReason::Topup);

    $this->actingAs($this->user)->get('/credits')->assertInertia(fn (Assert $page) => $page
        ->component('Credits')
        ->where('balance', 25)
        ->has('ledger', 2)
        ->where('ledger.0.reason', 'topup')
        ->where('ledger.0.delta', 5)
        ->where('topup.enabled', true));
});

it('only shows the current users ledger', function () {
    $this->credits->grant(User::factory()->create(), 99, LedgerReason::Topup);

    $this->actingAs($this->user)->get('/credits')->assertInertia(fn (Assert $page) => $page
        ->where('balance', 20)
        ->has('ledger', 1));
});

it('grants the stub top-up amount', function () {
    config(['credits.stub_topup' => true, 'credits.topup' => 25]);

    $this->actingAs($this->user)->post('/credits/topup')->assertRedirect();

    expect($this->credits->balance($this->user))->toBe(45);
});

it('disables the stub top-up when configured off', function () {
    config(['credits.stub_topup' => false]);

    $this->actingAs($this->user)->post('/credits/topup')->assertNotFound();

    expect($this->credits->balance($this->user))->toBe(20);
});

it('requires authentication', function () {
    $this->get('/credits')->assertRedirect('/login');
    $this->post('/credits/topup')->assertRedirect('/login');
});
