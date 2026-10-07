<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FileVault\Foundation\Config\ConfigLoader;
use FileVault\Persistence\Database;
use FileVault\Persistence\Migration\MigrationRunner;

try {
    $config = ConfigLoader::load(__DIR__ . '/..');
    $database = new Database($config);
    
    $migrationsPath = __DIR__ . '/../database/migrations';
    
    $runner = $database->createMigrationRunner($migrationsPath);
    $runner->run();
    
    echo "Migrations executed successfully.\n";
    exit(0);
} catch (\Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
