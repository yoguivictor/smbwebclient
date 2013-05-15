<?php
## $Id$
## by Victor M. Varela <vmvarela@gmail.com>

############## A 'smb' stream wrapper class using 'smbclient' #################

class samba_stream {

private static $__cache = array
 ('workgroups' => array(),
  'servers' => array(),
  'smbclient' => array());

private static $__config = array
 ('hide_system_shares' => true,
  'hide_printer_shares' => false);

private static $__regexp = array
 ("^added interface ip=(.*) bcast=(.*) nmask=(.*)\$" => 'skip',
  "Anonymous login successful" => 'skip',
  "^Domain=\[(.*)\] OS=\[(.*)\] Server=\[(.*)\]\$" => 'skip',
  "^\tSharename[ ]+Type[ ]+Comment\$" => 'shares',
  "^\t---------[ ]+----[ ]+-------\$" => 'skip',
  "^\tServer   [ ]+Comment\$" => 'servers',
  "^\t---------[ ]+-------\$" => 'skip',
  "^\tWorkgroup[ ]+Master\$" => 'workg',
  "^\t(.*)[ ]+(Disk|IPC)[ ]+IPC.*\$" => 'skip',
  "^\tIPC\\\$(.*)[ ]+IPC" => 'skip',
  "^\t(.*)[ ]+(Disk|Printer)[ ]+(.*)\$" => 'share',
  '([0-9]+) blocks of size ([0-9]+)\. ([0-9]+) blocks available' => 'size',
  'Got a positive name query response from ' => 'skip',
  "^(session setup failed): (.*)\$" => 'error',
  '^(.*): ERRSRV - ERRbadpw' => 'error',
  "^Error returning browse list: (.*)\$" => 'error',
  "^tree connect failed: (.*)\$" => 'error',
  "^(Connection) to .* failed\$" => 'error',
  '^NT_STATUS_(.*) ' => 'error',
  '^NT_STATUS_(.*)\$' => 'error',
  'ERRDOS - ERRbadpath \((.*).\)' => 'error',
  'cd (.*): (.*)\$' => 'error',
  '^cd (.*): NT_STATUS_(.*)' => 'error',
  "^\t(.*)\$" => 'srvorwg',
  "^([0-9]+)[ ]+([0-9]+)[ ]+(.*)\$" => 'L_JOB',
  "^Job ([0-9]+) cancelled" => 'L_CANCEL',
  '^[ ]+(.*)[ ]+([0-9]+)[ ]+(Mon|Tue|Wed|Thu|Fri|Sat|Sun)[ ](Jan|Feb|Mar|Apr|'.
  'May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[ ]+([0-9]+)[ ]+([0-9]{2}:[0-9]{2}:[0-9]{2})'.
  '[ ]([0-9]{4})$' => 'files',
  "^message start: ERRSRV - (ERRmsgoff)" => 'error');

private $url, $path, $mode, $flags=0, $type='?', $stream, $tmpfile='';
private $dir, $dir_index=0;

## This method is called immediately after your stream object is created.
## - url specifies the URL that was passed to fopen() and that this object is
##   expected to retrieve. You can use parse_url()  to break it apart.
## - mode is the mode used to open the file, as detailed for fopen(). You are
##   responsible for checking that mode is valid for the path requested.
## - options holds additional flags set by the streams API. It can hold one or
##   more of the following values OR'd together.
##      STREAM_USE_PATH	        If path is relative, search for the resource
##                              using the include_path.
##      STREAM_REPORT_ERRORS	If this flag is set, you are responsible for
##                              raising errors using trigger_error() during
##                              opening of the stream. If this flag is not set,
##                              you should not raise any errors.
## If the path is opened successfully, and STREAM_USE_PATH is set in options,
## you should set opened_path to the full path of the file/resource that was
## actually opened.
## If the requested resource was opened successfully, you should return TRUE,
## otherwise you should return FALSE
## 
public function stream_open ($url, $mode, $options, $opened_path)
 {list($this->url, $this->parsed_url, $this->mode) =
      array($url, parse_url($url), $mode);
  if ($mode <> 'r' && $mode <> 'w')
     {trigger_error('only r/w modes allowed', E_USER_ERROR);}
  if ($this->query_type() <> '?')
     {trigger_error('error in path', E_USER_ERROR);}
  switch ($mode)
         {case 'r':  $this->download(); break;
          case 'w':  $this->tmpfile = tempnam('/tmp', 'smb.up.');
                 $this->stream = fopen($this->tmpfile, 'w');}
  return TRUE;}

## This method is called when the stream is closed, using fclose(). You must
## release any resources that were locked or allocated by the stream.
##
public function stream_close () {return fclose($this->stream);}

## This method is called in response to fread()  and fgets() calls on the
## stream. You must return up-to count bytes of data from the current read/write
## position as a string. If there are less than count  bytes available, return
## as many as are available. If no more data is available, return either FALSE
## or an empty string. You must also update the read/write position of the
## stream by the number of bytes that were successfully read.
##
public function stream_read ($count) {return fread($this->stream, $data);}

## This method is called in response to fwrite()  calls on the stream. You
## should store data  into the underlying storage used by your stream. If there
## is not enough room, try to store as many bytes as possible. You should return
## the number of bytes that were successfully stored in the stream, or 0 if none
## could be stored. You must also update the read/write position of the stream
## by the number of bytes that were successfully written.
##
public function stream_write ($data) {return fwrite($this->stream, $data);}

## This method is called in response to feof()  calls on the stream. You should
## return TRUE if the read/write position is at the end of the stream and if no
## more data is available to be read, or FALSE otherwise.
##
public function stream_eof () {return feof($this->stream);}

## This method is called in response to ftell()  calls on the stream. You should
## return the current read/write position of the stream.
##
public function stream_tell () {return ftell($this->stream);}

## This method is called in response to fseek()  calls on the stream. You should
## update the read/write position of the stream according to offset and whence.
## See fseek()  for more information about these parameters. Return TRUE if the
## position was updated, FALSE otherwise.
##
public function stream_seek ($offset, $whence=null)
 {return fseek($this->stream, $offset, $whence);}

## This method is called in response to fflush()  calls on the stream. If you
## have cached data in your stream but not yet stored it into the underlying
## storage, you should do so now. Return TRUE if the cached data was
## successfully stored (or if there was no data to store), or FALSE if the data
## could not be stored.
##
public function stream_flush ()
 {if ($mode == 'w')
     {$rp = $this->get_rpath();
      return $this->smbclient_do('put "'.$this->tmpfile.'" "'.$rp.'"');}}

## This method is called in response to fstat()  calls on the stream and should
## return an array containing the same values as appropriate for the stream. 
##
public function stream_stat () {return $this->url_stat($this->get_url());}

## This method is called in response to unlink()  calls on URL paths associated
## with the wrapper and should attempt to delete the item specified by path. It
## should return TRUE on success or FALSE on failure. In order for the
## appropriate error message to be returned, do not define this method if your
## wrapper does not support unlinking.
## Note: Userspace wrapper unlink method is not supported prior to PHP 5.0.0.
##
public function unlink ($url)
 {$this->parsed_url = parse_url($url);
  if ($this->query_type() <> '?')
     {trigger_error('error in url', E_USER_ERROR);}
  $rp = $this->get_rpath();
  $cmd = 'cd "'.dirname($rp)           # I don't know how to delete
       . '"; del "'.basename($rp).'"'; # files without chdir first
  return $this->smbclient_do($cmd);}

## This method is called in response to rename()  calls on URL paths associated
## with the wrapper and should attempt to rename the item specified by path_from
## to the specification given by path_to. It should return TRUE on success or
## FALSE on failure. In order for the appropriate error message to be returned,
## do not define this method if your wrapper does not support renaming.
## Note: Userspace wrapper rename method is not supported prior to PHP 5.0.0.
## FIX: this function only renames files/folders in same path 
##
public function rename ($url_from, $path_to)
 {$this->parsed_url = parse_url($url_from);
  if ($this->query_type() <> '?')
     {trigger_error('error in url_from', E_USER_ERROR);}
  $rp = $this->get_rpath();
  $cmd = 'cd "'. dirname($rp)
               . '"; rename "'
               . basename($rp). '" "'
               . basename($path_to).'"';
  return $this->smbclient_do($cmd);}

## This method is called in response to mkdir()  calls on URL paths associated
## with the wrapper and should attempt to create the directory specified by
## path. It should return TRUE on success or FALSE on failure. In order for the
## appropriate error message to be returned, do not define this method if your
## wrapper does not support creating directories. Posible values for options
## include STREAM_REPORT_ERRORS and STREAM_MKDIR_RECURSIVE.
## Note: Userspace wrapper mkdir method is not supported prior to PHP 5.0.0. 
##
public function mkdir ($url, $mode, $options)
 {$this->parsed_url = parse_url($url);
  if ($this->query_type() <> '?') {trigger_error('error in url', E_USER_ERROR);}
  return $this->smbclient_do('mkdir "'.$this->get_rpath().'"');}

## This method is called in response to rmdir()  calls on URL paths associated
## with the wrapper and should attempt to remove the directory specified by
## path. It should return TRUE on success or FALSE on failure. In order for the
## appropriate error message to be returned, do not define this method if your
## wrapper does not support removing directories. Possible values for options
## include STREAM_REPORT_ERRORS.
## Note: Userspace wrapper rmdir method is not supported prior to PHP 5.0.0. 
##
public function rmdir ($url, $options)
 {$this->parsed_url = parse_url($url);
  if ($this->query_type() <> '?') {trigger_error('error in url', E_USER_ERROR);}
  return $this->smbclient_do('rmdir "'.$this->get_rpath().'"');}

## This method is called immediately when your stream object is created for
## examining directory contents with opendir(). path specifies the URL that was
## passed to opendir() and that this object is expected to explore. You can use
## parse_url()  to break it apart.
##
public function dir_opendir ($url, $options)
 {$this->parsed_url = parse_url($url);
  switch ($type = $this->query_type())
         {case 'workgroup':
               $browser = $this->get_master_server($this->get_rname());
               $saved = $this->get_servers();
               $this->set_servers(array());
               $this->smbclient_list($browser);
               $this->dir = $this->get_servers();
               $this->set_servers($saved);
               break;
          case 'network': $this->dir = $this->get_workgroups(); break;
          case 'server':
               $this->dir = $this->get_shares($this->get_user(), $this->get_server());
               break;
          default:        $this->dir = $this->get_files();}
  return true;}

## This method is called in response to stat()  calls on the URL paths
## associated with the wrapper and should return as many elements in common with
## the system function as possible. Unknown or unavailable values should be set
## to a rational value (usually 0).
## flags holds additional flags set by the streams API. It can hold one or more
## of the following values OR'd together.
##    STREAM_URL_STAT_LINK  For resources with the ability to link to other
##                          resource (such as an HTTP Location: forward, or a
##                          filesystem symlink). This flag specified that only
##                          information about the link itself should be
##                          returned, not the resource pointed to by the link.
##                          This flag is set in response to calls to lstat(),
##                          is_link(), or filetype().
##    STREAM_URL_STAT_QUIET	If this flag is set, your wrapper should not raise
##                          any errors. If this flag is not set, you are
##                          responsible for reporting errors using the
##                          trigger_error() function during stating of the path.
##
public function url_stat ($url, $flags)
 {list($this->url, $this->parsed_url, $this->flags) = array
      ($url, parse_url($url), $flags);
  $info = $this->get_info($this->get_user(), $this->get_server(),
                          $this->get_rname(), $this->get_rpath());
  return array
         ('dev' => 0,
          'ino' => 0,
          'mode' => $info['type'] == 'file' ? 0 : 040000,
          'nlink' => 1,
          'uid' => 0,
          'gid' => 0,
          'size' => $info['size'],
          'atime' => $info['time'],
          'mtime' => $info['time'],
          'ctime' => $info['time']);}

## This method is called in response to readdir()  and should return a string
## representing the next filename in the location opened by dir_opendir().
##
public function dir_readdir () {return @$this->dir[$this->dir_index++];}

## This method is called in response to rewinddir()  and should reset the output
## generated by dir_readdir(). i.e.: The next call to dir_readdir() should
## return the first entry in the location returned by dir_opendir().
##
public function dir_rewinddir () {$this->dir_index = 0;}

## This method is called in response to closedir(). You should release any
## resources which were locked or allocated during the opening and use of the
## directory stream.
##
public function dir_closedir () {return true;}


### internal functions


## before destruct the object, tmpfile must be deleted
public function __destruct () {if ($this->tmpfile <> '') {unlink($this->tmpfile);}}

## read functions
private function get_user () {return @$this->parsed_url['user'];}
private function get_pass () {return @$this->parsed_url['pass'];}
private function get_server () {return strtolower(@$this->parsed_url['host']);}
private function get_url () {return @$this->url;}
private function get_path () {return $this->fix_path(@$this->parsed_url['path']);}

private function get_rpath ()
 {return preg_replace('/^\/([^\/]+)/', '', $this->get_path());}

private function get_rname ()
 {$a = explode('/', $this->get_path()); return $a[1];}

private function fix_path ($path='')
 {return '/'.preg_replace(array('/^\//', '/\/$/'), '', $path);}

private function get_files ($path='')
 {list($u, $h, $s, $p) = array
      ($this->get_user(),
       $this->get_server(),
       $this->get_rname(),
       ($path == '') ? $this->get_rpath() : $path);
  if (! isset(samba_stream::$__cache['smbclient'][$u][$h][$s][$p]))
     {$this->smbclient_do('cd "'.$p.'"; dir');}
  if (! isset(samba_stream::$__cache['smbclient'][$u][$h][$s][$p]))
     {print $p;
      print_r(samba_stream::$__cache);
      trigger_error("path does not exist //$h/$s/$p");}
  return array_keys(samba_stream::$__cache['smbclient'][$u][$h][$s][$p]);}

private function get_servers ()
 {if (! isset(samba_stream::$__cache['servers']))
     {$this->smbclient_list();}
  return samba_stream::$__cache['servers'];}

private function get_workgroups ()
 {$browser = $this->get_browser_server();
  $this->smbclient_list($browser);
  if (! isset(samba_stream::$__cache['workgroups']))
     return array_keys(samba_stream::$__cache['workgroups']);}

private function set_servers ($servers)
 {return samba_stream::$__cache['servers'] = $servers;} 

private function get_master_server ($wg)
 {if (isset(samba_stream::$__cache['workgroups'][$wg]))
     {return samba_stream::$__cache['workgroups'][$wg];}
  else
     {$this->get_workgroup();
  if (isset(samba_stream::$__cache['workgroups'][$wg]))
     {return samba_stream::$__cache['workgroups'][$wg];}
  else
     {return '?';}}}

private function get_browser_server ()
 {return ($b = $this->get_config('network_browser')) == '' ? 'localhost' : $b;}

private function query_type ($path='', $server='')
 {list($p, $s) = array
      (($path == '') ? $this->get_path() : $this->fix_path($path),
       ($server == '') ? $this->get_server() : $server);
  if ($s == 'network')
     {return ($p == '/') ? 'network' : 'workgroup';}
  else
     {return ($p == '/') ? 'server' : (substr_count($p, '/') > 1 ? '?' : 'share');}}

private function download ()
 {$this->tmpfile = tempnam('/tmp', 'smb.down.');
  $this->smbclient_do('get "'.$this->get_rpath().'" "'.$this->tmpfile.'"');
  $this->stream = fopen ($this->tmpfile, 'r');}

private function get_config ($var)
 {return isset(samba_stream::$__config[$var]) ? samba_stream::$__config[$var] : FALSE;}

private function smbclient_do ($command)
 {putenv('USER='.$this->get_user().'%'.$this->get_pass());
  $cmd = 'smbclient '
       . escapeshellarg('//'.$this->get_server().'/'.$this->get_rname())
       . ' -b 1200 '
       . ' -O '.escapeshellarg('TCP_NODELAY IPTOS_LOWDELAY SO_KEEPALIVE '
               .'SO_RCVBUF=8192 SO_SNDBUF=8192')
       . ' -c '.escapeshellarg($command);
  $this->parse_smbclient($cmd);}

private function smbclient_list ($server='')
 {list($u, $s) = array
      ($this->get_user(), ($server == '') ? $this->get_server() : $server);
  if (! $this->get_shares($u, $s))
     {putenv('USER='.$this->get_user().'%'.$this->get_pass());
      $cmd = 'smbclient -L '.escapeshellarg($h).' -d 0';
      $this->parse_smbclient($cmd);}
  return $this->get_shares($u, $s);}

private function parse_time ($m, $d, $y, $hhiiss)
 {list ($his, $im) = array
       (split(':', $hhiiss),
        1 + strpos("JanFebMarAprMayJunJulAugSepOctNovDec", $m) / 3);
  return mktime($his[0], $his[1], $his[2], $im, $d, $y);}

private function parse_share ($line)
 {list($name, $type) = array
      (trim(substr($line, 1, 15)), strtolower(substr($line, 17, 10)));
  $skip = ($this->get_config('hide_system_shares')
       && substr($name,-1) == '$');
  $skip = $skip || ($this->get_config('hide_printer_shares') 
       && $type == 'printer');
  if (! $skip)
     {list($u, $h) = array($this->get_user(), $this->get_server());
      $this->add_share($u, $h, $name, $type);}}

private function parse_srvorwg ($line, $mode = 'servers')
 {$name = trim(substr($line,1,21));
  if ($mode == 'servers') $name = strtolower($name);
  $master = strtolower(trim(substr($line, 22)));
  if ($mode == 'servers') $this->add_server($name);
  else $this->add_workgroup($name, $master);}

private function parse_file ($regs)
  {if (preg_match("/^(.*)[ ]+([D|A|H|S|R]+)$/", trim($regs[1]), $regs2))
      {list($attr, $name) = array(trim($regs2[2]), trim($regs2[1]));}
   else
      {list($attr, $name) = array ('', trim($regs[1]));}
   if ($name <> '.' && $name <> '..')
      {$type = (strpos($attr,'D') === FALSE) ? 'file' : 'folder';
   list($u, $h, $s, $p) = array
       ($this->get_user(),
        $this->get_server(),
        $this->get_rname(),
        $this->get_rpath());
   $this->set_info($u, $h, $s, $p, $name, array
    ('attr' => $attr,
     'size' => $regs[2],
     'time' => $this->parse_time($regs[4],$regs[5],$regs[7],$regs[6]),
     'type' => $type));}}

private function get_info($user, $server, $rname, $rpath)
 {$ppath = $this->fix_path(dirname($rpath));
  $name = basename($rpath);
  if ($server == 'network')
     {if ($rname <> '' && !in_array($rname, samba_stream::$__cache['servers']))
         {trigger_error("$rname is not a server", E_USER_ERROR);}
      return array('attr'=>'','size'=>0,'time'=>time(),'type'=>'folder');}
  elseif (! isset(samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath]))
     {$this->get_files($ppath);}
  if (! isset(samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath]))
     {trigger_error("error examining //$server/$rname/$ppath",E_USER_ERROR);}
  if (! isset(samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath][$name]))
     {trigger_error("object does not exist //$server/$rname/$ppath/$name", E_USER_ERROR);}
  return samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath][$name];}

