<?php
#
#  smb.php -- smb stream wrapper for PHP
#
#  Version: 0.3
#  lun sep 24 13:11:27 CEST 2007
#
#  Copyright (c) 2007 Victor M. Varela <vmvarela@gmail.com>
#
#  This program is free software; you can redistribute it and/or modify
#  it under the terms of the GNU General Public License as published by
#  the Free Software Foundation; either version 2 of the License, or
#  (at your option) any later version.
#  
#  This program is distributed in the hope that it will be useful,
#  but WITHOUT ANY WARRANTY; without even the implied warranty of
#  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#  GNU General Public License for more details.
#  
#  You should have received a copy of the GNU General Public License
#  along with this program; if not, write to the Free Software
#  Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.

define ('SMB4PHP_SMBCLIENT', 'smbclient');


class smb {

    static $__cache = array ();

    static $__regexp = array (
	'^added interface ip=(.*) bcast=(.*) nmask=(.*)$' => 'skip',
	'Anonymous login successful' => 'skip',
	'^Domain=\[(.*)\] OS=\[(.*)\] Server=\[(.*)\]$' => 'skip',
	'^\tSharename[ ]+Type[ ]+Comment$' => 'shares',
	'^\t---------[ ]+----[ ]+-------$' => 'skip',
	'^\tServer   [ ]+Comment$' => 'servers',
	'^\t---------[ ]+-------$' => 'skip',
	'^\tWorkgroup[ ]+Master$' => 'workg',
	'^\t(.*)[ ]+(Disk|IPC)[ ]+IPC.*$' => 'skip',
	'^\tIPC\\\$(.*)[ ]+IPC' => 'skip',
	'^\t(.*)[ ]+(Disk)[ ]+(.*)$' => 'share',
	'^\t(.*)[ ]+(Printer)[ ]+(.*)$' => 'skip',
	'([0-9]+) blocks of size ([0-9]+)\. ([0-9]+) blocks available' => 'skip',
	'Got a positive name query response from ' => 'skip',
	'^(session setup failed): (.*)$' => 'error',
	'^(.*): ERRSRV - ERRbadpw' => 'error',
	'^Error returning browse list: (.*)$' => 'error',
	'^tree connect failed: (.*)$' => 'error',
	'^(Connection to .* failed)$' => 'error',
	'^NT_STATUS_(.*) ' => 'error',
	'^NT_STATUS_(.*)\$' => 'error',
	'ERRDOS - ERRbadpath \((.*).\)' => 'error',
	'cd (.*): (.*)$' => 'error',
	'^cd (.*): NT_STATUS_(.*)' => 'error',
	'^\t(.*)$' => 'srvorwg',
	'^([0-9]+)[ ]+([0-9]+)[ ]+(.*)$' => 'skip',
	'^Job ([0-9]+) cancelled' => 'skip',
	'^[ ]+(.*)[ ]+([0-9]+)[ ]+(Mon|Tue|Wed|Thu|Fri|Sat|Sun)[ ](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[ ]+([0-9]+)[ ]+([0-9]{2}:[0-9]{2}:[0-9]{2})[ ]([0-9]{4})$' => 'files',
	'^message start: ERRSRV - (ERRmsgoff)' => 'error'
	);


    function parse_url ($url) {
	    $pu = parse_url (trim($url));
	    if (count ($userdomain = split (';', urldecode (@$pu['user']))) > 1)
	      @list ($pu['domain'], $pu['user']) = $userdomain;
	    $path = preg_replace (array ('/^\//', '/\/$/'), '', urldecode (@$pu['path']));
	    list ($pu['share'], $pu['path']) = (preg_match ('/^([^\/]+)\/(.*)/', $path, $regs))
	      ? array ($regs[1], preg_replace ('/\//', '\\', $regs[2]))
	      : array ($path, '');
	    $pu['type'] = $pu['path'] ? 'path' : ($pu['share'] ? 'share' : (@$pu['host'] ? 'host' : '**error**'));
	    if (! ($pu['port'] = intval(@$pu['port']))) $pu['port'] = 139;
	    return $pu;
	}


