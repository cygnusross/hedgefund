<?php

it('updater_is_resolvable_from_container', function () {
    $obj = app(\App\Application\Candles\IncrementalCandleUpdater::class);
    expect($obj)->not->toBeNull();
});