private function set_info($user, $server, $rname, $rpath, $name, $value)
 {$ppath = $this->fix_path($rpath);
  samba_stream::$__cache['smbclient'][$user][$server][$rname][$rpath] = $value;}

private function add_server ($server)
 {if (! $this->is_server($server))
     {samba_stream::$__cache['servers'][] = $server;}}

private function is_server ($server)
 {return (! in_array($server, samba_stream::$__cache['servers']));}

private function add_workgroup ($workgroup, $master)
 {samba_stream::$__cache['workgroups'][$workgroup] = $master;
  $this->add_server($master);}

private function add_share ($user, $server, $share, $type)
 {samba_stream::$__cache['shares'][$user][$server][$share] = $type;}

private function get_shares ($user, $server)
 {return (isset(samba_stream::$__cache['shares'][$user][$server]))
         ? samba_stream::$__cache['shares'][$user][$server]
         : FALSE;}

private function parse_job ($regs)
 {list($name, $u, $h, $printer) = array
      ($regs[1].' '.$regs[3], $this->get_user(), $this->get_server(), $this->get_rname());
  samba_stream::$__cache['smbclient'][$u][$h][$printer][$name] = array
   ('type'=>'printjob',
    'id'=>$regs[1],
    'size'=>$regs[2]);}

