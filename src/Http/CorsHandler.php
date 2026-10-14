<?php

declare(strict_types=1);

namespace FileVault\Http;

final class CorsHandler
{
    public static function handlePreflight(Request $request): ?Response
    {
        if ($request->method !== 'OPTIONS') {
            return null;
        }

        $origin = $request->header('origin') ?? '*';

        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, X-API-Key, Content-Type, X-Request-ID, Accept',
            'Access-Control-Max-Age' => '86400',
        ];

        return Response::empty(204, $headers);
    }

    public static function applyHeaders(Request $request, Response $response): Response
    {
        $origin = $request->header('origin') ?? '*';

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, X-API-Key, Content-Type, X-Request-ID, Accept')
            ->withHeader('X-Request-ID', $request->requestId);
    }
}
