<?php

declare(strict_types=1);

namespace FileVault\ApiKey\Credential;

use FileVault\ApiKey\ApiKeyId;

final class ApiKeyGenerator
{
    public const PREFIX = 'fvk_';

    public function generate(): CreatedApiKeyCredential
    {
        $publicBytes = random_bytes(16);
        $secretBytes = random_bytes(32);

        $publicId = new ApiKeyId($publicBytes);

        $encodedPublicId = Base64Url::encode($publicBytes);
        $encodedSecret = Base64Url::encode($secretBytes);

        $plaintextCredential = self::PREFIX . $encodedPublicId . '_' . $encodedSecret;

        return new CreatedApiKeyCredential($publicId, $plaintextCredential, $secretBytes);
    }
}
