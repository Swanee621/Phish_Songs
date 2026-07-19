<?php

test('the offline page renders the no connection message', function () {
    $this->get('/offline')
        ->assertOk()
        ->assertSee('You are currently not connected to any networks.');
});

test('the offline page is self-contained so it renders with no network', function () {
    $html = $this->get('/offline')->assertOk()->getContent();

    expect($html)
        ->not->toContain('<link rel="stylesheet"')
        ->not->toContain('<script');
});
