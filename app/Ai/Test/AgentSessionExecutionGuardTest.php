<?php

use App\Ai\Service\Agent\SessionExecutionGuard;

it('SessionExecutionGuard：同一会话不可重复获取锁', function () {
    $first = SessionExecutionGuard::acquire(9527);
    expect($first)->not->toBeNull()
        ->and(SessionExecutionGuard::acquire(9527))->toBeNull();

    SessionExecutionGuard::release($first);

    $second = SessionExecutionGuard::acquire(9527);
    expect($second)->not->toBeNull();
    SessionExecutionGuard::release($second);
});
