<?php

declare(strict_types=1);

namespace FileVault\Http\Controller;

use FileVault\Http\Request;
use FileVault\Http\Response;
use FileVault\Http\Security\FileValidator;
use FileVault\Lifecycle\FileLifecycle;
use FileVault\Observability\ErrorEnvelope;
use FileVault\Persistence\Record\FileRecord;
use FileVault\Persistence\Repository\FileRepository;
use FileVault\Project\Exception\ProjectNotOperationalException;
use FileVault\Project\Exception\QuotaExceededException;
use FileVault\Project\ProjectRepository;
use FileVault\Project\UsageDelta;
use InvalidArgumentException;

final class FileController
{
    public function __construct(
        private readonly FileLifecycle $fileLifecycle,
        private readonly FileRepository $fileRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly string $appUrl
    ) {}

    public function upload(Request $request): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId), 401);
        }

        if (!$project->isOperational()) {
            throw new ProjectNotOperationalException('Project is not operational.');
        }

        $uploadedFile = $request->file('file');
        if ($uploadedFile === null) {
            return Response::error(new ErrorEnvelope('file_required', 'No file was uploaded. Field "file" is required.', $request->requestId), 400);
        }

        $uploadError = $uploadedFile['error'] ?? UPLOAD_ERR_OK;
        if ($uploadError !== UPLOAD_ERR_OK) {
            if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                return Response::error(new ErrorEnvelope('file_too_large', 'Uploaded file exceeds server upload size limit.', $request->requestId), 413);
            }
            return Response::error(new ErrorEnvelope('invalid_request', 'Upload failed with error code ' . $uploadError, $request->requestId), 400);
        }

        $tmpPath = $uploadedFile['tmp_name'];
        $originalName = (string) ($request->input('name') ?: $uploadedFile['name']);
        $clientMime = (string) $uploadedFile['type'];
        $fileSize = (int) ($uploadedFile['size'] ?? (file_exists($tmpPath) ? filesize($tmpPath) : 0));

        if ($fileSize <= 0) {
            return Response::error(new ErrorEnvelope('invalid_request', 'Uploaded file is empty.', $request->requestId), 400);
        }

        // Validate type & dangerous extensions
        try {
            $validated = FileValidator::validate($tmpPath, $originalName, $clientMime);
        } catch (InvalidArgumentException $e) {
            return Response::error(new ErrorEnvelope('file_type_not_allowed', $e->getMessage(), $request->requestId), 415);
        }

        // Validate Quota
        $quota = $project->getQuota();
        $delta = UsageDelta::newFile($fileSize);
        $check = $quota->fits($project->getUsage(), $delta, $fileSize);
        if (!$check->allowed) {
            throw new QuotaExceededException('Project quota exceeded.', $check->violations);
        }

        $visibility = strtolower((string) $request->input('visibility', 'public'));
        if (!in_array($visibility, ['public', 'private'], true)) {
            $visibility = 'public';
        }

        $stream = @fopen($tmpPath, 'rb');
        if ($stream === false) {
            return Response::error(new ErrorEnvelope('storage_error', 'Failed to read uploaded file payload.', $request->requestId), 500);
        }

        try {
            $fileRecord = $this->fileLifecycle->create(
                $project->id,
                $stream,
                $validated['safeName'],
                $validated['mimeType'],
                $visibility
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Update Project Usage
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lockedProject = $this->projectRepository->findByIdForUpdate($project->id);
        if ($lockedProject !== null) {
            $lockedProject->applyUsageDelta(UsageDelta::newFile($fileRecord->sizeBytes), $now);
            $this->projectRepository->save($lockedProject);
        }

        return Response::json([
            'file' => $this->formatFile($fileRecord),
        ], 201);
    }

    public function list(Request $request): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId), 401);
        }

        $status = $request->input('status', 'active');
        $limit = (int) $request->input('limit', 50);
        $offset = (int) $request->input('offset', 0);

        $files = $this->fileRepository->listByProject($project->id, $status, $limit, $offset);
        $total = $this->fileRepository->countByProject($project->id, $status);

        $formatted = array_map([$this, 'formatFile'], $files);

        return Response::json([
            'files' => $formatted,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function get(Request $request, string $id): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId), 401);
        }

        if (!ctype_digit($id)) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $file = $this->fileRepository->findByProjectAndId($project->id, (int) $id);
        if ($file === null) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        return Response::json([
            'file' => $this->formatFile($file),
        ]);
    }

    public function delete(Request $request, string $id): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId), 401);
        }

        if (!ctype_digit($id)) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $fileId = (int) $id;
        $file = $this->fileRepository->findByProjectAndId($project->id, $fileId);
        if ($file === null) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $this->fileLifecycle->trash($project->id, $fileId);

        return Response::json([
            'status' => 'trashed',
            'file_id' => $fileId,
        ]);
    }

    public function replace(Request $request, string $id): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId), 401);
        }

        if (!ctype_digit($id)) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $fileId = (int) $id;
        $oldFile = $this->fileRepository->findByProjectAndId($project->id, $fileId);
        if ($oldFile === null) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $uploadedFile = $request->file('file');
        if ($uploadedFile === null || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::error(new ErrorEnvelope('file_required', 'Replacement file is required.', $request->requestId), 400);
        }

        $tmpPath = $uploadedFile['tmp_name'];
        $originalName = (string) ($request->input('name') ?: $uploadedFile['name']);
        $clientMime = (string) $uploadedFile['type'];
        $newSize = (int) ($uploadedFile['size'] ?? filesize($tmpPath));

        try {
            $validated = FileValidator::validate($tmpPath, $originalName, $clientMime);
        } catch (InvalidArgumentException $e) {
            return Response::error(new ErrorEnvelope('file_type_not_allowed', $e->getMessage(), $request->requestId), 415);
        }

        // Quota check with replace delta
        $quota = $project->getQuota();
        $delta = UsageDelta::replace($newSize);
        $check = $quota->fits($project->getUsage(), $delta, $newSize);
        if (!$check->allowed) {
            throw new QuotaExceededException('Project quota exceeded.', $check->violations);
        }

        $visibility = strtolower((string) $request->input('visibility', $oldFile->visibility));

        $stream = @fopen($tmpPath, 'rb');
        if ($stream === false) {
            return Response::error(new ErrorEnvelope('storage_error', 'Failed to read replacement payload.', $request->requestId), 500);
        }

        try {
            $newRecord = $this->fileLifecycle->replace(
                $project->id,
                $fileId,
                $stream,
                $validated['safeName'],
                $validated['mimeType'],
                $visibility
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Apply replace usage delta
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lockedProject = $this->projectRepository->findByIdForUpdate($project->id);
        if ($lockedProject !== null) {
            $lockedProject->applyUsageDelta(UsageDelta::replace($newRecord->sizeBytes), $now);
            $this->projectRepository->save($lockedProject);
        }

        return Response::json([
            'file' => $this->formatFile($newRecord),
        ]);
    }

    public function restore(Request $request, string $id): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId), 401);
        }

        if (!ctype_digit($id)) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $fileId = (int) $id;
        $this->fileLifecycle->restore($project->id, $fileId);

        return Response::json([
            'status' => 'restored',
            'file_id' => $fileId,
        ]);
    }

    public function purge(Request $request, string $id): Response
    {
        $project = $request->authenticatedProject;
        if ($project === null) {
            return Response::error(new ErrorEnvelope('authentication_required', 'Authentication required.', $request->requestId), 401);
        }

        if (!ctype_digit($id)) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $fileId = (int) $id;
        $file = $this->fileRepository->findByProjectAndId($project->id, $fileId);
        if ($file === null) {
            return Response::error(new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId), 404);
        }

        $this->fileLifecycle->purge($project->id, $fileId);

        // Update Project usage by reducing deleted file size and count
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lockedProject = $this->projectRepository->findByIdForUpdate($project->id);
        if ($lockedProject !== null) {
            $lockedProject->applyUsageDelta(UsageDelta::purge($file->sizeBytes), $now);
            $this->projectRepository->save($lockedProject);
        }

        return Response::json([
            'status' => 'purged',
            'file_id' => $fileId,
        ]);
    }

    private function resolveBaseUrl(): string
    {
        $baseUrl = rtrim($this->appUrl, '/');
        if (($baseUrl === 'http://localhost' || $baseUrl === 'http://127.0.0.1') && isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], ':')) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        return $baseUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatFile(FileRecord $file): array
    {
        $publicUrl = $this->resolveBaseUrl() . '/files/' . $file->storageKey;

        return [
            'id' => $file->id,
            'project_id' => $file->projectId,
            'original_name' => $file->originalName,
            'storage_key' => $file->storageKey,
            'mime_type' => $file->mimeType,
            'size_bytes' => $file->sizeBytes,
            'visibility' => $file->visibility,
            'status' => $file->status,
            'url' => $publicUrl,
            'created_at' => $file->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $file->updatedAt->format(\DateTimeInterface::ATOM),
            'trashed_at' => $file->trashedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
