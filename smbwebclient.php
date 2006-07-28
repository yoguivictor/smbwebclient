<?php
## smbwebclient.php --- web interface to smbclient

# Copyright (C) 2003-2006 Victor M. Varela <vmvarela@gmail.com>

# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.

# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.

class samba_stream
    {private $path, $mode, $status, $type='?', $stream, $tmpfile='';

     function __destruct ()
        { if ($this->tmpfile <> '') unlink($this->tmpfile); }

     function stream_open ($path, $mode, $options, $opened_path)
        {list($this->path, $this->mode) = array(parse_url($path), $mode);
         if ($mode <> 'r' && $mode <> 'w')
           {trigger_error('only r/w modes allowed', E_USER_ERROR);}
         if (in_array($this->query_type(), array('server', 'resource')))
           {trigger_error('cannot open a server or resource', E_USER_ERROR);}
         switch ($mode)
           {case 'r': $this->download(); break;
            case 'w':
              $this->tmpfile = tempnam('/tmp', 'smb.up.');
              $this->stream = fopen($this->tmpfile, 'w');}
         return true;}

     # this is only a temp file
     function stream_close () {return fclose($this->stream);}
     function stream_read ($count) {return fread($this->stream, $data);}
     function stream_write ($data)  {return fwrite($this->stream, $data);}
     function stream_eof ()  {return feof($this->stream);}
     function stream_tell ()  {return ftell($this->stream);}
     function stream_seek ($offset, $whence=null)
        {return fseek($this->stream, $offset, $whence);}


     function stream_stat () {return fstat($this->stream);}

     function stream_flush ()
       {$rp = $this->get_resource_path();
        return $this->smbclient_command('put "'.$this->tmpfile.'" "'.$rp.'"'); }

     function unlink ($path)
       {$this->path = parse_url($path);
        if (in_array($this->query_type(), array('server', 'resource')))
          {trigger_error('cannot unlink a server or resource', E_USER_ERROR);}
        $rp = $this->get_resource_path();
        # i do no know why I cannot delete a file without doing cd  first !!!
        $cmd = 'cd "'.dirname($rp).'"; del "'.basename($rp).'"';
        return $this->smbclient_command($cmd); }

     function mkdir ($path, $mode, $options)
       {$this->path = parse_url($path);
        if (in_array($this->query_type(), array('server', 'resource')))
          {trigger_error('cannot mkdir a server or resource', E_USER_ERROR);}
        return $this->smbclient_command('mkdir "'.$this->get_resource_path().'"');}

     function rmdir ($path, $options)
       {$this->path = parse_url($path);
        if (in_array($this->query_type(), array('server', 'resource')))
          {trigger_error('cannot rmdir a server or resource', E_USER_ERROR);}
        return $this->smbclient_command('rmdir "'.$this->get_resource_path().'"');}

     # TODO
     function rename ($path_from, $path_to) {}
     function dir_opendir ($path, $options) {}
     function url_stat ($path, $flags) {}
     function dir_readdir () {}
     function dir_rewinddir () {}
     function dir_closedir () {}

     function get_user () {return @$this->path['user'];}
     function get_pass () {return @$this->path['pass'];}
     function get_host () {return @$this->path['host'];}

     function get_path ()
        # returns '/' + path without ^/ nor /$
        {return '/'.preg_replace(array('/^\//', '/\/$/'), '', @$this->path['path']);}

     function get_resource_path ()
        # deletes /* (/resource name) at beginning of path
        {return preg_replace('/^\/([^\/]+)/', '', $this->get_path());}

     function get_resource_name ()
        {$a = split('/', $this->get_path()); return $a[1];}

     protected function query_type ()
        {if ($this->type <> '?') return $this->type;
         # selects type (host or resource) from path
         switch (count(split('/',$this->get_path())))
           {case 1: return 'host'; break;
            case 2: return 'resource'; break;
            default: return '?';}}

     protected function download ()
        {$this->tmpfile = tempnam('/tmp', 'smb.down.');
         $this->smbclient_command('get "'.$this->get_resource_path().'" "'.$this->tmpfile.'"');
         $this->stream = fopen ($this->tmpfile, 'r'); }

     protected function smbclient_command ($command)
        {putenv('USER='.$this->get_user().'%'.$this->get_pass());
         $cmd = 'smbclient '.escapeshellarg('//'.$this->get_host().'/'.$this->get_resource_name()).
                ' -b 1200 -d 0 -O '.escapeshellarg('TCP_NODELAY IPTOS_LOWDELAY SO_KEEPALIVE SO_RCVBUF=8192 SO_SNDBUF=8192').
                ' -c '.escapeshellarg($command).
                ' -N 2>1&';
         $output = shell_exec($cmd); print $output;}}


# testing ...
 
stream_wrapper_register('smb', 'samba_stream') or die('Failed to register protocol');

/*
$f = fopen('smb://victor:victor@nashki/Archivos/samba/UPO.doc', 'r');
$stat = fstat($f);
print_r($stat);
fclose($f);
*/

/*
$f = fopen('smb://nashki/Archivos/samba/prueba.txt', 'w');
fputs($f, 'prueba');
fclose($f);
*/

// unlink('smb://nashki/Archivos/samba/prueba.txt');

// rmdir ('smb://nashki/Archivos/samba/xxx');

?>