    function look ($host, $user='', $pass='', $domain='', $port = 139) {
	    $auth = ($user <> '' ? (' -U '.escapeshellarg ($user.'%'.$pass)) : '')
	          . ($domain <> '' ? (' -W '.escapeshellarg ($domain)) : '');
        $port = ($port <> 139 ? ' -p '.escapeshellarg($port) : '');
	    return smb::client ('-L '	. escapeshellarg ($host) . $auth . $port);
	}


    function execute ($command, $host, $share, $user='', $pass='', $domain='', $port = 139) {
	    $auth = ($user <> '' ? (' -U '.escapeshellarg ($user.'%'.$pass)) : '')
	          . ($domain <> '' ? (' -W '.escapeshellarg ($domain)) : '');
        $port = ($port <> 139 ? ' -p '.escapeshellarg($port) : '');
	    return smb::client ('-d 0 '
 	          . escapeshellarg ('//'.$host.'/'.$share)
	          . $auth . $port
	          . ' -c '.escapeshellarg ($command)
	    );
	}

    function client ($params) {
	    $output = popen (SMB4PHP_SMBCLIENT.' '.$params.' 2>/dev/null', 'r');
	    $info = array ();
	    while ($line = fgets ($output, 4096)) {
	        list ($tag, $regs, $i) = array ('skip', array (), array ());
            reset (smb::$__regexp);
	        foreach (smb::$__regexp as $r => $t) if (preg_match ('/'.$r.'/', $line, $regs)) {
	            $tag = $t;
	            break;
	        }
	        switch ($tag) {
	        case 'skip':    continue;
	        case 'shares':  $mode = 'shares';     break;
	        case 'servers': $mode = 'servers';    break;
	        case 'workg':   $mode = 'workgroups'; break;
	        case 'share':
	             list($name, $type) = array (
	                 trim(substr($line, 1, 15)),
	                 trim(strtolower(substr($line, 17, 10)))
	             );
	             $i = ($type <> 'disk' && preg_match('/^(.*) Disk/', $line, $regs))
	                ? array(trim($regs[1]), 'disk')
	                : array($name, 'disk');
	             break;
	        case 'srvorwg':
	             list ($name, $master) = array (
	                  strlower(trim(substr($line,1,21))),
	                  strtolower(trim(substr($line, 22)))
	             );
	             $i = ($mode == 'servers') ? array ($name, "server") : array ($name, "workgroup", $master);
	             break;
	        case 'files':
	             list ($attr, $name) = preg_match ("/^(.*)[ ]+([D|A|H|S|R]+)$/", trim ($regs[1]), $regs2)
	                 ? array (trim ($regs2[2]), trim ($regs2[1]))
	                 : array ('', trim ($regs[1]));
	             list ($his, $im) = array (
	                 split(':', $regs[6]),
	                 1 + strpos("JanFebMarAprMayJunJulAugSepOctNovDec", $regs[4]) / 3
	             );
	                 $i = ($name <> '.' && $name <> '..')
	                    ? array (
	                          $name,
	                          (strpos($attr,'D') === FALSE) ? 'file' : 'folder',
	                          'attr' => $attr,
	                          'size' => intval($regs[2]),
	                          'time' => mktime ($his[0], $his[1], $his[2], $im, $regs[5], $regs[7])
	                      )
                            : array();
	                 break;
	            case 'error':   trigger_error($regs[1], E_USER_ERROR);
	        }
	        if ($i) switch ($i[1]) {
	            case 'file':
                case 'folder':    $info['info'][$i[0]] = $i;
	            case 'disk':
	            case 'server':
	            case 'workgroup': $info[$i[1]][] = $i[0];
	        }
	    }
	    pclose($output);
	    return $info;
	}


    # stats

