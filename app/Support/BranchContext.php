<?php

namespace App\Support;

use App\Models\Branch;

class BranchContext
{
    private static ?int $id = null;

    private static bool $locked = false;

    private static bool $allShops = false;

    public static function set(?int $id, bool $locked = false): void
    {
        self::$id = $id;
        self::$locked = $locked;
        self::$allShops = false;
    }

    public static function setAllShops(): void
    {
        self::$id = null;
        self::$locked = false;
        self::$allShops = true;
    }

    public static function clear(): void
    {
        self::$id = null;
        self::$locked = false;
        self::$allShops = false;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function requireId(): int
    {
        abort_unless(self::$id, 422, 'Select a shop before creating this record.');

        return (int) self::$id;
    }

    public static function locked(): bool
    {
        return self::$locked;
    }

    public static function viewingAll(): bool
    {
        return self::$allShops;
    }

    public static function branch(): ?Branch
    {
        return self::$id ? Branch::query()->find(self::$id) : null;
    }
}
