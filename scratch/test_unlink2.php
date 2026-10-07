<?php
$dir = sys_get_temp_dir() . '/test_unlink_fail2_' . uniqid();
@mkdir($dir);
$file = $dir . '/test.txt';
file_put_contents($file, 'hello');
chmod($file, 0444);
$result = @unlink($file);
var_dump("chmod file 0444: ", $result);
chmod($file, 0777);
@unlink($file);
rmdir($dir);
