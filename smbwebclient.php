<?php

# smbwebclient.php --- web interface to smbclient

# $Id$

# Copyright (C) 2003-2006 Victor M. Varela <vmvarela@gmail.com>

# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.

define ('S_IFMT',   0170000); # type of file
define ('S_IFIFO',  0010000); # named pipe (fifo)
define ('S_IFCHR',  0020000); # character special
define ('S_IFDIR',  0040000); # directory
define ('S_IFBLK',  0060000); # block special
define ('S_IFREG',  0100000); # regular
define ('S_IFLNK',  0120000); # symbolic link
define ('S_IFSOCK', 0140000); # socket
define ('S_IFWHT',  0160000); # whiteout
define ('S_ISUID',  0004000); # set user id on execution
define ('S_ISGID',  0002000); # set group id on execution
define ('S_ISVTX',  0001000); # save swapped text even after use
define ('S_IRUSR',  0000400); # read permission, owner
define ('S_IWUSR',  0000200); # write permission, owner
define ('S_IXUSR',  0000100); # execute/search permission, owner

class samba_stream

  {private static $__cache;
   private $url, $path, $mode, $type='?', $stream, $tmpfile='';

   public function __destruct ()
     {if ($this->tmpfile <> '')
         {unlink($this->tmpfile);}}

   public function stream_open ($path, $mode, $options, $opened_path)
     {list($this->url, $this->path, $this->mode) = array($path, parse_url($path), $mode);
      if ($mode <> 'r' && $mode <> 'w')
         {trigger_error('only r/w modes allowed', E_USER_ERROR);}
      if ($this->query_type() <> '?')
         {trigger_error('error in path', E_USER_ERROR);}
      switch ($mode)
             {case 'r': $this->download(); break;
              case 'w': $this->tmpfile = tempnam('/tmp', 'smb.up.');
                        $this->stream = fopen($this->tmpfile, 'w');}
      return true;}

   public function stream_close ()
     {return fclose($this->stream);}

   public function stream_read ($count)
     {return fread($this->stream, $data);}

   public function stream_write ($data)
     {return fwrite($this->stream, $data);}

   public function stream_eof ()
     {return feof($this->stream);}

   public function stream_tell ()
     {return ftell($this->stream);}

   public function stream_seek ($offset, $whence=null)
     {return fseek($this->stream, $offset, $whence);}

   public function stream_stat ()
     {return $this->url_stat($this->get_url());}

   public function stream_flush ()
     {if ($mode == 'w')
         {$rp = $this->get_resource_path();
          return $this->smbclient_command('put "'.$this->tmpfile.'" "'.$rp.'"');}}

   public function unlink ($path)
     {$this->path = parse_url($path);
      if ($this->query_type() <> '?')
         {trigger_error('error in path', E_USER_ERROR);}
      $rp = $this->get_resource_path();
      # i do no know howto delete a file without doing chdir first !!!
      $cmd = 'cd "'.dirname($rp).'"; del "'.basename($rp).'"';
      return $this->smbclient_command($cmd);}

   public function rename ($path_from, $path_to)
     # fix: this function only renames files/folders in same path
     {$this->path = parse_url($path_from);
      if ($this->query_type() <> '?')
         {trigger_error('error in path_from', E_USER_ERROR);}
      $rp = $this->get_resource_path();
      $cmd = 'cd "'.dirname($rp).'"; rename "'.basename($rp).'" "'.basename($path_to).'"';
      return $this->smbclient_command($cmd);}

   public function mkdir ($path, $mode, $options)
     {$this->path = parse_url($path);
      if ($this->query_type() <> '?')
         {trigger_error('error in path', E_USER_ERROR);}
      return $this->smbclient_command('mkdir "'.$this->get_resource_path().'"');}

   public function rmdir ($path, $options)
     {$this->path = parse_url($path);
      if (in_array($this->query_type(), array('server', 'resource')))
         {trigger_error('error in path', E_USER_ERROR);}
      return $this->smbclient_command('rmdir "'.$this->get_resource_path().'"');}

   public function dir_opendir ($path, $options)
     {$this->path = parse_url($path);
      $type = $this->query_type();
      switch ($type)
        {case 'network': $browser = $this->get_network_browser();
                         $this->smbclient_browse($browser); break;
         case 'domain':  $browser = $this->get_master($this->get_resource_name());
                         $this->smbclient_browse($browser); break;
         case 'host':    $this->smbclient_browse(); break;}
      print_r($this->__cache);
      return true;}

   public function url_stat ($path, $flags)
     {$p = parse_url($path);
      $type = $this->query_type($p['path']);
      return true;}

   public function dir_readdir ()
     {switch ($this->dir_type)
         {case 'host': if ($this->dir_index >= count($this->dir['shares']))
                          {return false;}
                       else
                          {return $this->dir['shares'][$this->dir_index++]['name'];}}}

   public function dir_rewinddir ()
     {$this->dir_index = 0;}

   public function dir_closedir ()
     {return true;}

   protected function get_user ()
     {return @$this->path['user'];}

   protected function get_pass ()
     {return @$this->path['pass'];}

   protected function get_host ()
     {return strtoupper(@$this->path['host']);}

   protected function get_url ()
     {return @$this->url;}

   protected function get_path ()
     {return $this->fix_path(@$this->path['path']);}

   protected function fix_path ($path='')
     {return '/'.preg_replace(array('/^\//', '/\/$/'), '', $path);}

   protected function get_resource_path ()
     {return preg_replace('/^\/([^\/]+)/', '', $this->get_path());}

   protected function get_resource_name ()
     {$a = split('/', $this->get_path()); return $a[1];}

   protected function query_type ($path='', $host='')
     {$p = ($path == '') ? $this->get_path() : $this->fix_path($path);
      $h = ($host == '') ? $this->get_host() : $host;
      if ($h == 'NETWORK')
         {return ($p == '/') ? 'network' : 'domain';}
      else
         {return ($p == '/') ? 'host' : (substr_count($p, '/') > 1 ? '?' : 'resource');}}

   protected function download ()
     {$this->tmpfile = tempnam('/tmp', 'smb.down.');
      $this->smbclient_command('get "'.$this->get_resource_path().'" "'.$this->tmpfile.'"');
      $this->stream = fopen ($this->tmpfile, 'r'); }

   protected function get_network_browser ()
     {return 'NASHKI';}

   protected function get_master ($domain)
     {return 'NASHKI';}

   protected function smbclient_command ($command)
     {putenv('USER='.$this->get_user().'%'.$this->get_pass());
      $cmd = 'smbclient '.escapeshellarg('//'.$this->get_host().'/'.$this->get_resource_name()).
             ' -b 1200 '.
             ' -d 0'.
             ' -O '.escapeshellarg('TCP_NODELAY IPTOS_LOWDELAY SO_KEEPALIVE SO_RCVBUF=8192 SO_SNDBUF=8192').
             ' -c '.escapeshellarg($command).
             ' -N 2>&1';
      print ($output = shell_exec($cmd));}

   protected function smbclient_browse ($host='')
     {$u = $this->get_user();
      $h = ($host == '') ? $this->get_host() : $host;
      if (! isset($this->__cache['smbclient'][$u][$h]))
         {putenv('USER='.$this->get_user().'%'.$this->get_pass());
          $output = popen('smbclient -L '.escapeshellarg($host).' -d 0 -N 2>&1', 'r');
          $r = array();
          while (! feof($output))
            {$line = fgets($output, 4096);
             if (preg_match('/^\tSharename[ ]+Type[ ]+Comment/', $line, $regs))
                {$line = fgets($output, 4096); break;}
             elseif (preg_match('/^Domain=\[(.*)\] OS=\[(.*)\] Server=\[(.*)\]/', $line, $regs))
                {list(,$r['domain'], $r['os'], $r['server']) = $regs;}}
          while (! feof($output))
            {$line = fgets($output, 4096);
             if (preg_match('/^\tServer   [ ]+Comment/', $line, $regs))
                {$line = fgets($output, 4096); break;}
             elseif (preg_match('/^\t(.*)[ ]+(Disk|Printer)[ ]+(.*)/', $line, $regs))
                {$r['shares'][trim($regs[1])] = strtolower($regs[2]);}}
          while (! feof($output))
            {$line = fgets($output, 4096);
             if (preg_match('/^\tWorkgroup[ ]+Master/', $line, $regs))
                {$line = fgets($output, 4096); break;}
             else
                {sscanf($line, "%20s%s", $name, $comment);
                 $this->__cache['servers'][trim($name)] = '';}}
          while (! feof($output))
            {$line = fgets($output, 4096);
             sscanf($line, "%20s%s", $name, $master);
             $name = trim($name);
             $master = trim($master);
             $this->__cache['domains'][$name] = $master;
             $this->__cache['servers'][$master] = '';}
          pclose($output);
          $this->__cache['smbclient'][$u][$h] = $r;}
      return $this->__cache[$u][$h];}}


stream_wrapper_register('smb', 'samba_stream') or die('Failed to register protocol');

# testing ...

/*
$f = fopen('smb://nashki/Archivos/samba/UPO.doc', 'r');
$stat = fstat($f);
print_r($stat);
fclose($f);
*/

/*
$f = fopen('smb://nashki/Archivos/samba/prueba.txt', 'w');
fputs($f, 'prueba');
fclose($f);
*/

# unlink('smb://nashki/Archivos/samba/prueba.txt');
# rmdir ('smb://nashki/Archivos/samba/xxx');

$d = opendir('smb://network/dominio');
/*
while (($s = readdir($d)) !== false)
      {$t = filetype('smb://network/'.$s); echo "$s : $t\n";}*/
closedir($d);

?>