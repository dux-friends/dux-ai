<?php

use App\Ai\Test\Support\Migrate\AiMigrateProvider;
use App\Boot\Test\Support\Migrate\BootMigrateProvider;
use App\System\Test\Support\Migrate\SystemMigrateProvider;
use App\System\Test\Support\TestApp;

TestApp::boot();

pest()->beforeEach(function () {
    TestApp::setMigrateProviders([
        SystemMigrateProvider::class,
        BootMigrateProvider::class,
        AiMigrateProvider::class,
    ]);
    TestApp::refreshDatabase();
})->in(__DIR__);

