<?php

// The test suite uses a file based SQLite database: migrations run ONCE per test run and every test is wrapped in a
// transaction (RefreshDatabase). With ":memory:" all migrations would run again before every single test.
$database = __DIR__ . '/../database/testing.sqlite';

if (!file_exists($database)) {
    touch($database);
}

require __DIR__ . '/../vendor/autoload.php';