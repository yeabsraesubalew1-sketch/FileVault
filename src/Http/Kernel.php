<?php

declare(strict_types=1);

namespace FileVault\Http;

use FileVault\Foundation\Bootstrap\App;
use FileVault\Http\Controller\AdminController;
use FileVault\Http\Controller\FileController;
use FileVault\Http\Controller\ProjectController;
use FileVault\Http\Controller\PublicDeliveryController;
use FileVault\Observability\ErrorEnvelope;
use FileVault\Observability\ExceptionMapper;
use FileVault\Observability\Logger;
use Throwable;

final class Kernel
{
    private Router $router;
    private Logger $logger;

    public function __construct(
        private readonly App $app
    ) {
        $this->router = new Router();
        $this->logger = new Logger();
        $this->registerRoutes();
    }

    public static function create(string $basePath): self
    {
        $app = App::bootstrap($basePath);
        return new self($app);
    }

    public function handle(Request $request): Response
    {
        // 1. CORS Preflight
        $preflight = CorsHandler::handlePreflight($request);
        if ($preflight !== null) {
            return $preflight;
        }

        try {
            // 2. Route Matching
            $match = $this->router->match($request->method, $request->path);

            if ($match === null) {
                $response = Response::error(
                    new ErrorEnvelope('not_found', 'Route not found: ' . $request->path, $request->requestId),
                    404
                );
                return CorsHandler::applyHeaders($request, $response);
            }

            if (isset($match['methodNotAllowed'])) {
                $allowed = implode(', ', $match['methodNotAllowed']);
                $response = Response::error(
                    new ErrorEnvelope('method_not_allowed', 'Method not allowed for this route.', $request->requestId),
                    405,
                    ['Allow' => $allowed]
                );
                return CorsHandler::applyHeaders($request, $response);
            }

            // 3. Authentication Check
            if ($match['authRequired']) {
                $apiKey = $request->apiKey();
                if ($apiKey === null || trim($apiKey) === '') {
                    $response = Response::error(
                        new ErrorEnvelope('authentication_required', 'Authentication credentials are required.', $request->requestId),
                        401
                    );
                    return CorsHandler::applyHeaders($request, $response);
                }

                $authenticated = $this->app->getAuthenticateApiKey()->authenticate($apiKey);
                $project = $this->app->getProjectRepository()->findById($authenticated->projectId);

                if ($project === null) {
                    $response = Response::error(
                        new ErrorEnvelope('project_not_found', 'Authenticated project not found.', $request->requestId),
                        404
                    );
                    return CorsHandler::applyHeaders($request, $response);
                }

                $request->authenticatedApiKey = $authenticated;
                $request->authenticatedProject = $project;
            }

            // 4. Dispatch Handler
            /** @var Response $response */
            $response = ($match['handler'])($request, ...$match['params']);

            return CorsHandler::applyHeaders($request, $response);
        } catch (Throwable $e) {
            $isDebug = $this->app->getConfig()->appDebug;
            $mapped = ExceptionMapper::map($e, $request->requestId, $isDebug);

            $this->logger->log($mapped['logLevel'], $e->getMessage(), [
                'request_id' => $request->requestId,
                'path' => $request->path,
                'method' => $request->method,
                'exception' => $e,
            ]);

            $response = Response::error($mapped['envelope'], $mapped['status']);
            return CorsHandler::applyHeaders($request, $response);
        }
    }

    private function registerRoutes(): void
    {
        $appUrl = $this->app->getConfig()->appUrl;

        // Controllers
        $deliveryController = new PublicDeliveryController(
            $this->app->getFileRepository(),
            $this->app->getProjectRepository(),
            $this->app->getStorageBackend()
        );

        $fileController = new FileController(
            $this->app->getFileLifecycle(),
            $this->app->getFileRepository(),
            $this->app->getProjectRepository(),
            $appUrl
        );

        $projectController = new ProjectController();

        // Health Checks
        $healthHandler = fn (Request $req) => Response::json([
            'status' => 'ok',
            'app' => 'FileVault',
            'version' => '1.0.0',
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ]);
        $this->router->get('/', $healthHandler);
        $this->router->get('/health', $healthHandler);
        $this->router->get('/api/v1/health', $healthHandler);

        // Favicon fallback routes
        $this->router->get('/favicon.ico', function () {
            $path = dirname(__DIR__, 2) . '/public/favicon.ico';
            if (file_exists($path)) {
                return new Response(200, ['Content-Type' => 'image/vnd.microsoft.icon', 'Cache-Control' => 'public, max-age=86400'], (string) file_get_contents($path));
            }
            return new Response(204, ['Content-Type' => 'image/vnd.microsoft.icon'], '');
        }, false);
        $this->router->get('/favicon.svg', function () {
            $path = dirname(__DIR__, 2) . '/public/favicon.svg';
            if (file_exists($path)) {
                return new Response(200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400'], (string) file_get_contents($path));
            }
            return new Response(204, ['Content-Type' => 'image/svg+xml'], '');
        }, false);

        // Public File Delivery (M08)
        $this->router->get('/files/{storageKey:.*}', [$deliveryController, 'deliver'], false);
        $this->router->get('/f/{id}', [$deliveryController, 'deliverById'], false);

        // Authenticated Project API (M02)
        $this->router->get('/api/v1/project', [$projectController, 'get'], true);

        // Authenticated File Operations (M02, M07)
        $this->router->post('/api/v1/files', [$fileController, 'upload'], true);
        $this->router->get('/api/v1/files', [$fileController, 'list'], true);
        $this->router->get('/api/v1/files/{id}', [$fileController, 'get'], true);
        $this->router->delete('/api/v1/files/{id}', [$fileController, 'delete'], true);
        $this->router->post('/api/v1/files/{id}/replace', [$fileController, 'replace'], true);
        $this->router->post('/api/v1/files/{id}/restore', [$fileController, 'restore'], true);
        $this->router->delete('/api/v1/files/{id}/purge', [$fileController, 'purge'], true);

        // Admin Interface (M09)
        $adminController = new AdminController($this->app);
        $this->router->get('/admin/login', [$adminController, 'loginView'], false);
        $this->router->post('/admin/login', [$adminController, 'login'], false);
        $this->router->get('/admin/logout', [$adminController, 'logout'], false);
        $this->router->post('/admin/logout', [$adminController, 'logout'], false);
        $this->router->get('/admin', [$adminController, 'dashboard'], false);
        $this->router->post('/admin/projects', [$adminController, 'createProject'], false);
        $this->router->post('/admin/projects/{id}/status', [$adminController, 'updateProjectStatus'], false);
        $this->router->post('/admin/projects/{id}/quota', [$adminController, 'updateProjectQuota'], false);
        $this->router->post('/admin/projects/{projectId}/keys', [$adminController, 'createApiKey'], false);
        $this->router->post('/admin/keys/{keyId}/revoke', [$adminController, 'revokeApiKey'], false);
    }
}
