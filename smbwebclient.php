<?php
##
## $Id$
##
## by Victor M. Varela <vmvarela@gmail.com>
##
## This script is a web interface to a Windows Network.
## You need to have ''smbclient'' installed in your system
## in order to use this program.
##
## oooo

define ('l_skip', 1);
define ('l_shares', 2);
define ('l_servers', 3);
define ('l_workg', 4);
define ('l_share', 5);
define ('l_size', 6);
define ('l_error', 7);
define ('l_srvorwg', 8);
define ('l_jobs', 9);
define ('l_cancel', 10);
define ('l_files', 11);

class samba_stream
      {private static $__cache = array
               ('workgroups' => array(),
                'servers' => array(),
                'smbclient' => array());
       private static $__config = array
               ('hide_system_shares' => true,
                'hide_printer_shares' => false);
       private static $__regexp = array
               ("^added interface ip=(.*) bcast=(.*) nmask=(.*)\$" => l_skip,
                "Anonymous login successful" => l_skip,
                "^Domain=\[(.*)\] OS=\[(.*)\] Server=\[(.*)\]\$" => l_skip,
                "^\tSharename[ ]+Type[ ]+Comment\$" => l_shares,
                "^\t---------[ ]+----[ ]+-------\$" => l_skip,
                "^\tServer   [ ]+Comment\$" => l_servers,
                "^\t---------[ ]+-------\$" => l_skip,
                "^\tWorkgroup[ ]+Master\$" => l_workg,
                "^\t(.*)[ ]+(Disk|IPC)[ ]+IPC.*\$" => l_skip,
                "^\tIPC\\\$(.*)[ ]+IPC" => l_skip,
                "^\t(.*)[ ]+(Disk|Printer)[ ]+(.*)\$" => l_share,
                '([0-9]+) blocks of size ([0-9]+)\. ([0-9]+) blocks available' => l_size,
                'Got a positive name query response from ' => l_skip,
                "^(session setup failed): (.*)\$" => l_error,
                '^(.*): ERRSRV - ERRbadpw' => l_error,
                "^Error returning browse list: (.*)\$" => l_error,
                "^tree connect failed: (.*)\$" => l_error,
                "^(Connection) to .* failed\$" => l_error,
                '^NT_STATUS_(.*) ' => l_error,
                '^NT_STATUS_(.*)\$' => l_error,
                'ERRDOS - ERRbadpath \((.*).\)' => l_error,
                'cd (.*): (.*)\$' => l_error,
                '^cd (.*): NT_STATUS_(.*)' => l_error,
                "^\t(.*)\$" => l_srvorwg,
                "^([0-9]+)[ ]+([0-9]+)[ ]+(.*)\$" => l_job,
                "^Job ([0-9]+) cancelled" => l_cancel,
                '^[ ]+(.*)[ ]+([0-9]+)[ ]+(Mon|Tue|Wed|Thu|Fri|Sat|Sun)[ ](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[ ]+([0-9]+)[ ]+([0-9]{2}:[0-9]{2}:[0-9]{2})[ ]([0-9]{4})$' => l_files,
                "^message start: ERRSRV - (ERRmsgoff)" => l_error);

       private $url, $path, $mode, $flags=0, $type='?', $stream, $tmpfile='';
       private $dir, $dir_index=0;

       public function __destruct ()
              {if ($this->tmpfile <> '')
	              {unlink($this->tmpfile);}}

       public function stream_open ($path, $mode, $options, $opened_path)
              {list($this->url, $this->path, $this->mode) = array
                   ($path, parse_url($path), $mode);
               if ($mode <> 'r' && $mode <> 'w')
                  {trigger_error('only r/w modes allowed', E_USER_ERROR);}
               if ($this->query_type() <> '?')
                  {trigger_error('error in path', E_USER_ERROR);}
               switch ($mode)
                      {case 'r':
                            $this->download();
                            break;
                       case 'w':
                            $this->tmpfile = tempnam('/tmp', 'smb.up.');
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
                  {$rp = $this->get_rpath();
               return $this->smbclient_do('put "'.$this->tmpfile.'" "'.$rp.'"');}}

       public function unlink ($path)
              {$this->path = parse_url($path);
               if ($this->query_type() <> '?')
                  {trigger_error('error in path', E_USER_ERROR);}
               $rp = $this->get_rpath();
               $cmd = 'cd "'.dirname($rp)           # I don't know how to delete
                    . '"; del "'.basename($rp).'"'; # files without chdir first
               return $this->smbclient_do($cmd);}

       ## FIX: this function only renames files/folders in same path
       public function rename ($path_from, $path_to)
              {$this->path = parse_url($path_from);
               if ($this->query_type() <> '?')
                  {trigger_error('error in path_from', E_USER_ERROR);}
               $rp = $this->get_rpath();
               $cmd = 'cd "'. dirname($rp)
                    . '"; rename "'
                    . basename($rp). '" "'
                    . basename($path_to).'"';
               return $this->smbclient_do($cmd);}

       public function mkdir ($path, $mode, $options)
              {$this->path = parse_url($path);
               if ($this->query_type() <> '?')
                  {trigger_error('error in path', E_USER_ERROR);}
               return $this->smbclient_do('mkdir "'.$this->get_rpath().'"');}

       public function rmdir ($path, $options)
              {$this->path = parse_url($path);
               if ($this->query_type() <> '?')
                  {trigger_error('error in path', E_USER_ERROR);}
               return $this->smbclient_do('rmdir "'.$this->get_rpath().'"');}

       public function dir_opendir ($path, $options)
              {$this->path = parse_url($path);
               $type = $this->query_type();
               switch ($type)
                      {case 'workgroup':
                            $browser = $this->get_master_server($this->get_rname());
                            $saved = $this->get_servers();
                            $this->set_servers(array());
                            $this->smbclient_list($browser);
                            $this->dir = $this->get_servers();
                            $this->set_servers($saved);
                            break;
                       case 'network': $this->dir = $this->get_workgroups(); break;
                       case 'server':    $this->dir = $this->get_shares();  break;
                       default:        $this->dir = $this->get_files();}
               return true;}

       private function get_files ($path='')
              {$u = $this->get_user();
               $h = $this->get_server();
               $s = $this->get_rname();
               $p = ($path == '') ? $this->get_rpath() : $path;
               if (! isset(samba_stream::$__cache['smbclient'][$u][$h][$s][$p]))
                  {$this->smbclient_do('cd "'.$p.'"; dir');}
               if (! isset(samba_stream::$__cache['smbclient'][$u][$h][$s][$p]))
                  {trigger_error("path does not exist //$h/$s/$p");}
               return array_keys(samba_stream::$__cache['smbclient'][$u][$h][$s][$p]);}

       private function get_shares ()
               {list ($u, $s) = array($this->get_user(), $this->get_server());
                if (! isset(samba_stream::$__cache['smbclient'][$u][$s]))
                   {$this->smbclient_list();}
                return array_keys(samba_stream::$__cache['smbclient'][$u][$s]);}

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
               {$b = $this->get_config('network_browser');
                return ($b == '') ? 'localhost' : $b;}

       public function url_stat ($path, $flags)
              {list($this->url, $this->path, $this->flags) = array
                   ($path, parse_url($path), $flags);
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

       public function dir_readdir ()
              {return @$this->dir[$this->dir_index++];}

       public function dir_rewinddir ()
              {$this->dir_index = 0;}

       public function dir_closedir ()
              {return true;}

       private function get_user ()
               {return @$this->path['user'];}

       private function get_pass ()
               {return @$this->path['pass'];}

       private function get_server ()
               {return strtolower(@$this->path['host']);}

       private function get_url ()
               {return @$this->url;}

       private function get_path ()
               {return $this->fix_path(@$this->path['path']);}

       private function fix_path ($path='')
               {return '/'.preg_replace(array('/^\//', '/\/$/'), '', $path);}

       private function get_rpath ()
               {return preg_replace('/^\/([^\/]+)/', '', $this->get_path());}

       private function get_rname ()
               {$a = explode('/', $this->get_path()); return $a[1];}

       private function query_type ($path='', $server='')
               {$p = ($path == '') ? $this->get_path() : $this->fix_path($path);
                $s = ($server == '') ? $this->get_server() : $server;
                if ($h == 'network')
                   {return ($p == '/') ? 'network' : 'workgroup';}
                else
                   {return ($p == '/')
                           ? 'server'
                           : (substr_count($p, '/') > 1 ? '?' : 'share');}}

       private function download ()
               {$this->tmpfile = tempnam('/tmp', 'smb.down.');
                $this->smbclient_do('get "'
                                   .$this->get_rpath().'" "'
                                   .$this->tmpfile.'"');
                $this->stream = fopen ($this->tmpfile, 'r');}


       private function get_config ($var)
               {return isset(samba_stream::$__config[$var])
                       ? samba_stream::$__config[$var]
                       : false;}

       private function smbclient_do ($command)
               {putenv('USER='.$this->get_user().'%'.$this->get_pass());
                $cmd = 'smbclient '
                     . escapeshellarg('//'.$this->get_server().'/'.$this->get_rname())
                     . ' -b 1200 '
                     . ' -d 0'
                     . ' -O '.escapeshellarg('TCP_NODELAY '
                                            .'IPTOS_LOWDELAY '
                                            .'SO_KEEPALIVE '
                                            .'SO_RCVBUF=8192 '
                                            .'SO_SNDBUF=8192')
                     . ' -c '.escapeshellarg($command);
                $this->parse_smbclient($cmd);}

       private function smbclient_list ($server='')
               {$u = $this->get_user();
                $s = ($server == '') ? $this->get_server() : $server;
                if (! isset(samba_stream::$__cache['smbclient'][$u][$s]))
                   {putenv('USER='.$this->get_user().'%'.$this->get_pass());
                    $cmd = 'smbclient -L '.escapeshellarg($h).' -d 0';
                    $this->parse_smbclient($cmd);}
                return samba_stream::$__cache['smbclient'][$u][$s];}

       private function parse_time ($m, $d, $y, $hhiiss)
               {$his= split(':', $hhiiss);
                $im = 1 + strpos("JanFebMarAprMayJunJulAugSepOctNovDec", $m) / 3;
                return mktime($his[0], $his[1], $his[2], $im, $d, $y);}

       private function parse_share ($line)
               {$name = trim(substr($line, 1, 15));
                $type = strtolower(substr($line, 17, 10));
                $skip = ($this->get_config('hide_system_shares')
                      && substr($name,-1) == '$');
                $skip = $skip || ($this->get_config('hide_printer_shares') 
                      && $type == 'printer');
                if (! $skip)
                   {list($u, $h) = array($this->get_user(), $this->get_server());
                    samba_stream::$__cache['smbclient'][$u][$h][$name] = $type;}}

       private function parse_srvorwg ($line, $mode = 'servers')
               {$name = trim(substr($line,1,21));
                if ($mode == 'servers') $name = strtolower($name);
                $master = strtolower(trim(substr($line, 22)));
                if ($mode == 'servers' && ! in_array($name, (array) $this->get_servers()))
                   {samba_stream::$__cache['servers'][] = $name;}
                else
                   {samba_stream::$__cache['workgroups'][$name] = $master;
                    if (! in_array($master, samba_stream::$__cache['servers']))
                       {samba_stream::$__cache['servers'][] = $master;}}}

       private function parse_file ($regs)
               {if (preg_match("/^(.*)[ ]+([D|A|H|S|R]+)$/", trim($regs[1]), $regs2))
                   {list($attr, $name) = array(trim($regs2[2]), trim($regs2[1]));}
                else
                   {list($attr, $name) = array ('', trim($regs[1]));}
                if ($name <> '.' && $name <> '..')
                   {$type = (strpos($attr,'D') === false)
                          ? 'file'
                          : 'folder';
                    list($u, $h) = array($this->get_user(), $this->get_server());
                    list($s, $p) = array($this->get_rname(), $this->get_rpath());
                    $path = $this->get_rpath();
                    samba_stream::$__cache['smbclient'][$u][$h][$s][$p][$name] = array
                                    ('attr' => $attr,
                                     'size' => $regs[2],
                                     'time' => $this->parse_time
                                               ($regs[4],$regs[5],$regs[7],$regs[6]),
                                     'type' => $type);}}

       private function get_info($user, $server, $rname, $rpath)
               {$ppath = $this->fix_path(dirname($rpath));
                $name = basename($rpath);
                if ($server == 'network')
                   {if ($rname <> '' &&
                        !in_array($rname, samba_stream::$__cache['servers']))
                       {trigger_error("$rname is not a server", E_USER_ERROR);}
                    return array('attr'=>'','size'=>0,'time'=>time(),'type'=>'folder');}
                elseif (! isset(samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath]))
                    {$this->get_files($ppath);}
                if (! isset(samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath]))
                   {trigger_error("error examining //$server/$rname/$ppath",E_USER_ERROR);}
                if (! isset(samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath][$name]))
                   {trigger_error("object does not exist //$server/$rname/$ppath/$name", E_USER_ERROR);}
                return samba_stream::$__cache['smbclient'][$user][$server][$rname][$ppath][$name];}
                    

       private function parse_job ($regs)
               {$name = $regs[1].' '.$regs[3];
                list($u, $h) = array($this->get_user(), $this->get_server());
                $printer = $this->get_rname();
                samba_stream::$__cache['smbclient'][$u][$h][$printer][$name] = array
                                           ('type'=>'printjob',
                                            'id'=>$regs[1],
                                            'size'=>$regs[2]);}

       private function parse_size ($regs)
               {list ($size, $avail) = array
                     ($regs[1] * $regs[2], $regs[3] * $regs[2]);}


       private function get_linetype ($line)
               {list($line_type, $regs) = array(l_skip, array());
                reset(samba_stream::$__regexp);
   	            foreach (samba_stream::$__regexp as $regexp => $type)
   	                    {if (preg_match('/'.$regexp.'/', $line, $regs))
   	                        {$line_type = $type;
   	                         break;}}
                return array($line_type, $regs);}

       private function parse_smbclient ($cmd)
   	           {print "{debug: $cmd}\n"; $output = popen($cmd.' 2>&1', 'r');
   	            while (! feof($output))
   	                  {$line = fgets($output, 4096);
   	                   list($line_type, $regs) = $this->get_linetype($line);
                       switch ($line_type)
                              {case l_skip:    continue;
                               case l_shares:  $mode = 'shares';     break;
                               case l_servers: $mode = 'servers';    break;
                               case l_workg:   $mode = 'workgroups'; break;
                               case l_share:   $this->parse_share($line); break;
                               case l_srvorwg: $this->parse_srvorwg($line, $mode); break;
                               case l_files:   $this->parse_file($regs); break;
                               case l_jobs:    $this->parse_job($regs); break;
                               case l_size:    $this->parse_size($regs); break;
			                   case l_error:
			                        trigger_error('error '.$regs[1], E_USER_ERROR);}}
   	            pclose($output);}}


stream_wrapper_register('smb', 'samba_stream') or die('Failed to register protocol');

?>
