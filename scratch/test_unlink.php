<?php
$dir = __DIR__ . '/test_unlink_fail';
@mkdir($dir);
$file = $dir . '/test.txt';
file_put_contents($file, 'hello');

// Try Windows trick
$fp = fopen($file, 'rb');
$result = @unlink($file);
var_dump("Windows trick (fopen): ", $result);
fclose($fp);

// Try Linux trick
chmod($dir, 0555);
$result2 = @unlink($file);
var_dump("Linux trick (chmod dir): ", $result2);
chmod($dir, 0777);
@unlink($file);
rmdir($dir);