private function parse_size ($regs)
 {list ($size, $avail) = array
       ($regs[1] * $regs[2], $regs[3] * $regs[2]);}

private function get_linetype ($line)
 {list($line_type, $regs) = array('skip', array());
  reset(samba_stream::$__regexp);
  foreach (samba_stream::$__regexp as $regexp => $type)
          {if (preg_match('/'.$regexp.'/', $line, $regs))
   	          {$line_type = $type;
   	           break;}}
           return array($line_type, $regs);}

private function parse_smbclient ($cmd)
 {print "{debug: $cmd}\n";
  $output = popen($cmd.' 2>&1', 'r');
  while (! feof($output))
        {$line = fgets($output, 4096);
         list($line_type, $regs) = $this->get_linetype($line);
         print "$line:$line_type\n";
         switch ($line_type)
                {case 'skip':    continue;
                 case 'shares':  $mode = 'shares';     break;
                 case 'servers': $mode = 'servers';    break;
                 case 'workg':   $mode = 'workgroups'; break;
                 case 'share':   $this->parse_share($line); break;
                 case 'srvorwg': $this->parse_srvorwg($line, $mode); break;
                 case 'files':   $this->parse_file($regs); break;
                 case 'jobs':    $this->parse_job($regs); break;
                 case 'size':    $this->parse_size($regs); break;
                 case 'error':   trigger_error('error '.$regs[1], E_USER_ERROR);}}
  pclose($output);}


}
## end of stream wrapper


stream_wrapper_register('smb', 'samba_stream')
 or die('Failed to register protocol');

?>