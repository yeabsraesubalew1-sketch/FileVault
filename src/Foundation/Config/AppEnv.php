<?php

declare(strict_types=1);

namespace FileVault\Foundation\Config;

enum AppEnv: string
{
    case Production = 'production';
    case Development = 'development';
    case Testing = 'testing';
}
