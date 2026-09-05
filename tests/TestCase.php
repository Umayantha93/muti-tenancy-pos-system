<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        self::forceTestDatabase();
        parent::setUpBeforeClass();
    }

    public function createApplication()
    {
        self::forceTestDatabase();

        return parent::createApplication();
    }

    protected function beforeRefreshingDatabase(): void
    {
        throw new RuntimeException('Database refresh is disabled. It deletes database data.');
    }

    protected function refreshDatabase(): void
    {
        throw new RuntimeException('Database refresh is disabled. It deletes database data.');
    }

    private static function forceTestDatabase(): void
    {
        putenv('DB_DATABASE=pos_db_test');
        $_ENV['DB_DATABASE'] = 'pos_db_test';
        $_SERVER['DB_DATABASE'] = 'pos_db_test';
    }
}
