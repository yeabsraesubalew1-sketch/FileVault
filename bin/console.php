<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FileVault\Foundation\Bootstrap\App;
use FileVault\Project\ProjectQuota;

$basePath = dirname(__DIR__);

try {
    $app = App::bootstrap($basePath);
} catch (\Throwable $e) {
    echo "Bootstrap error: " . $e->getMessage() . "\n";
    exit(1);
}

$argv = $_SERVER['argv'];
$command = $argv[1] ?? 'help';

function parseBytes(string $val): int
{
    $val = trim($val);
    $last = strtolower(substr($val, -1));
    $num = (int) $val;

    return match ($last) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => (int) $val,
    };
}

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return 'Unlimited';
    }
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

switch ($command) {
    case 'project:create':
        $name = $argv[2] ?? null;
        if (!$name) {
            echo "Usage: php bin/console.php project:create <name> [--max-file-size=50M] [--max-file-count=1000] [--max-storage=1G]\n";
            exit(1);
        }

        $maxFileSize = 50 * 1024 * 1024; // Default 50MB
        $maxFileCount = 10000;
        $maxStorage = 10 * 1024 * 1024 * 1024; // Default 10GB

        foreach (array_slice($argv, 3) as $arg) {
            if (str_starts_with($arg, '--max-file-size=')) {
                $maxFileSize = parseBytes(substr($arg, 16));
            } elseif (str_starts_with($arg, '--max-file-count=')) {
                $maxFileCount = (int) substr($arg, 17);
            } elseif (str_starts_with($arg, '--max-storage=')) {
                $maxStorage = parseBytes(substr($arg, 14));
            }
        }

        $quota = new ProjectQuota($maxFileSize, $maxFileCount, $maxStorage);
        $project = $app->getProjectService()->create($name, $quota, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        echo "========================================\n";
        echo "Project created successfully!\n";
        echo "========================================\n";
        echo "ID:             " . $project->id . "\n";
        echo "Name:           " . $project->name . "\n";
        echo "Status:         " . $project->getStatus()->value . "\n";
        echo "Max File Size:  " . formatBytes($quota->maxFileSize) . "\n";
        echo "Max File Count: " . number_format($quota->maxFileCount) . "\n";
        echo "Max Storage:    " . formatBytes($quota->maxStorageBytes) . "\n";
        echo "========================================\n";
        break;

    case 'project:list':
        $projects = $app->getProjectRepository()->findAll(100, 0);
        if (empty($projects)) {
            echo "No projects found.\n";
            exit(0);
        }

        echo sprintf("%-5s | %-25s | %-12s | %-16s | %-16s\n", "ID", "Name", "Status", "Storage Used", "Files");
        echo str_repeat("-", 85) . "\n";
        foreach ($projects as $p) {
            $quota = $p->getQuota();
            $usage = $p->getUsage();
            $storageStr = formatBytes($usage->currentStorageBytes) . ' / ' . formatBytes($quota->maxStorageBytes);
            $filesStr = number_format($usage->currentFileCount) . ' / ' . ($quota->maxFileCount > 0 ? number_format($quota->maxFileCount) : 'Unlimited');
            echo sprintf("%-5d | %-25s | %-12s | %-16s | %-16s\n", $p->id, mb_substr($p->name, 0, 25), $p->getStatus()->value, $storageStr, $filesStr);
        }
        break;

    case 'key:create':
        $projectId = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : null;
        $name = $argv[3] ?? 'Default API Key';

        if (!$projectId) {
            echo "Usage: php bin/console.php key:create <projectId> [keyName]\n";
            exit(1);
        }

        // Verify project exists
        $project = $app->getProjectRepository()->findById($projectId);
        if ($project === null) {
            echo "Error: Project with ID $projectId not found.\n";
            exit(1);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $createdKey = $app->getCreateApiKey()->create($projectId, $name, $now);

        echo "========================================================================\n";
        echo "API Key created successfully!\n";
        echo "========================================================================\n";
        echo "Project:   " . $project->name . " (ID: $projectId)\n";
        echo "Key Name:  " . $name . "\n";
        echo "Key ID:    " . $createdKey->apiKey->id . "\n";
        echo "\nPLAINTEXT CREDENTIAL (save this now, it cannot be recovered):\n";
        echo "\n  " . $createdKey->plaintextCredential . "\n\n";
        echo "========================================================================\n";
        break;

    case 'key:list':
        $projectId = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : null;
        if (!$projectId) {
            echo "Usage: php bin/console.php key:list <projectId>\n";
            exit(1);
        }

        $keys = $app->getApiKeyRepository()->findByProject($projectId);
        if (empty($keys)) {
            echo "No API keys found for project $projectId.\n";
            exit(0);
        }

        echo sprintf("%-5s | %-20s | %-24s | %-10s | %-20s\n", "ID", "Name", "Public ID", "Status", "Created At");
        echo str_repeat("-", 90) . "\n";
        foreach ($keys as $k) {
            $publicHex = bin2hex($k->publicId->bytes);
            echo sprintf("%-5d | %-20s | %-24s | %-10s | %-20s\n", $k->id, mb_substr($k->name->value, 0, 20), substr($publicHex, 0, 24), $k->status->value, $k->createdAt->format('Y-m-d H:i:s'));
        }
        break;

    case 'key:revoke':
        $keyId = isset($argv[2]) && ctype_digit($argv[2]) ? (int) $argv[2] : null;
        if (!$keyId) {
            echo "Usage: php bin/console.php key:revoke <keyId>\n";
            exit(1);
        }

        $app->getApiKeyRepository()->revoke($keyId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        echo "API Key $keyId revoked successfully.\n";
        break;

    case 'migrate':
        $migrationsPath = $basePath . '/database/migrations';
        $runner = $app->getDatabase()->createMigrationRunner($migrationsPath);
        $runner->run();
        echo "Migrations executed successfully.\n";
        break;

    case 'help':
    default:
        echo "FileVault Management Console\n\n";
        echo "Available commands:\n";
        echo "  project:create <name> [options]   Create a new project\n";
        echo "  project:list                      List all projects and their quotas/usage\n";
        echo "  key:create <projectId> [name]     Generate an API key for a project\n";
        echo "  key:list <projectId>              List API keys for a project\n";
        echo "  key:revoke <keyId>                Revoke an API key\n";
        echo "  migrate                           Run database migrations\n";
        echo "  help                              Show this help message\n";
        break;
}
