<?php

declare(strict_types=1);

namespace FileVault\Http;

final class Router
{
    /**
     * @var list<array{method: string, pattern: string, regex: string, paramNames: list<string>, handler: callable, authRequired: bool}>
     */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $authRequired = false): void
    {
        $method = strtoupper($method);
        $paramNames = [];

        // Convert pattern like /files/{storageKey:.*} or /api/v1/files/{id} into regex
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            $customPattern = $matches[2] ?? '[^/]+';
            return '(' . $customPattern . ')';
        }, $pattern);

        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
            'authRequired' => $authRequired,
        ];
    }

    public function get(string $pattern, callable $handler, bool $authRequired = false): void
    {
        $this->add('GET', $pattern, $handler, $authRequired);
    }

    public function post(string $pattern, callable $handler, bool $authRequired = false): void
    {
        $this->add('POST', $pattern, $handler, $authRequired);
    }

    public function delete(string $pattern, callable $handler, bool $authRequired = false): void
    {
        $this->add('DELETE', $pattern, $handler, $authRequired);
    }

    public function options(string $pattern, callable $handler): void
    {
        $this->add('OPTIONS', $pattern, $handler, false);
    }

    /**
     * @return array{handler: callable, params: array<string, string>, authRequired: bool}|array{methodNotAllowed: list<string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $allowedMethodsForPath = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                if ($route['method'] === $method) {
                    $params = [];
                    foreach ($route['paramNames'] as $index => $name) {
                        $params[$name] = $matches[$index + 1] ?? '';
                    }

                    return [
                        'handler' => $route['handler'],
                        'params' => $params,
                        'authRequired' => $route['authRequired'],
                    ];
                }

                $allowedMethodsForPath[] = $route['method'];
            }
        }

        if (!empty($allowedMethodsForPath)) {
            return [
                'methodNotAllowed' => array_unique($allowedMethodsForPath),
            ];
        }

        return null;
    }
}
