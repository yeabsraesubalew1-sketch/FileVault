<?php

declare(strict_types=1);

namespace FileVault\Http\Controller;

use FileVault\Http\Request;
use FileVault\Http\Response;
use FileVault\Observability\ErrorEnvelope;
use FileVault\Persistence\Repository\FileRepository;
use FileVault\Project\ProjectRepository;
use FileVault\Storage\StorageBackend;
use FileVault\Storage\Exception\StorageObjectNotFoundException;

final class PublicDeliveryController
{
    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly StorageBackend $storageBackend
    ) {}

    public function deliver(Request $request, string $storageKey): Response
    {
        $file = $this->fileRepository->findByStorageKey($storageKey);
        if ($file === null) {
            return Response::error(
                new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId),
                404
            );
        }

        return $this->serveFile($request, $file);
    }

    public function deliverById(Request $request, string $id): Response
    {
        if (!ctype_digit($id)) {
            return Response::error(
                new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId),
                404
            );
        }

        $file = $this->fileRepository->findById((int) $id);
        if ($file === null) {
            return Response::error(
                new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId),
                404
            );
        }

        return $this->serveFile($request, $file);
    }

    private function serveFile(Request $request, \FileVault\Persistence\Record\FileRecord $file): Response
    {
        // 1. Invariant: only active public files are delivered publicly
        if ($file->status !== 'active' || $file->visibility !== 'public') {
            return Response::error(
                new ErrorEnvelope('file_not_found', 'File not found or not publicly available.', $request->requestId),
                404
            );
        }

        // 2. Invariant: project must permit public delivery
        $project = $this->projectRepository->findById($file->projectId);
        if ($project === null || !$project->allowsPublicDelivery()) {
            return Response::error(
                new ErrorEnvelope('file_not_found', 'File not found.', $request->requestId),
                404
            );
        }

        // 3. Conditional GET (ETag and If-Modified-Since caching)
        $etag = '"' . md5($file->storageKey) . '"';
        $lastModifiedTimestamp = $file->createdAt->getTimestamp();
        $lastModifiedHeader = gmdate('D, d M Y H:i:s', $lastModifiedTimestamp) . ' GMT';

        $ifNoneMatch = $request->header('if-none-match');
        if ($ifNoneMatch !== null && trim($ifNoneMatch) === $etag) {
            return Response::notModified($etag, ['Last-Modified' => $lastModifiedHeader]);
        }

        $ifModifiedSince = $request->header('if-modified-since');
        if ($ifModifiedSince !== null) {
            $sinceTime = strtotime($ifModifiedSince);
            if ($sinceTime !== false && $sinceTime >= $lastModifiedTimestamp) {
                return Response::notModified($etag, ['Last-Modified' => $lastModifiedHeader]);
            }
        }

        // 4. Open physical file stream
        try {
            $stream = $this->storageBackend->openRead($file->projectId, $file->storageKey);
        } catch (StorageObjectNotFoundException) {
            return Response::error(
                new ErrorEnvelope('file_not_found', 'Stored file object is missing.', $request->requestId),
                404
            );
        } catch (\Throwable) {
            return Response::error(
                new ErrorEnvelope('storage_error', 'Failed to retrieve stored file.', $request->requestId),
                500
            );
        }

        $asDownload = ($request->queryParams['download'] ?? null) === '1';

        return Response::fileStream(
            streamResource: $stream,
            sizeBytes: $file->sizeBytes,
            mimeType: $file->mimeType,
            filename: $file->originalName,
            asDownload: $asDownload,
            etag: $etag,
            lastModified: $lastModifiedHeader
        );
    }
}
