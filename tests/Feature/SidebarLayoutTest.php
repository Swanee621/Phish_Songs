<?php

use Inertia\Testing\AssertableInertia as Assert;

test('pages are told to render the sidebar layout when the sidebar is enabled', function () {
    config(['app.sidebar_enabled' => true]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sidebarEnabled', true));
});

test('pages are told to render the plain layout when the sidebar is disabled', function () {
    config(['app.sidebar_enabled' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sidebarEnabled', false));
});
