<?php

include './smbwebclient.php';

smbclient_stream_wrapper::SetAuth('isotroldom\vmvarela', 'queypo');
$path = 'smbclient://sprfiles/TI';

mkdir($path.'/test-vmvarela');
mkdir($path.'/test-vmvarela/dir1');
mkdir($path.'/test-vmvarela/dir2');
rmdir($path.'/test-vmvarela/dir2');

$filename = $path.'/test-vmvarela/dir1/fil1.txt';
$errfile = $filename.'_';

$fd = fopen($filename, 'w');
fputs($fd, 'ok');
fclose($fd);


# is_file($filename) OR print("error is_file $filename\n");
# file_exists($filename) OR print("error file_exists $filename\n");

# is_file($errfile) OR print("error is_file $errfile\n");
# file_exists($errfile) OR print("error file_exists $errfile\n");

is_dir($path) OR print("share $path not is_dir\n");
file_exists($path) OR print("share $path not file_exists\n");

# echo "stat $filename:\n";
# print_r(stat($filename));

# $fd = fopen($filename, 'r');
# $text = fread($fd, 100);
# echo $text."\n";
# fclose($fd);




?>