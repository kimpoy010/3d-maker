<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users are sent to their creations', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/creations');
});