	function url_stat ($url, $flags = STREAM_URL_STAT_LINK) {
        if (isset(smb::$__cache[$url])) { return smb::$__cache[$url]; }
	    list ($stat, $pu) = array (array (), smb::parse_url ($url));
	    switch ($pu['type']) {
	        case 'host':
	            if (smb::look ($pu['host'], @$pu['user'], @$pu['pass'], @$pu['domain'], $pu['port']))
	               $stat = stat ("/tmp");
	            else
	               trigger_error ("url_stat(): list failed for host '{$host}'", E_USER_WARNING);
	            break;
	        case 'share':
	            if ($o = smb::look ($pu['host'], @$pu['user'], @$pu['pass'], @$pu['domain'], $pu['port'])) {
	               $found = false;
	               $lshare = strtolower ($share);
	               foreach ($o['disk'] as $s) if ($lshare == strtolower($s)) {
	                   $found = true;
	                   $stat = stat ("/tmp");
	                   break;
	               }
	               if (! $found)
	                  trigger_error ("url_stat(): disk resource '{$share}' not found in '{$host}'", E_USER_WARNING);
	            }
	            break;
	        case 'path':
	            if ($o = smb::execute ('dir "'.$pu['path'].'"', $pu['host'], $pu['share'], @$pu['user'], @$pu['pass'], @$pu['domain'])) {
	                $p = split ("[\\]", $pu['path']);
	                $name = $p[count($p)-1];
	                if (isset ($o['info'][$name])) {
                       $stat = smb::addstatcache ($url, $o['info'][$name]);
	                } else {
	                   trigger_error ("url_stat(): path '{$pu['path']}' not found", E_USER_WARNING);
	                }
	            } else {
	                trigger_error ("url_stat(): dir failed for path '{$pu['path']}'", E_USER_WARNING);
	            }
	            break;
	        default: trigger_error ('error in URL', E_USER_ERROR);
	    }
	    return $stat;
	}

	function addstatcache ($url, $info) {
	    $is_file = (strpos ($info['attr'],'D') === FALSE);
	    $s = ($is_file) ? stat ('/etc/passwd') : stat ('/tmp');
	    $s[7] = $s['size'] = $info['size'];
	    $s[8] = $s[9] = $s[10] = $s['atime'] = $s['mtime'] = $s['ctime'] = $info['time'];
	    return smb::$__cache[$url] = $s;
	}

	function clearstatcache ($url='') {
	    if ($url == '') smb::$__cache = array (); else unset (smb::$__cache[$url]);
	}


	# commands

	function unlink ($url) {
	    $pu = smb::parse_url($url);
	    if ($pu['type'] <> 'path') trigger_error('unlink(): error in URL', E_USER_ERROR);
	    smb::clearstatcache ($url);
	    return smb::execute ('del "'.$pu['path'].'"', $pu['host'], $pu['share'], @$pu['user'], @$pu['pass'], @$pu['domain'], $pu['port']);
	}

	function rename ($url_from, $url_to) {
	    list ($from, $to) = array (smb::parse_url($url_from), smb::parse_url($url_to));
	    if ($from['host'] <> $to['host'] ||
	        $from['share'] <> $to['share'] ||
	        @$from['user'] <> @$to['user'] ||
	        @$from['pass'] <> @$to['pass'] ||
	        @$from['domain'] <> @$to['domain']) {
	        trigger_error('rename(): FROM & TO must be in same server-share-user-pass-domain', E_USER_ERROR);
	    }
	    if ($from['type'] <> 'path' || $to['type'] <> 'path') {
	        trigger_error('rename(): error in URL', E_USER_ERROR);
	    }
	    smb::clearstatcache ($url_from);
	    return smb::execute ('rename "'.$from['path'].'" "'.$to['path'].'"', $to['host'], $to['share'], @$to['user'], @$to['pass'], @$to['domain'], $to['port']);

	}

	function mkdir ($url, $mode, $options) {
	    $pu = smb::parse_url($url);
	    if ($pu['type'] <> 'path') trigger_error('mkdir(): error in URL', E_USER_ERROR);
	    return smb::execute ('mkdir "'.$pu['path'].'"', $pu['host'], $pu['share'], @$pu['user'], @$pu['pass'], @$pu['domain'], $pu['port']);
	}

