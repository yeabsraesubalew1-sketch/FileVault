<?php
$dir = sys_get_temp_dir() . '/test_unlink_fail3_' . uniqid();
@mkdir($dir);
$active = $dir . '/active.txt';
$trash = $dir . '/trash.txt';
file_put_contents($active, 'hello');
chmod($active, 0444);

$link = @link($active, $trash);
$unlink = @unlink($active);

var_dump("link: ", $link);
var_dump("unlink: ", $unlink);

chmod($active, 0777);
chmod($trash, 0777);
@unlink($active);
@unlink($trash);
rmdir($dir);
