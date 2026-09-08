<?php

use Inertia\Testing\AssertableInertia;

/*
 * A returning visitor is bounced to the page they were last using, but that has
 * to stay entirely in the browser and entirely dependent on stored state. A
 * crawler carries neither a cookie nor local storage, so `/` must always serve
 * the song checker itself — never a redirect, which would be cloaking and would
 * also make the response uncacheable.
 */

test('the home page serves the song checker without redirecting', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('SongChecker'));
});

test('the home page never redirects, whatever a client sends with it', function () {
    $this->withHeader('User-Agent', 'Googlebot')
        ->get('/')
        ->assertOk();

    $this->withCookie('last-visit', '/setlist-browser')
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('SongChecker'));
});