	function rmdir ($url) {
	    $pu = smb::parse_url($url);
	    if ($pu['type'] <> 'path') trigger_error('rmdir(): error in URL', E_USER_ERROR);
	    smb::clearstatcache ($url);
	    return smb::execute ('rmdir "'.$pu['path'].'"', $pu['host'], $pu['share'], @$pu['user'], @$pu['pass'], @$pu['domain'], $pu['port']);
	}

}

class smb_stream_wrapper extends smb {

	# variables

	var $stream, $url, $parsed_url = array (), $mode, $tmpfile;
	var $dir = array (), $dir_index = -1;


	# directories

	function dir_opendir ($url, $options) {
	    $pu = smb::parse_url ($url);
	    switch ($pu['type']) {
	        case 'host':
	            if ($o = smb::look ($pu['host'], $pu['user'], $pu['pass'], $pu['domain'], $pu['port'])) {
	               $this->dir = $o['disk'];
	               $this->dir_index = 0;
	            } else {
	               trigger_error ("dir_opendir(): list failed for host '{$pu['host']}'", E_USER_WARNING);
	            }
	            break;
	        case 'share':
	        case 'path':
	            if ($o = smb::execute ('dir "'.$pu['path'].'\*"', $pu['host'], $pu['share'], $pu['user'], $pu['pass'], @$pu['domain'], $pu['port'])) {
	               $this->dir = array_keys($o['info']);
	               $this->dir_index = 0;
	               foreach ($o['info'] as $name => $info) {
	                   smb::addstatcache($url.'/'.urlencode($name), $info);
	               }
	            } else {
	               trigger_error ("dir_opendir(): dir failed for path '{$path}'", E_USER_WARNING);
	            }
	            break;
	        default:
	            trigger_error ('dir_opendir(): error in URL', E_USER_ERROR);
	    }
	    return true;
	}

	function dir_readdir () { return ($this->dir_index < count($this->dir)) ? $this->dir[$this->dir_index++] : FALSE; }

	function dir_rewinddir () { $this->dir_index = 0; }

	function dir_closedir () { $this->dir = array(); $this->dir_index = -1; return TRUE; }


	# streams

	function stream_open ($url, $mode, $options, $opened_path) {
	    $this->url = $url;
	    $this->parsed_url = $pu = smb::parse_url($url);
	    if ($pu['type'] <> 'path') trigger_error('stream_open(): error in URL', E_USER_ERROR);
	    switch ($mode) {
	        case 'r':
	            $this->tmpfile = tempnam('/tmp', 'smb.down.');
                    smb::execute ('get "'.$pu['path'].'" "'.$this->tmpfile.'"', $pu['host'], $pu['share'], $pu['user'], $pu['pass'], @$pu['domain'], $pu['port']);
	            $this->stream = fopen ($this->tmpfile, 'r');
	            $this->mode = 'r';
	            break;
	        case 'w':
	            $this->tmpfile = tempnam('/tmp', 'smb.up.');
	            $this->stream = fopen($this->tmpfile, 'w');
	            $this->mode = 'w';
	    }
	    return TRUE;
	}

	function stream_close () { return fclose($this->stream); }

	function stream_read ($count) { return fread($this->stream, $count); }

	function stream_write ($data) { return fwrite($this->stream, $data); }

	function stream_eof () { return feof($this->stream); }

	function stream_tell () { return ftell($this->stream); }

	function stream_seek ($offset, $whence=null) { return fseek($this->stream, $offset, $whence); }

	function stream_flush () {
	    if ($this->mode == 'w') {
	        smb::clearstatcache ($this->url);
	        smb::execute ('put "'.$this->tmpfile.'" "'.$this->parsed_url['path'].'"',
	            $this->parsed_url['host'],
	            $this->parsed_url['share'],
	            @$this->parsed_url['user'],
	            @$this->parsed_url['pass'],
	            @$this->parsed_url['domain'],
	            $this->parsed_url['port']
	        );
	    }
	}

	function stream_stat () { return smb::url_stat ($this->url); }

	function __destruct () { if ($this->tmpfile <> '') unlink($this->tmpfile); }

}


stream_wrapper_register('smb', 'smb_stream_wrapper') or die ('Failed to register protocol');

?>