<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Credential;

use FileVault\ApiKey\ApiKeyId;
use FileVault\ApiKey\Exception\MalformedApiKeyCredentialException;

final class ApiKeyCredentialParser
{
    public const PREFIX = 'fvk_';

    public function parse(#[\SensitiveParameter] string $credential): ParsedApiKeyCredential
    {
        if ($credential === '') {
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        if (!str_starts_with($credential, self::PREFIX)) {
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        $withoutPrefix = substr($credential, strlen(self::PREFIX));

        if (strlen($withoutPrefix) !== 66) { // 22 (public) + 1 (_) + 43 (secret)
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        if ($withoutPrefix[22] !== '_') {
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        $encodedPublicId = substr($withoutPrefix, 0, 22);
        $encodedSecret = substr($withoutPrefix, 23);

        if (strlen($encodedPublicId) !== 22) {
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        if (strlen($encodedSecret) !== 43) {
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        $publicBytes = Base64Url::decode($encodedPublicId);
        if ($publicBytes === null || strlen($publicBytes) !== 16) {
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        $secretBytes = Base64Url::decode($encodedSecret);
        if ($secretBytes === null || strlen($secretBytes) !== 32) {
            throw new MalformedApiKeyCredentialException('Invalid API key credential format.');
        }

        return new ParsedApiKeyCredential(new ApiKeyId($publicBytes), $secretBytes);
    }
}
