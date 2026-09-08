<?php

use App\Models\SetlistEntry;
use App\Models\Show;

/*
 * `visit()` is defined by Pest itself but only works once the browser plugin is
 * installed, and calling it without one aborts the whole run rather than failing
 * a test. Skipping keeps `php artisan test` green on a machine that has not set
 * the plugin up — it needs PHP's sockets extension enabled.
 */
beforeEach(function () {
    if (! is_dir(base_path('vendor/pestphp/pest-plugin-browser'))) {
        $this->markTestSkipped(
            'Requires pestphp/pest-plugin-browser (enable PHP ext-sockets, then composer require pestphp/pest-plugin-browser:^4.3 --dev).'
        );
    }
});

/**
 * A show long enough to push the page well past the viewport — there is nothing
 * to restore on a page that never scrolls.
 */
function seedTallShow(): Show
{
    $show = Show::factory()->forYear((int) date('Y'))->create();

    foreach (range(1, 40) as $position) {
        SetlistEntry::factory()->forShow($show, $position)->create();
    }

    return $show;
}

test('a first-time visitor gets the song checker at the root', function () {
    $page = visit('/');

    $page->assertPathIs('/')
        ->assertSee('Song Checker')
        ->assertNoJavaScriptErrors();
});

test('a returning visitor lands back on the page they were last using', function () {
    seedTallShow();

    $page = visit('/recent-setlists');

    $page->assertSee('Recent Setlists');

    // A cold load of the bare root, the way reopening a browser arrives.
    $page->navigate('/');

    $page->assertPathIs('/recent-setlists')
        ->assertSee('Recent Setlists')
        ->assertNoJavaScriptErrors();
});

/*
 * Forwarding and restoring have to work in the same breath. They were once
 * separately correct and jointly broken: the flag that stopped `/` recording
 * itself was still set when the destination mounted, leaving that page with no
 * scroll memory at all, so a returning visitor arrived at the right page but at
 * the top of it.
 */
test('being forwarded back to a page still restores its scroll position', function () {
    seedTallShow();

    $page = visit('/recent-setlists');

    $page->assertSee('Recent Setlists');
    $page->script('window.scrollTo(0, 600)');
    $page->wait(1);

    $page->navigate('/');

    $page->assertPathIs('/recent-setlists')
        ->wait(3)
        ->assertScript('window.scrollY > 300', true);

    // And the page it forwarded to must keep recording, or the memory would be
    // stuck on whatever was stored before the bounce.
    $page->assertScript(
        'JSON.parse(localStorage.getItem("last-visit")).path',
        '/recent-setlists',
    );
});

test('navigating to the root in-app after a bounce records it normally', function () {
    seedTallShow();

    $page = visit('/recent-setlists');

    $page->assertSee('Recent Setlists');
    $page->navigate('/');
    $page->assertPathIs('/recent-setlists');

    $page->click('Song Checker')
        ->assertPathIs('/')
        ->wait(2)
        ->assertScript('JSON.parse(localStorage.getItem("last-visit")).path', '/');
});

test('a query string opts out of the bounce', function () {
    seedTallShow();

    $page = visit('/recent-setlists');

    $page->assertSee('Recent Setlists');

    $page->navigate('/?stay');

    $page->assertPathIs('/')
        ->assertSee('Song Checker');
});

test('the vertical scroll position survives the page being reopened', function () {
    seedTallShow();

    $page = visit('/recent-setlists');

    // Wait for the setlists to arrive: until they render, the document is only a
    // heading tall and there is nowhere to scroll to.
    $page->assertSee('Recent Setlists')
        ->assertScript('document.documentElement.scrollHeight > window.innerHeight', true);

    $page->script('window.scrollTo(0, 600)');

    // The write is coalesced into an animation frame rather than run per event.
    $page->wait(1)
        ->assertScript('window.scrollY > 300', true);

    $page->navigate('/recent-setlists');

    // The restore waits for the document to grow tall enough to hold the offset,
    // which takes as long as the fetch does.
    $page->assertSee('Recent Setlists')
        ->wait(3)
        ->assertScript('window.scrollY > 300', true)
        ->assertNoJavaScriptErrors();
});

/*
 * Before the rows land the document is only a heading tall, so a visitor who
 * wants to scroll cannot actually move the page — trying is all they can do.
 * That is why the restore watches for the attempt itself, and not just for the
 * scroll position having changed.
 */
test('trying to scroll during load stands the restore down rather than yanking the reader back', function (string $event) {
    seedTallShow();

    $page = visit('/recent-setlists');

    $page->assertSee('Recent Setlists');
    $page->script('window.scrollTo(0, 600)');
    $page->wait(1);

    $page->navigate('/recent-setlists');

    $page->script($event);

    $page->assertSee('Recent Setlists')
        ->wait(3)
        ->assertScript('window.scrollY < 100', true);
})->with([
    'wheel' => 'window.dispatchEvent(new WheelEvent("wheel", { deltaY: 10 }))',
    'keyboard' => 'window.dispatchEvent(new KeyboardEvent("keydown", { key: "End" }))',
]);
