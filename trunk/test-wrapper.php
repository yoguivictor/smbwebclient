<?php

include './smbwebclient.php';


$smbclientpath = 'smbclient://sprfiles/TI';
$filepath = './TI';

smbclient_stream_wrapper::SetAuth('isotroldom\vmvarela', 'queypo');




$path = $smbclientpath;
$dir  = $path.'/test-vmvarela';
$errdir  = $path.'/test-vmvarelaXXX';
$filename = $dir.'/dir1/nombreráro.txt';
$errfile = $filename.'XXX';

/*
echo "\n".$smbclientpath.":";
print_r(stat($smbclientpath.'/test-vmvarela'));
echo "\n".$filepath.":";
print_r(stat($filepath.'/test-vmvarela'));
exit;
*/




if (! @mkdir($dir)) echo "ya existe test-vmvarela\n";
if (! @mkdir($dir.'/dir1')) echo "ya existe dir1\n";
if (! @mkdir($dir.'/dir2')) echo "ya existe dir2\n";
if (! rmdir($dir.'/dir2')) echo "no existe dir2\n";




// smbclient_stream_wrapper::$debugLevel = 3;



# FALLO: ARCHIVO CON COMILLAS DOBLES "

$fd = fopen($filename, 'w');
fputs($fd, 'ok');
fclose($fd);

$fd = fopen($filename, 'r');
$text = fread($fd, 100);
echo $text."\n";
fclose($fd);

foreach (array($path, $dir, $errdir, $filename, $errfile) as $x) {
  print ($x . (file_exists($x) ? " " : " NOT ") . "file_exists\n");
  print ($x . (is_dir($x) ? " " : " NOT ") . "is_dir\n");
  print ($x . (is_executable($x) ? " " : " NOT ") . "is_executable\n");
  print ($x . (is_file($x) ? " " : " NOT ") . "is_file\n");
  print ($x . (is_link($x) ? " " : " NOT ") . "is_link\n");
  print ($x . (is_readable($x) ? " " : " NOT ") . "is_readable\n");
  print ($x . (is_writable($x) ? " " : " NOT ") . "is_writable\n");
}

?>