<?php

declare(strict_types=1);

namespace FileVault\Http\Controller;

use FileVault\Http\Request;
use FileVault\Http\Response;
use FileVault\Observability\ErrorEnvelope;

final class ProjectController
{
    public function get(Request $request): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(
                new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId),
                401
            );
        }

        $quota = $project->getQuota();
        $usage = $project->getUsage();

        $storagePercentage = 0.0;
        if ($quota->maxStorageBytes > 0) {
            $storagePercentage = round(($usage->currentStorageBytes / $quota->maxStorageBytes) * 100, 2);
        }

        $fileCountPercentage = 0.0;
        if ($quota->maxFileCount > 0) {
            $fileCountPercentage = round(($usage->currentFileCount / $quota->maxFileCount) * 100, 2);
        }

        return Response::json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->getStatus()->value,
                'quota' => [
                    'max_file_size' => $quota->maxFileSize,
                    'max_file_count' => $quota->maxFileCount,
                    'max_storage_bytes' => $quota->maxStorageBytes,
                ],
                'usage' => [
                    'current_file_count' => $usage->currentFileCount,
                    'current_storage_bytes' => $usage->currentStorageBytes,
                    'storage_percentage' => $storagePercentage,
                    'file_count_percentage' => $fileCountPercentage,
                ],
                'created_at' => $project->createdAt->format(\DateTimeInterface::ATOM),
                'updated_at' => $project->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
