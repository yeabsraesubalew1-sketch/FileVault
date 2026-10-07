<?php
namespace FileVault\Storage\Local;

function random_bytes($length) {
    return str_repeat('a', $length);
}

require __DIR__ . '/../src/Storage/Exception/StorageException.php';
require __DIR__ . '/../src/Storage/Exception/StorageOperationException.php';
require __DIR__ . '/../src/Storage/Local/LocalFilesystemStorage.php';

$rc = new \ReflectionClass(LocalFilesystemStorage::class);
$method = $rc->getMethod('generateStorageKey');
$method->setAccessible(true);
$obj = $rc->newInstanceWithoutConstructor();
var_dump($method->invoke($obj));
