<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Bootstrap;
use App\Support\Db;
use App\Support\FrozenClock;
use App\Support\Migrator;
use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase
{
    public function testMigrationsApplyOnceAndInOrder(): void
    {
        $db = Db::open(':memory:');
        $migrator = new Migrator($db, Bootstrap::root() . '/database/migrations', new FrozenClock());
        $applied = $migrator->migrate();
        self::assertNotEmpty($applied);
        self::assertSame($applied, array_keys($migrator->files()));
        self::assertSame([], $migrator->migrate(), 'Una seconda esecuzione non applica nulla');
        self::assertSame(29, $db->int('SELECT COUNT(*) FROM countries WHERE served = 1'));
        self::assertSame('IT', $db->value('SELECT code FROM countries ORDER BY sort_order, code LIMIT 1'));
        self::assertSame(3, $db->int('SELECT COUNT(*) FROM shipping_zones'));
    }

    public function testFileDatabaseUsesWal(): void
    {
        $path = sys_get_temp_dir() . '/scs-wal-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $db = Db::open($path);
            self::assertSame('wal', $db->value('PRAGMA journal_mode'));
            self::assertSame(1, $db->int('PRAGMA foreign_keys'));
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                @unlink($file);
            }
        }
    }
}
