<?php
###################################################################
# smbwebclient.php
# This script is a web interface to a Windows Network.
#
# Date: wed may 15 09:17:00 CEST 2013
#
# Homepage: http://www.phpclasses.org/smbwebclient
#
# Copyright (c) 2003-2013 Victor M. Varela <vmvarela@gmail.com>
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#  
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#  
###################################################################

###################################################################
# CONFIGURATION SECTION - Change for your needs
###################################################################

define ('SMBCLIENT_PATH', 'smbclient');
define ('SMBCLIENT_OPTIONS', '-O "TCP_NODELAY IPTOS_LOWDELAY SO_KEEPALIVE SO_RCVBUF=8192 SO_SNDBUF=8192"');


define ('SMBCLIENT_VERSION', '3.5.6');
define ('SMBCLIENT_PORT', 139);


define ('S_IFDIR',  0040000); # directory
define ('S_IFREG',  0100000); # regular
define ('S_IRUSR',  0000444); # read permission
define ('S_IWUSR',  0000222); # write permission
define ('S_IXUSR',  0000111); # execute/search permission





###################################################################
# CACHED_STREAM_WRAPPER - streams with download/upload needed
###################################################################

class cached_stream_wrapper {

static $__cache = array ('stat' => array (), 'dir' => array ());


# variables

var $stream = NULL;
var $streamURL = '';
var $streamMode = '';
var $streamTmpfile = '';
var $streamNeedFlush = FALSE;


# stats & dir cache

function GetStatCache ($url)
{
	return isset (cached_stream_wrapper::$__cache['stat'][$url]) ? cached_stream_wrapper::$__cache['stat'][$url] : FALSE;
}

function AddStatCache ($url, $stat)
{
	return cached_stream_wrapper::$__cache['stat'][$url] = $stat;
}

function ClearStatCache ($url='')
{
	if ($url == '') {
		cached_stream_wrapper::$__cache['stat'] = array ();
	} else {
		unset (cached_stream_wrapper::$__cache['stat'][$url]);
	}
}

function AddDirCache ($url, $content)
{
	return cached_stream_wrapper::$__cache['dir'][$url] = $content;
}

function GetDirCache ($url)
{
	return isset (cached_stream_wrapper::$__cache['dir'][$url]) ? smbclient_stream_wrapper::$__cache['dir'][$url] : FALSE;
}

function ClearDirCache ($url='')
{
	if ($url == '') {
		cached_stream_wrapper::$__cache['dir'] = array ();
	} else {
		unset (cached_stream_wrapper::$__cache['dir'][$url]);
	}
}

# WRAPPER::stream_open
function stream_open ($url, $mode, $options, $openPath)
{
	$this->streamURL = $url;
	$this->streamMode = $mode;
	switch ($mode) {
		case 'r':
		case 'r+':
		case 'rb':
		case 'a':
		case 'a+':	$this->streamTmpfile = tempnam('/tmp', 'cached_sw.down.');
				$this->Download($url, $this->streamTmpfile);
				break;
		case 'w':
		case 'w+':
		case 'wb':
		case 'x':
		case 'x+':	$this->ClearDirCache();
				$this->streamTmpfile = tempnam('/tmp', 'cached_sw.up.');
	}
	$this->stream = fopen($this->streamTmpfile, $mode);
	return TRUE;
}

# WRAPPER::stream_close
function stream_close ()
{
	return fclose($this->stream);
}

# WRAPPER::stream_read
function stream_read ($count)
{
	return fread($this->stream, $count);
}

# WRAPPER::stream_write
function stream_write ($data)
{
	$this->streamNeedFlush = TRUE;
	return fwrite($this->stream, $data);
}

# WRAPPER::stream_eof
function stream_eof ()
{
	return feof($this->stream);
}

# WRAPPER::stream_tell
function stream_tell ()
{
	return ftell($this->stream);
}

# WRAPPER::stream_seek
function stream_seek ($offset, $whence=NULL)
{
	return fseek($this->stream, $offset, $whence);
}


# WRAPPER::stream_flush
function stream_flush ()
{
	if ($this->streamMode <> 'r' AND $this->streamNeedFlush) {
		$this->ClearStatCache($this->streamURL);
		$this->Upload($this->streamTmpfile, $this->streamURL);
		$this->streamNeedFlush = FALSE;
	}
}

# WRAPPER::stream_stat
function stream_stat ()
{
	return $this->url_stat($this->streamURL);
}

function __destruct ()
{
	if ($this->streamTmpfile <> '') {
		if ($this->streamNeedFlush) $this->stream_flush();
		unlink($this->streamTmpfile);
	}
}


}


###################################################################
# SMBCLIENT_STREAM_WRAPPER - access to smbclient:// URIs
###################################################################


class smbclient_stream_wrapper extends cached_stream_wrapper {

static $debugLevel = 0;
static $defaultUser = '';
static $defaultPass = '';

static $re = array (
	'^tree connect failed: (.*)$' => 'error',
	'^Connection to .* failed \(Error (.*)\)$' => 'error',
	'^(NT_STATUS_ACCESS_DENIED) listing' => 'error',
	'^(NT_STATUS_NO_SUCH_FILE) listing' => 'error',
	'^(NT_STATUS_OBJECT_NAME_COLLISION)' => 'error',
	'^(NT_STATUS_OBJECT_NAME_INVALID)' => 'error',
	'^(NT_STATUS_OBJECT_NAME_NOT_FOUND) removing remote directory file' => 'error',
	'^(NT_STATUS_OBJECT_PATH_NOT_FOUND)' => 'error',
	'^[ ]+(.*)[ ]+([0-9]+)[ ]+(Mon|Tue|Wed|Thu|Fri|Sat|Sun)[ ](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[ ]+([0-9]+)[ ]+([0-9]{2}:[0-9]{2}:[0-9]{2})[ ]([0-9]{4})$' => 'files'
);

static $__info = array();

var $dir = array ();
var $dirIndex = -1;



# get info needed by smbclient from URL
# format - smbclient://domain\user@host/share/path
function ParseURL ($url)
{
	$pu = parse_url (trim($url));
	foreach (array ('user', 'pass', 'host', 'port', 'share', 'path') as $i) {
		# init missing fields always present
		if (! isset($pu[$i])) $pu[$i] = '';
	}

	$path = preg_replace (array ('/^\//', '/\/$/'), '', urldecode ($pu['path']));
	list ($pu['share'], $pu['path']) = (preg_match ('/^([^\/]+)\/(.*)/', $path, $regs))
		? array ($regs[1], preg_replace ('/\//', '\\', $regs[2]))
		: array ($path, '');

	if (! ($pu['port'] = intval(@$pu['port']))) $pu['port'] = SMBCLIENT_PORT;

	($pu['share'] <> '') OR trigger_error("a shared resource name must be specified !", E_USER_ERROR);
	if ($pu['path'] == '') $pu['path'] = '.';
	if ($pu['user'] == '' AND smbclient_stream_wrapper::$defaultUser <> '') {
		$pu['user'] = smbclient_stream_wrapper::$defaultUser;
		$pu['pass'] = smbclient_stream_wrapper::$defaultPass;
	}

	return $pu;
}

function SetAuth ($user, $pass)
{
	smbclient_stream_wrapper::$defaultUser = $user;
	smbclient_stream_wrapper::$defaultPass = $pass;
}

function MakeStat ($attr, $size, $time)
{
	# builds stat array
	if (strpos ($attr,'D') === FALSE) {
		$mode = S_IFREG | S_IRUSR | S_IWUSR; # file
	} else {
		$mode = S_IFDIR | S_IRUSR | S_IWUSR | S_IXUSR; # dir
	}
	return array (
		0		=>	date('Y'),
		1		=>	0,
		2		=>	$mode,
		3		=>	1,
		4		=>	0,
		5		=>	0,
		6		=>	0,
		7		=>	$size,
		8		=>	$time,
		9		=>	$time,
		10		=>	$time,
		11		=>	-1,
		12		=>	-1,
		'dev'		=>	date('Y'),
		'ino'		=>	0,
		'mode'		=>	$mode,
		'nlink'		=>	1,
		'uid'		=>	0,
		'gid'		=>	0,
		'rdev'		=>	0,
		'size'		=>	$size,
		'atime'		=>	$time,
		'mtime'		=>	$time,
		'ctime'		=>	$time,
		'blksize'	=>	-1,
		'blocks'	=>	-1,
	);
}

function Client ($command, $purl)
{
	smbclient_stream_wrapper::$__info = array('info' => array(), 'error' => FALSE);

	# setting user & pass
	$auth = ' --user='.escapeshellarg($purl['user'].'%'.$purl['pass']);
	# setting port (from URL)
	$port = ($purl['port'] <> SMBCLIENT_PORT) ? ' --port '.escapeshellarg($purl['port']) : '';
	# target
	$share = escapeshellarg ('//'.$purl['host'].'/'.$purl['share']);

	# smbclient command to execute
	$command = ' --command '.escapeshellarg($command);

	# call to smbclient tool
	$smbclient = implode(' ', array(
		SMBCLIENT_PATH,
		'--debuglevel=0',
		'--no-pass',
		$auth,
		SMBCLIENT_OPTIONS,
		$port,
		$share,
		$command
	));

	# parse smbclient output
	(smbclient_stream_wrapper::$debugLevel < 2) OR print "\n\n[".$smbclient."]\n\n";
	$output = popen ("{$smbclient} 2>/dev/null", 'r');
	while ($line = fgets ($output, 4096)) {

		(smbclient_stream_wrapper::$debugLevel < 3) OR print $line;

		# search for a match
		list ($tag, $regs, $i) = array ('skip', array (), array ());
		reset (smbclient_stream_wrapper::$re);
		foreach (smbclient_stream_wrapper::$re as $r => $t) {
			if (preg_match ('/'.$r.'/', $line, $regs)) {
				$tag = $t;
				break;
			}
		}
		# parse match
		switch ($tag) {
			case 'skip':	continue;
			case 'files':
				# parse directory listing
				if (preg_match ("/^(.*)[ ]+([D|A|H|S|R]+)$/", trim ($regs[1]), $regs2)) {
					$name = trim ($regs2[1]);
					$attr = trim ($regs2[2]);
				} else {
					$name = trim ($regs[1]);
					$attr = '';
				}
				$his = split(':', $regs[6]);
				$im  = 1 + strpos("JanFebMarAprMayJunJulAugSepOctNovDec", $regs[4]) / 3;
				$size = intval($regs[2]);
				$time = mktime ($his[0], $his[1], $his[2], $im, $regs[5], $regs[7]);
				$stat = smbclient_stream_wrapper::MakeStat($attr, $size, $time);
				smbclient_stream_wrapper::$__info['info'][$name] = $stat;
				break;
			case 'error':
				smbclient_stream_wrapper::$__info['error'] = ($tag == 'error') ? $regs[1] : $line;
				return smbclient_stream_wrapper::$__info;
		}
        }
        pclose($output);

        return smbclient_stream_wrapper::$__info;
}

function Download ($url, $localFile)
{
	$pu = smbclient_stream_wrapper::ParseURL($url);
	$o = $this->Client ('get "'.$pu['path'].'" "'.$localFile.'"', $pu);
	return $o['error'] == '';
}

function Upload ($localFile, $url)
{
	$pu = smbclient_stream_wrapper::ParseURL($url);
	$o = $this->Client ('put "'.$localFile.'" "'.$pu['path'].'"', $pu);
	return $o['error'] == '';
}




# WRAPPER::url_stat

function url_stat ($url, $flags = STREAM_URL_STAT_LINK)
{
	if ($stat = smbclient_stream_wrapper::GetStatCache($url)) return $stat;

	list ($stat, $pu) = array (array (), smbclient_stream_wrapper::ParseURL ($url));
	$path = ($pu['path'] == '.') ? '' : $pu['path'];
	if ($o = smbclient_stream_wrapper::Client ('dir "'.$path.'"', $pu)) {
		$p = split ("[\\]", $pu['path']);
		$name = $p[count($p)-1];
		if (isset ($o['info'][$name])) {
			return smbclient_stream_wrapper::AddStatCache ($url, $o['info'][$name]);
		} else {
			#trigger_error ("url_stat(): path '{$pu['path']}' not found", E_USER_WARNING);
			return FALSE;
		}
	} else {
		#trigger_error ("url_stat(): dir failed for path '{$pu['path']}'", E_USER_WARNING);
		return FALSE;
	}
}



# directories

# WRAPPER::dir_opendir

function dir_opendir ($url, $options)
{
	if ($d = $this->GetDirCache ($url)) {
		$this->dir = $d;
		$this->dirIndex = 0;
		return TRUE;
	}
	$pu = smbclient_stream_wrapper::ParseURL($url);
	($o = smbclient_stream_wrapper::Client ('dir "'.$pu['path'].'\*"', $pu))
		OR trigger_error("dir_opendir(): dir failed for path '{$path}'", E_USER_ERROR);
	$this->dir = array_keys($o['info']);
	$this->dirIndex = 0;
	$this->AddDirCache ($url, $this->dir);
	foreach ($o['info'] as $name => $info) {
		smbclient_stream_wrapper::AddStatCache($url.'/'.urlencode($name), $info);
	}
	return TRUE;
}

# WRAPPER::dir_readdir

function dir_readdir ()
{
	return ($this->dirIndex < count($this->dir)) ? $this->dir[$this->dirIndex++] : FALSE;
}

# WRAPPER::dir_rewinddir

function dir_rewinddir ()
{
	$this->dirIndex = 0;
}

# WRAPPER::dir_closedir

function dir_closedir ()
{
	$this->dir = array();
	$this->dirIndex = -1;
	return TRUE;
}

# commands

# WRAPPER::unlink

function unlink ($url)
{
	$pu = smbclient_stream_wrapper::ParseURL($url);
	smbclient_stream_wrapper::ClearStatCache ($url);
	$o = smbclient_stream_wrapper::Client ('del "'.$pu['path'].'"', $pu);
	return $o['error'] == '';
}

# WRAPPER::rename

function rename ($url_from, $url_to)
{
	list ($from, $to) = array (smbclient_stream_wrapper::ParseURL($url_from), smbclient_stream_wrapper::ParseURL($url_to));
	($from['host'] == $to['host'] AND $from['share'] == $to['share'] AND $from['user'] == $to['user'] AND $from['pass'] == $to['pass'])
		OR trigger_error('rename(): FROM & TO must be in same server-share-user-pass', E_USER_ERROR);
	smbclient_stream_wrapper::ClearStatCache ($url_from);
	$o = smbclient_stream_wrapper::Client ('rename "'.$from['path'].'" "'.$to['path'].'"', $to);
	return $o['error'] == '';
}

# WRAPPER::mkdir

function mkdir ($url, $mode, $options)
{
	$pu = smbclient_stream_wrapper::ParseURL($url);
	$o = smbclient_stream_wrapper::Client ('mkdir "'.$pu['path'].'"', $pu);
	if ($o['error'] <> '') {
		trigger_error($o['error'], E_USER_WARNING);
	}
	return $o['error'] == '';
}

# WRAPPER::rmdir

function rmdir ($url)
{
	$pu = smbclient_stream_wrapper::ParseURL($url);
	($pu['path'] <> '.') OR trigger_error('rmdir(): error in URL', E_USER_ERROR);
	smbclient_stream_wrapper::ClearStatCache ($url);
	$o = smbclient_stream_wrapper::Client ('rmdir "'.$pu['path'].'"', $pu);
	return $o['error'] == '';
}

}


###################################################################
# Register 'smbclient' protocol !
###################################################################

stream_wrapper_register('smbclient', 'smbclient_stream_wrapper')
    OR die ('Failed to register protocol');




class webapp {

# session, userauth, logging, i18n, inline resources, html & templates

var $cfgLogId = 'webapp';
var $cfgSessionName = 'WebappSessionID';
var $cfgFacility = LOG_DAEMON;
var $cfgDefaultLanguage 'es.utf-8';
var $languages;
var $lang;

var $cfgInlineFiles = true;
var $inlineFiles = array ();


function InitSession ()
{
	set_time_limit(1200);
	error_reporting(E_ALL ^ E_NOTICE);

	openlog($this->cfgLogId, LOG_ODELAY | LOG_PID, $this->cfgFacility);

	if ($this->cfgSessionName <> '') session_name($this->cfgSessionName);
	session_start();
}

function InitLanguage ()
{
	# load languages
	foreach (preg_split('/\n/',$this->GetInlineFile('data/languages.csv')) as $line) {
		$a = preg_split('/;/', $line);
		$this->languages[$a[0]] = $a[1];
	}

	# default language
	$this->lang = $this->cfgDefaultLanguage;
	$langOK = false;

	# param from GET
	if (isset($_REQUEST['lang']) AND isset($this->strings[$_REQUEST['lang']])) {
		$this->lang = $_REQUEST['lang'];
		$langOK = true;
	# current session
	} elseif (isset($_SESSION['webappLanguage']) AND isset($this->strings[$_SESSION['webappLanguage']])) {
		$this->lang = $_SESSION['webappLanguage'];
	} else {
		# take a look at HTTP_ACCEPT_LANGUAGE
		foreach (preg_split('/,/', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $lang)
		foreach ($this->languages as $key => $filter)
		if (isset($this->strings[$key]) AND preg_match('/^('.$filter.')(;q=[0-9]\\.[0-9])?$/i', $lang)) {
			$this->lang = $key;
			$langOK = true;
		}
		# look at HTTP_USER_AGENT
		if (! $langOK) {
			reset($this->languages);
			foreach ($this->languages as $key => $filter)
			if (isset($this->strings[$key])
			AND preg_match('/(\(|\[|;[[:space:]])(' . $filter . ')(;|\]|\))/i', $_SERVER['HTTP_USER_AGENT'])) {
				$this->lang = $key;
				$langOK = true;
			}
		}
	}
	if ($langOK) $_SESSION['webappLanguage'] = $this->lang;
}

# returns a string in a given language
function _($str)
{
	# for english, all is done !
	if ($this->lang == 'en') return $str;

	# search string at english list (get position)
	$pos = array_search($str, $this->strings['en']);
	if (($pos = array_search($str, $this->strings['en'])) === FALSE) return $str;

	# found position at current language (ok!)
	if ($this->strings[$this->lang][$pos] <> '') return $this->strings[$this->lang][$pos];

	# found position at default language (better than nothing!)
	if ($this->strings[$this->cfgDefaultLanguage][$pos] <> '') return $this->strings[$this->cfgDefaultLanguage][$pos];

	# well, I cannot do anything better !
	return $str;
}

function DumpFile($file='', $name='', $isAttachment=false, $isCacheable=false)
{
	if ($name == '') $name = basename($file);
	$pi = pathinfo(strtolower($name));
	$mimeType = @$this->mimeTypes[@$pi['extension']];
	if ($mimeType == '') $mimeType = 'application/octet-stream';

	# dot bug with IE
	if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
		$name = preg_replace('/\./','%2e', $name, substr_count($name, '.') - 1);
	}

	header("Cache-control: ".($isCacheable ? "public" : "private"));
	header("Pragma: public");
	header('MIME-Version: 1.0');
	header("Content-Type: $mimeType; name =\"".$name."\"");

	$isAttachment = ($isAttachment) ? "attachment; " : "";
	header("Content-Disposition: $isAttachment filename=\"".$name."\"");

	if ($file <> '' AND is_readable($file)) {
		$fp = fopen($file, "r");
		while (! feof($fp)) {
			print fread($fp,65536);
			flush();
		}
		fclose($fp);
	}
}

function GetInlineFile ($file)
{
	if (! $this->cfgInlineFiles) {
		# if does not exists, write from inline file ! (devel)
		if (! is_readable($file)) {
			$f = fopen($file, 'wb');
			fwrite($f, base64_decode($this->inlineFiles[$file]));
			fclose($f);
		}
		$f = fopen($file, 'r');
		$data = fread ($f, filesize($file));
		fclose ($f);
	} else {
		if (isset($_SESSION['webappUncodedInlineFiles'][$file])) {
			$data =  $_SESSION['webappUncodedInlineFiles'][$file];
		} else {
			$data = base64_decode($this->inlineFiles[$file]);
			$_SESSION['webappUncodedInlineFiles'][$file] = $data;
		}
	}
	return $data;
}

function DumpInlineFile ($file)
{
	$this->DumpFile('',$file,false,true);
	print $this->GetInlineFile ($file);
}

function GetIP()
{
	foreach (array('HTTP_X_FORWARDED','HTTP_VIA','REMOTE_ADDR') as $var) {
		if (isset($_SERVER[$var])) return $_SERVER[$var];
	}
	return 'unknown';
}                                                                   

# debugging messages
function Debug ($message, $level=0)
{
	if ($level <= $this->debug) {
		foreach (preg_split('/\n/',$message) as $line) {
			syslog(LOG_INFO, $this->user.'['.$this->GetIP().']: '.$line);
		}
	}
}

# HTML page
function Page ($title='', $content='')
{
	if (@$_SESSION['webappErrorMessage'] <> '') {
		$content .= "\n<script language=\"Javascript\">alert(\"{$_SESSION['webappErrorMessage']}\")</script>\n";
		$_SESSION['webappErrorMessage'] = '';
	}
	return $this->Template('style/page.thtml', array(
		'{title}' => $title,
		'{charset}' => $this->cfgDefaultCharset,
		'{content}' => $content,
		'{style}' => $this->GetUrl('style/'),
		'{favicon}' => $this->GetUrl('style/favicon.ico')
	));
}

# loads an HTML template
function Template ($file, $vars=array())
{
	return str_replace(array_keys($vars), array_values($vars), $this->GetInlineFile($file));
}

# HTML a href
function Link ($title, $url='', $name='')
{
	if ($name <> '') $name = "name = \"{$name}\"";
	return ($url == '') ? $title : "<a href=\"{$url}\" {$name}>{$title}</a>";
}

# HTML img
function Image ($url, $alt='', $extra='')
{
	return ($url == '') ? $title : "<img src=\"{$url}\" alt=\"{$alt}\" border=\"0\" {$extra} />";
}

function Icon ($icon, $url='', $size=18)
{
	$image = $this->Image($this->GetUrl("style/{$icon}.png"),'#',"align=\"absmiddle\" width=\"$size\" height=\"$size\"");
	return ($url <> '') ? $this->Link($image, $url) : $image;
}

# HTML select (combo)
function Select ($name, $value, $options)
{
	$html = "<select name=\"{$name}\">\n";
	foreach ($options as $key => $description) {
		$selected = ($key == $value) ? "selected" : "";
		$html .= "<option value=\"{$key}\" $selected>{$description}</option>\n";
	}
	$html .= "</select>\n";
	return $html;
}

# HTML check box
function CheckBox ($name, $value, $checked = false)
{
	return $this->Input($name, $value, 'checkbox', $checked ? "checked" : "");
}

function Input ($name, $value = '', $type = 'text', $extra = '')
{
	return "<input type=\"{$type}\" name=\"{$name}\" value=\"".htmlentities($value, ENT_COMPAT, $this->cfgDefaultCharset)."\" {$extra}/>";
}

function ErrorMessage ($msg)
{
	$_SESSION['webappErrorMessage'] = @$_SESSION['webappErrorMessage'] . $msg;
}

function CleanCachedAuth ()
{
	$mode = $this->type;
	@$_SESSION['webappCachedAuth'][$mode][$this->$mode] = '';
}

# basic auth
function GetAuth ($command = 'get')
{
	switch ($command) {
		case 'get':
			if (@$_GET['auth'] == 1 OR ($this->cfgAnonymous <> true AND !isset($_SERVER['PHP_AUTH_USER']))) {
				$_SESSION['webappAuthSubmit'] = true;
				$time = date("h:i:s");
				$path = $this->PrintablePath();
				header("WWW-Authenticate: Basic realm=\"{$path} ($time)\"");
				header("HTTP/1.0 401 Unauthorized");
				print $this->Page('unauthorized');
				exit;
			}
			$this->user = stripslashes(@$_SERVER['PHP_AUTH_USER']);
			$this->pw = stripslashes(@$_SERVER['PHP_AUTH_PW']);
			break;
		case 'submit':
			if (@$_SESSION['webappAuthSubmit']) {
				$_SESSION['webappAuthSubmit'] = false;
				$this->user = stripslashes(@$_SERVER['PHP_AUTH_USER']);
				$this->pw = stripslashes(@$_SERVER['PHP_AUTH_PW']);
				return true;
			}
			return false;
	}
}

function GetCachedAuth ()
{
	$this->user = $this->pw = '';
	$nextLevel = array('network'=>'','workgroup'=>'network','server'=>'workgroup','share'=>'server');
	if ($this->GetAuth('submit')) {
		# store auth in cache
		$mode = $this->type;
		$_SESSION['swcCachedAuth'][$mode][$this->$mode]['User'] = $this->user;
		$_SESSION['swcCachedAuth'][$mode][$this->$mode]['Password'] = $this->pw;
		for ($mode = $nextLevel[$mode]; $mode <> ''; $mode = $nextLevel[$mode]) {
			if (! isset($_SESSION['swcCachedAuth'][$mode][$this->$mode])) {
				$_SESSION['swcCachedAuth'][$mode][$this->$mode]['User'] = $this->user;
				$_SESSION['swcCachedAuth'][$mode][$this->$mode]['Password'] = $this->pw;
			}
		}
	} elseif (@$_GET['auth'] <> 1) {
		# get auth from cache
		for ($mode = $this->type; $mode <> ''; $mode = $nextLevel[$mode]) {
			if (isset($_SESSION['swcCachedAuth'][$mode][$this->$mode])) {
				$this->user = $_SESSION['swcCachedAuth'][$mode][$this->$mode]['User'];
				$this->pw = $_SESSION['swcCachedAuth'][$mode][$this->$mode]['Password'];
				break;
			}
		}
		if ($this->user == '') $this->GetAuth('get');
	} else $this->GetAuth('get');
}



# return an URL (adding a param)
function GetUrl ($path='', $arg='', $val='')
{
	$get = $_GET;
	$get['path'] = $path;

	# delete switches from URL
	$get['lang'] = $get['auth'] = '';

	if ($arg <> '') {
		if (! is_array($arg)) $get[$arg] = $val;
		else foreach ($arg as $key=>$value) $get[$key] = $value;
	}

	# build query string
	$query = array();
	foreach ($get as $key=>$value) if ($value <> '') {
		if ($this->cfgModRewrite <> true OR $key <> 'path') {
			$query[] = urlencode($key).'='.urlencode($value);
		}
	}
	if (($query = join('&',$query)) <> '') $query = '?'.$query;

	if ($this->cfgModRewrite) {
		return $this->cfgBaseUrl.str_replace('%2F','/',urlencode($get['path'])).$query;
	} else {
		return $_SERVER['PHP_SELF'].$query;
	}
}


}




class smbwebclient extends webapp {

###################################################################
# CONFIGURATION SECTION - Change for your needs
###################################################################

###################################################################
# You can set this constant to see a single domain (or workgroup),
# a domain and a server, a domain/server/shared resource
# and (even) a path into a domain/server/shared.
# For example to see only folder Bilbo in 'Bolson' shared resource
# at 'HOBBIT' server in 'TIERRAMEDIA' domain would be:
# var $cfgSambaRoot = 'TIERRAMEDIA/HOBBIT/Bolson/Bilbo';
# Note: Do not put any slash at beginning/end.
# Tip: To share only their home directory in your SAMBA server to
#      users (auth req.) you must set cfgSambaRoot to
#      'DOMAIN/SERVER/homes'

var $cfgSambaRoot = '';


###################################################################
# Anonymoys login is disallowed by default.
# If you have public shares in your network, turn on this flag
# i.e. var $cfgAnonymous = true;

var $cfgAnonymous = false;


###################################################################
# Path at web server to store downloaded files. This script will
# check when it need to update the cached file. This path must be
# writable to the user that runs your web server.
# If you set this value to '' cache will be disabled.
# Note: this feature is a security risk.

#var $cfgCachePath = false;
var $cfgCachePath = './cache/';


###################################################################
# This script try to set language from web browser. If browser
# language is not supported you can set a default language.

var $cfgDefaultLanguage = 'en';
 

###################################################################
# Default charset (as suggested by Norbert Malecki)

var $cfgDefaultCharset = 'ISO-8859-1';


###################################################################
# Default browse server for your network. A browse server is where
# you run smbclient -L subcommand to read available domains and/or
# workgroups. Set to 'localhost' if you are running SAMBA server
# in your web server. Maybe you will need cfgDefaultUser and
# cfgDefaultPassword if no anonymous browsing is allowed.

var $cfgDefaultServer = 'localhost';


###################################################################
# Path to smbclient program.
# i.e. var $cfgSmbClient = '/usr/bin/smbclient';

var $cfgSmbClient = 'smbclient';


###################################################################
# Authentication method with smbclient
# 'SMB_AUTH_ENV' USER environment variable (more secure)
# 'SMB_AUTH_ARG' smbclient -U param

var $cfgAuthMode = 'SMB_AUTH_ARG';


###################################################################
# If you have Apache mod_rewrite installed you can put this
# .htaccess file in same path of smbwebclient.php:
#
#  <IfModule mod_rewrite.c>
#   RewriteEngine on
#   RewriteCond    %{REQUEST_FILENAME}  -d
#   RewriteRule ^(.*/[^\./]*[^/])$ $1/
#   RewriteRule ^(.*)$ smbwebclient.php?path=$1 [QSA,L]
#  </IfModule>
#
# Then you will be able to access to use "pretty" URLs
# i.e: http://server/windows-network/DOMAIN/SERVER/SHARE/PATH
#
# To do this, all you have to set is cfgBaseUrl and set
# cfgModRewrite = true
# (i.e. http://server/windows-network/)
#
# Note - Change this if you want to use mod_rewrite

var $cfgModRewrite = false;
var $cfgBaseUrl = '';


###################################################################
# Do not show dot files (like .cshrc)
#

var $cfgHideDotFiles = true;


###################################################################
# Do not show system shared resources (like admin$ or C$)
#

var $cfgHideSystemShares = true;


###################################################################
# Do not show printer resources
#

var $cfgHidePrinterShares = false;


###################################################################
# Log level
# -1 = no messages
#  0 = log actions performed
#  1 = smbclient calls
# >1 = smbclient output
#

var $cfgLogLevel = 0;


###################################################################
# Log facility (User authentication: BasicAuth or FormAuth)
#



###################################################################
# User authentication (BasicAuth or FormAuth)
#

var $cfgUserAuth = 'BasicAuth';


###################################################################
# Change PHP session name ('' to use default session name)
#

var $cfgSessionName = 'SMBWebClientID';


###################################################################
# Virus scanner to upload files -- suggested by Bill R <wjries@hotmail.com>
# Only ClamAV is available in this revision, set to false to
# disable virus scanning.
#
# var $cfgAntivirus = 'ClamAV';

	var $cfgAntivirus = false;


###################################################################
# Format to upload compressed folders: tar, tgz or zip 
#
# var $cfgArchiver = 'tgz';

	var $cfgArchiver = 'zip';

	var $archiverPlugins = array(
		'tar' => '',
		'tgz' => '| gzip -q -c - ',
		'zip' => '| ( cat - > @f.tar; mkdir @f.zip.$$; cd @f.zip.$$; tar xf @f.tar; zip -r -q @f.zip *; cd ..; rm -rf @f.tar @f.zip.$$; cat @f.zip; rm @f.zip )'
	);



###################################################################
# INTERFACE CLASS
###################################################################

# inline files (included using base64_encode PHP function)

var $cfgInlineFiles = true;

var $inlineFiles = array (
	'data/languages.csv' => 'YWY7YWZ8YWZyaWthYW5zCmFyO2FyKFstX11bWzphbHBoYTpdXXsyfSk/fGFyYWJpYwphejthenxhemVyYmFpamFuaQpiZztiZ3xidWxnYXJpYW4KYnM7YnN8Ym9zbmlhbgpjYTtjYXxjYXRhbGFuCmNzO2NzfGN6ZWNoCmRhO2RhfGRhbmlzaApkZTtkZShbLV9dW1s6YWxwaGE6XV17Mn0pP3xnZXJtYW4KZWw7ZWx8Z3JlZWsKZW47ZW4oWy1fXVtbOmFscGhhOl1dezJ9KT98ZW5nbGlzaAplbztlb3xlc3BlcmFudG8KZXM7ZXMoWy1fXVtbOmFscGhhOl1dezJ9KT98c3BhbmlzaApldDtldHxlc3RvbmlhbgpldTtldXxiYXNxdWUKZmE7ZmF8cGVyc2lhbgpmaTtmaXxmaW5uaXNoCmZyO2ZyKFstX11bWzphbHBoYTpdXXsyfSk/fGZyZW5jaApnbDtnbHxnYWxpY2lhbgpoZTtoZXxoZWJyZXcKaGk7aGl8aGluZGkKaHI7aHJ8Y3JvYXRpYW4KaHU7aHV8aHVuZ2FyaWFuCmlkO2lkfGluZG9uZXNpYW4KaXQ7aXR8aXRhbGlhbgpqYTtqYXxqYXBhbmVzZQprbztrb3xrb3JlYW4Ka2E7a2F8Z2VvcmdpYW4KbHQ7bHR8bGl0aHVhbmlhbgpsdjtsdnxsYXR2aWFuCm1zO21zfG1hbGF5Cm5sO25sKFstX11bWzphbHBoYTpdXXsyfSk/fGR1dGNoCm5vO25vfG5vcndlZ2lhbgpwbDtwbHxwb2xpc2gKcHQtYnI7cHRbLV9dYnJ8YnJhemlsaWFuIHBvcnR1Z3Vlc2UKcHQ7cHQoWy1fXVtbOmFscGhhOl1dezJ9KT98cG9ydHVndWVzZQpybztyb3xyb21hbmlhbgpydTtydXxydXNzaWFuCnNrO3NrfHNsb3ZhawpzbDtzbHxzbG92ZW5pYW4Kc3E7c3F8YWxiYW5pYW4Kc3I7c3J8c2VyYmlhbgpzdjtzdnxzd2VkaXNoCnRoO3RofHRoYWkKdHI7dHJ8dHVya2lzaAp1azt1a3x1a3JhaW5pYW4KemgtdHc7emhbLV9ddHd8Y2hpbmVzZSB0cmFkaXRpb25hbAp6aDt6aHxjaGluZXNlIHNpbXBsaWZpZWQ=',
	'data/mime.types' => 'bWRiIGFwcGxpY2F0aW9uL21zYWNjZXNzCmRvYyBhcHBsaWNhdGlvbi9tc3dvcmQKZG90IGFwcGxpY2F0aW9uL21zd29yZApiaW4gYXBwbGljYXRpb24vb2N0ZXQtc3RyZWFtCnBkZiBhcHBsaWNhdGlvbi9wZGYKcGdwIGFwcGxpY2F0aW9uL3BncC1zaWduYXR1cmUKcHMgYXBwbGljYXRpb24vcG9zdHNjcmlwdApydGYgdGV4dC9ydGYKeGxzIGFwcGxpY2F0aW9uL3ZuZC5tcy1leGNlbApwcHQgYXBwbGljYXRpb24vdm5kLm1zLXBvd2VycG9pbnQKcHBzIGFwcGxpY2F0aW9uL3ZuZC5tcy1wb3dlcnBvaW50CnBvdCBhcHBsaWNhdGlvbi92bmQubXMtcG93ZXJwb2ludAp6aXAgYXBwbGljYXRpb24vemlwCmRlYiBhcHBsaWNhdGlvbi94LWRlYmlhbi1wYWNrYWdlCmR2aSBhcHBsaWNhdGlvbi94LWR2aQpndGFyIGFwcGxpY2F0aW9uL3gtZ3Rhcgp0Z3ogYXBwbGljYXRpb24veC1ndGFyCnRheiBhcHBsaWNhdGlvbi94LWd0YXIKaWNhIGFwcGxpY2F0aW9uL3gtaWNhCmpzIGFwcGxpY2F0aW9uL3gtamF2YXNjcmlwdApsaGEgYXBwbGljYXRpb24veC1saGEKbHpoIGFwcGxpY2F0aW9uL3gtbHpoCmx6eCBhcHBsaWNhdGlvbi94LWx6eApjb20gYXBwbGljYXRpb24veC1tc2Rvcy1wcm9ncmFtCmV4ZSBhcHBsaWNhdGlvbi94LW1zZG9zLXByb2dyYW0KYmF0IGFwcGxpY2F0aW9uL3gtbXNkb3MtcHJvZ3JhbQpkbGwgYXBwbGljYXRpb24veC1tc2Rvcy1wcm9ncmFtCm1zaSBhcHBsaWNhdGlvbi94LW1zaQpwYWMgYXBwbGljYXRpb24veC1ucy1wcm94eS1hdXRvY29uZmlnCm9nZyBhcHBsaWNhdGlvbi94LW9nZwpzd2YgYXBwbGljYXRpb24veC1zaG9ja3dhdmUtZmxhc2gKc3dmbCBhcHBsaWNhdGlvbi94LXNob2Nrd2F2ZS1mbGFzaAp0YXIgYXBwbGljYXRpb24veC10YXIKdGV4IHRleHQveC10ZXgKbWFuIGFwcGxpY2F0aW9uL3gtdHJvZmYtbWFuCmNydCBhcHBsaWNhdGlvbi94LXg1MDktY2EtY2VydAphdSBhdWRpby9iYXNpYwpzbmQgYXVkaW8vYmFzaWMKbWlkIGF1ZGlvL21pZGkKbWlkaSBhdWRpby9taWRpCmthciBhdWRpby9taWRpCm1wZ2EgYXVkaW8vbXBlZwptcGVnYSBhdWRpby9tcGVnCm1wMiBhdWRpby9tcGVnCm1wMyBhdWRpby9tcGVnCm0zdSBhdWRpby94LW1wZWd1cmwKcmEgYXVkaW8veC1yZWFsYXVkaW8Kcm0gYXVkaW8veC1wbi1yZWFsYXVkaW8KcmFtIGF1ZGlvL3gtcG4tcmVhbGF1ZGlvCnBscyBhdWRpby94LXNjcGxzCndhdiBhdWRpby94LXdhdgpibXAgaW1hZ2UveC1tcy1ibXAKZ2lmIGltYWdlL2dpZgppY28gaW1hZ2UvaWNvCmllZiBpbWFnZS9pZWYKanBlZyBpbWFnZS9qcGVnCmpwZyBpbWFnZS9qcGVnCmpwZSBpbWFnZS9qcGVnCnBjeCBpbWFnZS9wY3gKcG5nIGltYWdlL3BuZwpzdmcgaW1hZ2Uvc3ZnK3htbApzdmd6IGltYWdlL3N2Zyt4bWwKdGlmZiBpbWFnZS90aWZmCnRpZiBpbWFnZS90aWZmCndibXAgaW1hZ2Uvdm5kLndhcC53Ym1wCnJhcyBpbWFnZS94LWNtdS1yYXN0ZXIKam5nIGltYWdlL3gtam5nCnBubSBpbWFnZS94LXBvcnRhYmxlLWFueW1hcApwYm0gaW1hZ2UveC1wb3J0YWJsZS1iaXRtYXAKcGdtIGltYWdlL3gtcG9ydGFibGUtZ3JheW1hcApwcG0gaW1hZ2UveC1wb3J0YWJsZS1waXhtYXAKeGJtIGltYWdlL3gteGJpdG1hcAp4cG0gaW1hZ2UveC14cGl4bWFwCnh3ZCBpbWFnZS94LXh3aW5kb3dkdW1wCndybCB4LXdvcmxkL3gtdnJtbAp2cm1sIHgtd29ybGQveC12cm1sCmNzdiB0ZXh0L2NvbW1hLXNlcGFyYXRlZC12YWx1ZXMKY3NzIHRleHQvY3NzCmh0bSB0ZXh0L2h0bWwKaHRtbCB0ZXh0L2h0bWwKeGh0bWwgdGV4dC9odG1sCmFzYyB0ZXh0L3BsYWluCnR4dCB0ZXh0L3BsYWluCnRleHQgdGV4dC9wbGFpbgpkaWZmIHRleHQvcGxhaW4KcnR4IHRleHQvcmljaHRleHQKdHN2IHRleHQvdGFiLXNlcGFyYXRlZC12YWx1ZXMKd21sIHRleHQvdm5kLndhcC53bWwKd21scyB0ZXh0L3ZuZC53YXAud21sc2NyaXB0CnhtbCB0ZXh0L3htbAp4c2wgdGV4dC94bWwKZmxpIHZpZGVvL2ZsaQpnbCB2aWRlby9nbAptcGVnIHZpZGVvL21wZWcKbXBnIHZpZGVvL21wZWcKbXBlIHZpZGVvL21wZWcKcXQgdmlkZW8vcXVpY2t0aW1lCm1vdiB2aWRlby9xdWlja3RpbWUKYXNmIHZpZGVvL3gtbXMtYXNmCmFzeCB2aWRlby94LW1zLWFzZgphdmkgdmlkZW8veC1tc3ZpZGVvCmljZSB4LWNvbmZlcmVuY2UveC1jb29sdGFsawp2cm0geC13b3JsZC94LXZybWw=',
	'style/disk.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1AUHDgIvgzUxgQAAAjBJREFUeJytlM9rE1EQxz9NtLWSi6Ft1pZicbehiMRAD6JZUGiFhRwEQfAk6kUPXgo5+KMi/gW1Fg9e2kPRQ0SK2NIFjyaNFGvqraZ5EJpD06CkMTHFpPF5qI1Zt4sizmnel5nPm5k3PPhP1vK7sLYwInf9mu8mqqraYv4IWlsYkb2nrgJVALKJp7YEJ3hD+BgdkGr4Me6D6R1BdgFeaPkCfAKqZBMrvEpqGIZhg+1rPrjlFlQqP08ZIENq7onl5uFO2F6aQAghm2EWUH2zgPt7gdTbaENTz8/jbs0DdSjVAEiZ1zFN0wKzgCobH1gXs/gvzgMpYAtINEW0kjJHiUz3o+tlS6UW0LqY5fDxG1BfBD5jM9kKgKIoBINBHFsD2O89BqWkHQKkXk9x99kRBk8eRVVV54r8xuSOs/m1odW3vyHevQQgMt1PIBAgFAqhaZrzq1XziwBk3kebZSLT/SiKgq5rhEIhdF133iMhhNxeCjcSL1+5xuidW/j9foLBILquo2ma46ZbxHg8LmOxGEN9c3R0n0DWy6wkZsh3j2MYRsHn83n3ggC4dp3nL2Zkm7eTs+ELeLwqPfoj+s7szGxg8DQbxeKhe/cfSCGE3AvUmNHU1CS+nl6qNbgdXqCUvITLVUe6DzAxNkZ7GyTexFC6OmxbbQFNjD/ENE3S6TTJ+CrJ+GojqJjP0q4onBsecurMOiMhhCyXy+RyOZaXl8nlcsCvBVQUBY/H89dfyz/ZD84Fy8JJpTM+AAAAAElFTkSuQmCC',
	'style/dotdot.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1AUHDyEuS1kcQQAAAlJJREFUeJy9kl9IU2EUwH+zbdd73fTOa+mCdOoqExcVFOSSLKWHwrCnHuqxIBN6XVK9hVQPEUTWWyBSvYigID0EFQqBf0DTyLLplmL+u013tznn5u2lBjZdENR5+77D+X2/c74D/zMa2xr0qntV+ujXYf2vId1DXXqmx6jbn1j1/CZFv9PdvCnM8CdQ8U2HvuYMUKBYkI0CU2NBhOBOHp9/iLvseLI+Ix2ksa1B1+QFChQL2yUJxSpRXeUg27FMTUstTe2epF2KkbfjcDLpfDlAVq4Z07YMDh204yzKxWQy0fV8hqBxHoDA7RUDgPF3SE75JWTHEbS4yheakrnT3kn2OfMRRZFC2y56PP4NEhtAAMru/WDoQzaDXH8XUIAwgVunEEURSZRYX1tMGUMKiNgkGGI/Dx8AmOhupXhdRxIlwuE1lCwlOYLSc/2prQEQDJCIzRFZniWk+liNRskpv4rkfYpZEPg8rnLCdZaSmkZ8vdeB/i2MlkPMeN9h3XsFe/kxIAio+MOfEKVCBgf9NF8oQ/vWiaAcBVo2ByVWY5C9H9lhADoB8KkqViWT98PTuDPzcFhiTA60U3KyY+s9+r44iuKsB80HmgaaRuubt0SjcYZfjXO/ro6IOoQ5tzb9sOPxGJIkQGglefegt4fqwiIe1VeSmBlhLjBLyZm+9N+PtQyCYxAJoS1NE1qa4kWFgJIYY2EkgpjvxlJ8DbiY3sgs7+Hjaw+R0DyCzYW0w42r8jKCzYViP2AAP/As5f0Ner92I6viBoKtotSW55xIqfjX8QPSvc+A1HHT7gAAAABJRU5ErkJggg==',
	'style/favicon.ico' => 'AAABAAEAICAAAAAAAACoCAAAFgAAACgAAAAgAAAAQAAAAAEACAAAAAAAgAQAAAAAAAAAAAAAAAEAAAAAAAAAAAAA4vTCAOvOhAB70H4A5a1KAEy3VADI46YAvo8MAN3d3ACfXwwAtbWwAAx5BACXkowAlHBTAMbGxgD6+vkAhl9BAP///wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgOCgoKCggIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgODAwMDAwMDQwKAAAAAAAAAAAAAAAAAAAAAAAAAAAICgoOCAgPCA4KDAwMCAAAAAAAAAAAAAAAAAAAAAAAAAoKCAgPDw8PDw8ODA0MAAAAAAAAAAAAAAAAAAAAAAAOCggPCA8PDw8PDw8ICg0OAAAAAAAAAAAAAAAAAAAAAAoICAgIDw8PDw8PDw8ODAwAAAAAAAAAAAAAAAAAAAAOCg8IDwgICAgIDw8PDwgMDAAAAAAAAAAAAAAAAAAADgwIDwgPCAgPCA8ICA8PDwoMCAAAAAAAAAAAAAAAAAoHDAgPDwgIDwgICAgIDw8ICg0OAAAAAAAAAAAAAAAKEAkMCA8PDw8IDwgPCAgIDwgKCQ0IAAAAAAAAAAAAChAJCQwPDw8PCA8IDwgPCAgPCAwQEAwAAAAAAAAAAAANCQkJDQ8PDw8PDwgPCAgIDw8ODRAQEAoAAAAAAAAICgkJCQkMDw8PDw8PDw8PCA8ICAwQEBAQDAAAAAAAAAACCQkJCQ0ODw8PDw8PDwgPCAgOEBAQEBAQCAAAAAAAAQ0JCQkJCg4ODg8PCAgIDwgPDgwJCRAQEBAKAAAAAAAGDQkJCQkEDw8IDgoNDQ4IDgoKEAkJEAsQEAwAAAAAAAIJCQkJCQwIDw8PDBANDggIBg0LCwkLEBAJDAAAAAAAAgkJCQcJBAoPDw8KDQwPDw4MCwsLCwkLEAkMAAAAAAAKBwkMDgoLBQwKCAoKCAEODAcLCwsLCQsQCQwAAAAAAAIHDQoMCgoFBQUKDgUCBAcHBwcLCwsJCQkJDAAAAAAABgcJBwwFCg4KDg8CBAIEBAcHBwcLCwsLCwkMAAAAAAABBAkHBQUFAwgBAwMDAgQEBAcHBwsLCwsLCQgAAAAAAAACBwcFBQUDAwMDAwMCAgQEBwcLBwsLCwsNCAAAAAAAAAEHBwUFAwMDBgMDBgYGBAQEBwcLCwsLCQwAAAAAAAAAAQMFBQMDAwYGBgEBBgMCBAcHBwcLCwsECAAAAAAAAAAAAQUFAwMDBgYBAQEGAwUFBQcHBwkLBwoAAAAAAAAAAAAAAQUDAwMGAQEGBgMDAwUHBwkHCQcKAAAAAAAAAAAAAAAABgUDAwYDBgYGAwMFBQcHBwcHBAAAAAAAAAAAAAAAAAAAAQYDAwYGBgMDAwUFBAcHBwIAAAAAAAAAAAAAAAAAAAAAAQYDAgMGAwMDBQUHBwQIAAAAAAAAAAAAAAAAAAAAAAAAAAEBAgIDBAQFAgIBAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEBAQEAAAAAAAAAAAAAAAAAAP/4B///4AP//8AA///AAP//gAB//4AAf/8AAH/+AAA//AAAP/gAAB/wAAAf8AAAD8AAAA/gAAAHwAAAB8AAAAfAAAAHwAAAB8AAAAfAAAAHwAAAB8AAAAfgAAAH4AAAD+AAAA/wAAAf+AAAP/wAAH/+AAD//wAB///AB////D//',
	'style/file.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1AUHDh0ZgdWqhgAAAPZJREFUeJyt0jESgjAQBdAfh8KextbSnoPYJL2NuYONkgJmOIJHgE6OkFYmnR5BbkC5NojGEIyOW2122DebJcCfgj2SLMsopCGOY0gp2Xs9ej1sNuuP0OmkwTmnqqoszIIWi1XATBplWUIIYWGzgE4nhBAAAM75sI7o/aM8zz9CdV1Da42iKIaaAwHAbheDzkewZDsKzedAkiRWzXs1H+KL0YmI/EjbXrFcuvWJZTOk7PmHmfNyAqGUAftXhKYxL7QnQtrnRL188EujO2rbKwBA3i5DfpMX/zhjkFIKSqnJpiCo67qvEQdqmuYnxIKMMTDG/Az9Le5YqEcLs1eIzgAAAABJRU5ErkJggg==',
	'style/folder.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1AUHDhwE+8j3HgAAAY1JREFUeJzNkz9IW1EUxn+XCo9AB6dUx5fBQQQpShMcOhQyubZroYubi+AYh64OQoeCrnX0YbFjoEMRfaWZHDp0yIOACJlj8vLnnNPhmWcSY4yL+MEdLufe7/7u4Tvw3OQGN5X9VTMzVBVVZW79O77vu/suj9WNyZB+7S1aGIY2NVFlf9VWNv4AByPlDX7uLiAiiEhCaoqIsPzpfIh2BsDMIP4CIiNGe7zb/nfn9ePSK4IgoFqtWi6XcynR76+v7c2H99DtcXZ0NBa92+3S6/XSZSQ/Xvp4iu/7bgZAVeG6ydnJCWubFw835OozzO9wuPWSIAhuv6aq0Gjged6YPt1VO4rw5g8QEWq12q2RiEAcJ70CiOOJRq1WC+8GwJyNEAGdTic52Wg8aDTbv/eCEaJBo+vmRKNmM6nfa9Rut6cmgiQ2ziVRcgBRFNnfb2/TsKlqGsLBfX90VBUz48flOvl8nlKp5NJkhmFo5XKZer0+kWZQ2WyWYrFIoVBwQwMZRZH1sadRJpN5/FA/mf4DyGb4kNXbm0QAAAAASUVORK5CYII=',
	'style/logout.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsQAAALEAGtI711AAADd0lEQVR42j2TTWucVRiGr+ecM1+ZfEzTjzSC0baiNEq1tWCroEJx5UJE0KUL8R90J+jO7gXRtYJId26KiFQs2rpwUbXQNtXW0MSQkEkymczM+77nPOdxMdE/cMN9Xfct33x4xs6+8DI62ianETmVoBVZI4JhGGTFLEFOWFbIiqaCWI4owhHWdhLh7LlXqE09Qv3A42jRQ8seliosRywnyIpvTuObHcCh5Taxt0IaboLvs9frAjOEXPRws8fBeVyoY6lOtgwYIp7m/NNMLJwntI8gvo4WW5TdP+ndukyq7uB8jb3uCiGnEi37iAvkOCKnghxLTEta82eYXnwTTOnf+5a0t07j0FNMnriAr08Sr3+C7N7F+UDIcYgWPcQ5cizI5ZAcC3xzmvbCi1gcsvbdB6ThJuIDg+WfGPz9I0df+5jpJ19na30ZcZ5gWpHjABE/ZqNjPvXOArXOAt1fPiX2V6lNzXH4pYtYrlj/4SP27n9Pc26R2tQcyAbBNGKpBPHYvhXLCUMQcVS9ZUDJVY/h6g3AsNSn3LpHe+ZxXKgjQDCL5FggLoCNVbMPG8A1JhHJ5HKT3dtfYblEnFHvPIppgeUCJOMsllgqMN3XTcY3p2kdPYWEJq5WQzxIANhDZEjj0HHaj71KtbOEDlcRU4LliGkJIoDDt2boPPMOrfnn2Pr1M8rNW7Tmn8W1JtDhP7TmTjN98j3i7l/s3b+M5R4iiWBa/s8otA/RWXyL1tFT9G59zfDhNVpzpzh47uJ4Y1mxuEPZvUHv9ufE3d9xLiMoIWtJ1pL65BwzJ99gYuE82799yeDBVUwLpk++jfM1+ktfoKNltFon9m6iRRfxGVCwSEArTAsmFs7TPn6B/tIVBg+uUp89QZg6jPgAQCpWGa1dQaQCCnAJJO0Hpf92VOAbU6TBBv2lKzQOLzL7/Pv41iziPKPVq5Qb1xExkIxIRnICqv1DKyFrhcQRWROhdYADp9+lcfAJ4u4KZfcOpgO2b14C2UV8RCRiJCwlLCmWEjkZoaoqghvSX76OZkNEGKz9weDhNeL2XfCKq0WcrxBX7dcZh8SREisjqiE/X1o0HWzgQhNXa4yHiY3f7zLiMr6WcJLARcwSYhG0oiqVnR1jW48RNkdNNB2hv7GC8wFxHjMQwDlDnCEkglMgo3msWyyTkkH7GBMTHf4FzZoY5K8MTx4AAAAASUVORK5CYII=',
  'style/page.thtml' => 'PD94bWwgdmVyc2lvbj0iMS4wIj8+CjwhRE9DVFlQRSBodG1sCglQVUJMSUMgIi0vL1czQy8vRFREIFhIVE1MIDEuMS8vRU4iIAoJImh0dHA6Ly93d3cudzMub3JnL1RSL3hodG1sMTEvRFREL3hodG1sMTEuZHRkIj4KPGh0bWwgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGh0bWwiPgo8aGVhZD4KCTx0aXRsZT57dGl0bGV9PC90aXRsZT4KCTxtZXRhIGh0dHAtZXF1aXY9ImNvbnRlbnQtdHlwZSIgY29udGVudD0idGV4dC9odG1sOyBjaGFyc2V0PXtjaGFyc2V0fSIgLz4KCTxsaW5rIHJlbD0iaWNvbiIgaHJlZj0ie2Zhdmljb259IiB0eXBlPSJpbWFnZS9pY28iIC8+Cgk8bGluayByZWw9InNob3J0Y3V0IGljb24iIGhyZWY9IntmYXZpY29ufSIgLz4KCTxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyI+Cgk8IS0tCgkJYm9keSwgdGFibGUgeyBjb2xvcjogYmxhY2s7IGJhY2tncm91bmQ6IHdoaXRlOyBmb250LXNpemU6IDhwdDsgZm9udC1mYW1pbHk6IFRhaG9tYSwgQXJpYWwsIEhlbHZldGljYTsgbWFyZ2luLXRvcDogMHB4OyBtYXJnaW4tbGVmdDogMHB4OyBtYXJnaW4tcmlnaHQ6IDBweDsgd2lkdGg6IDEwMCU7IH0KCQlpbnB1dCwgc2VsZWN0IHsgZm9udC1zaXplOiA4cHQ7IH0KCQlhIHsgY29sb3I6IGJsYWNrOyB0ZXh0LWRlY29yYXRpb246IG5vbmU7IHBhZGRpbmctbGVmdDogM3B4OyBwYWRkaW5nLXJpZ2h0OiAzcHg7IH0KCQlhOmhvdmVyIHsgYmFja2dyb3VuZC1jb2xvcjogIzMxNmFjNTsgY29sb3I6IHdoaXRlOyBwYWRkaW5nLXRvcDogM3B4OyBwYWRkaW5nLWJvdHRvbTogM3B4OyB9CgkJdGggeyB0ZXh0LWFsaWduOiBsZWZ0OyB3aGl0ZS1zcGFjZTogbm93cmFwOyBiYWNrZ3JvdW5kLWNvbG9yOiAjZWVlYWQ4OyBib3JkZXItYm90dG9tOiAzcHggc29saWQgI2Q2ZDJjMjsgYm9yZGVyLWxlZnQ6IDFweCBzb2xpZCB3aGl0ZTsgYm9yZGVyLXJpZ2h0OiAxcHggc29saWQgI2Q2ZDJjMjsgcGFkZGluZy1sZWZ0OiAxMHB4OyBwYWRkaW5nLXJpZ2h0OiAzcHg7IHBhZGRpbmctdG9wOiAzcHg7IGZvbnQtd2VpZ2h0OiBub3JtYWw7IH0KCQl0aDpob3ZlciB7IGJhY2tncm91bmQtY29sb3I6ICNmYWY4ZjM7IGJvcmRlci1ib3R0b206IDNweCBzb2xpZCAjZmNjMjQ3OyB9CgkJdGggYTpob3ZlciB7IGJhY2tncm91bmQtY29sb3I6ICNmYWY4ZjM7IGNvbG9yOiBibGFjazsgfQoJCXRoLmxhbmd1YWdlIHsgYmFja2dyb3VuZC1jb2xvcjogIzIyNWFkOTsgYm9yZGVyLWJvdHRvbTogM3B4IHNvbGlkICMzODg4ZTk7IGJvcmRlci1sZWZ0OiAxcHggc29saWQgIzM4ODhlOTsgYm9yZGVyLXJpZ2h0OiAxcHggc29saWQgYmxhY2s7IHBhZGRpbmctcmlnaHQ6IDEwcHg7IGNvbG9yOiB3aGl0ZTsgfQoJCXRoLmxhbmd1YWdlIGEgeyBjb2xvcjogd2hpdGUgfQoJCXRoLmxhbmd1YWdlIGE6aG92ZXIgeyBiYWNrZ3JvdW5kLWNvbG9yOiAjMjI1YWQ5OyB9CgkJdGgudG9vbGJhciB7IGJhY2tncm91bmQtY29sb3I6ICMxMjhiZTY7IGJvcmRlci1ib3R0b206IDNweCBzb2xpZCAjMTliOGYyOyBib3JkZXItbGVmdDogMXB4IHNvbGlkICMxOWI4ZjI7IGJvcmRlci1yaWdodDogMXB4IHNvbGlkIGJsYWNrOyBwYWRkaW5nLXJpZ2h0OiAxMHB4OyBjb2xvcjogd2hpdGU7IH0KCQl0aC50b29sYmFyIGEgeyBiYWNrZ3JvdW5kLWNvbG9yOiAjMTI4YmU2IH0KCQl0ZCB7IHdoaXRlLXNwYWNlOiBub3dyYXA7IHBhZGRpbmctcmlnaHQ6IDEwcHg7IHBhZGRpbmctbGVmdDogNXB4OyBwYWRkaW5nLXRvcDogMHB4OyBwYWRkaW5nLWJvdHRvbTogMHB4OyB2ZXJ0aWNhbC1hbGlnbjogbWlkZGxlOyB9CgkJdGQub3JkZXItYnkgeyBiYWNrZ3JvdW5kLWNvbG9yOiAjZjdmN2Y3OyB9CgkJdGQuY2hlY2tib3gsIHRoLmNoZWNrYm94IHsgd2lkdGg6IDIwcHg7IHRleHQtYWxpZ246IHJpZ2h0OyBwYWRkaW5nLWxlZnQ6IDBweDsgcGFkZGluZy1yaWdodDogMHB4OyB9CgktLT4KCTwvc3R5bGU+CjwvaGVhZD4KPGJvZHk+Cgp7Y29udGVudH0KCjwvYm9keT4KPC9odG1sPg==',
  'style/view.thtml' => 'PHNjcmlwdCBsYW5ndWFnZT0iSmF2YVNjcmlwdCI+CmZ1bmN0aW9uIHNlbF9hbGwobWFzdGVyX3NlbGVjdCkgewoJd2l0aCAoZG9jdW1lbnQuZF9mb3JtKSB7CgkJZm9yIChpPTA7IGk8ZWxlbWVudHMubGVuZ3RoOyBpKyspIHsKCQkJZWxlID0gZWxlbWVudHNbaV07CgkJCQlpZiAoZWxlLnR5cGU9PSJjaGVja2JveCIpCgkJCQkJZWxlLmNoZWNrZWQgPSBtYXN0ZXJfc2VsZWN0LmNoZWNrZWQ7CgkJfQoJfQp9Cjwvc2NyaXB0PgoKPGZvcm0gZW5jdHlwZT0ibXVsdGlwYXJ0L2Zvcm0tZGF0YSIgYWN0aW9uPSJ7YWN0aW9ufSIgbWV0aG9kPSJwb3N0IiBuYW1lPSJkX2Zvcm0iPgo8dGFibGUgY2VsbHBhZGRpbmc9IjAiIGNlbGxzcGFjaW5nPSIwIiBib3JkZXI9IjAiPgo8dHI+PHRoIGNsYXNzPSJjaGVja2JveCI+PGlucHV0IHR5cGU9ImNoZWNrYm94IiBuYW1lPSJjaGthbGwiIG9uY2xpY2s9ImphdmFzY3JpcHQ6c2VsX2FsbCh0aGlzKSIgLz48L3RoPgp7aGVhZGVyfQp7bGluZXN9CjwvdGFibGU+CjwvZm9ybT4K',
	'style/printer.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1AUHDhQANHy5DwAAArlJREFUeJytkU1PE2EUhZ+ZMtOiZbSNpCQCBaVBClOREhI0GglBGtcmGiM7o3sX/gJW7k1kK5su2LiSSMAQ+Yg1tZI0RokUaaDNtM2MmgklHafjglhBysZ4d/fc+z5vzrnwn0o42Lycfe+4T3nJb2m4RJFTykm8ygk+fv3Gw7tDwnEQgIaDze7uLhevXKKnLwSAKOx/9c3aJJ3+6vT1dRwLOzJ4MbvmuM5FaBD3e6sCP6tQ+fSG27euHQtq+FuwzB1OlyPIAriln7hlkxPSLp4rIWZmZpzu7m5UVT0CPCQsLCw4kiTR09PDysoKHo8H0zTxeDy43e6afa/Xy8jIiFAXFI/Hnd7eXnRdR9M0LMsiHA7T2dmJbdvYto1hGORyORzHwbZtxsbGau9r1nZ2dhgeHkaSJHw+H+VyGVmWyWQymKZJtVrF6/XS3NzM3t4eqVSqfkaFQoHl5WWi0SiyLCPLMgB+vx9BEDAMg7W1NUqlEpq1iZ/W+qDBwUHC4TCrq6tYlkUkEiGbzZLNZjFNE1EUaW1tZWhoiHfFH1TWj7maoih0dXXR1tZGLpdjfn6eQqFAS0sLoVAI0zTRdZ1UKoVjKYiiXf9qc3NzTrFYJBaL4XK5KJfLLC0tkc/nSSaTKIpCe3s7/f39qKpKOp1mdHRUOASamppyfguZTIZoNMr4+DiLi4t8/lzCsjyo6mkGBi6yvr5OMpmkqakJgFgsZgSDQX/N2o07D2rkopbn2ZN7VKQurl5W2cxkKG7ofDl7no6B63QMXAdg8tF9tre3fbWMEokEiUTikOfHN9/iP7OJWX5F+EKV5dcbPH/6nb/LMIw/YU9OTp6Px+Mbuq7XFj4kSlSsEgBSg0Cl4tDY2IjP5zsCOxT21taWfnCgaZpvenoaTdNqWiAQYGJigkAgYBzcDQaD/rr0f6lfbq8VahbV+oAAAAAASUVORK5CYII=',
	'style/server.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1AUHCyMCTL66/AAAAnJJREFUeJytlE1PE1EUhp9OP2lL27FtQGwA+QoQxEaIGIMLNyYujNGFP8Ef6MqFRIzRBEwwUA0oFFBKgDLSaad3mJnOzHWBJjSk3ejZ3Nx7c56c8543B/5TBDp9VCpH0jp38aWCXrOoN2wqFZ25OwPM3h6+ktf2UCqVZCAQolYTQJhqtU7T8fGTCfy0imEp1N994OmzeYrzU225ocsX15Usr39HuT6E2neNQnGSQjyC7cGpCXYT6q/ecqbpVzpoA3legMTsffRMDjcOugemCS0PDBvMFshQDKMuuoPCoSi9rsdPG5QAOB6EFHB9EC1oWCBzAwhhdAclEnGSWouzc3A9EA4oCvg+WC40bHCVHs60CnrNGMmovbt/c5XLoLHxQqB1dIImoCrguAnHxsVZFaCZINI3MBoCpBzpWBFAqjcBWo2TtEokCEEFPP9Pm7ZDrinQzxq4nvcSeNMRFIuF6dEcykGIBC+0uhsR9GsaYmOTo/1NEoV4d40AVDVO7IdPWEoe9VpMaMesvC6ztHXEUH6fmVuDLCwWyeXVF11BEslks8qTjEn9QNBoWGxtXWgaTRUYnxrh3oPiFWe3gXZ3T2UmE+T542FUNUitplMqGQwNJdG0Jvn+Psp7gr2dirw5VmiDtU3NNE1arRaqGsRxHDzPI5VSmJ7OE49fWOHc8vj0ceOKRm2geE+Mw8Nf6LqOYTSxLBfXdVHVMNlsjGQyxkH5G3XdQK8Zncc/MtoXWF76LNdWt8n3ZwmGwti2j+NAXzbKzvoq6VSE0YlBLpsROqyRtZUvcuX9Bl9L2xgNgZSSaDTM5MwoC4tFFh/OdVw//xy/AbreHTutN5hZAAAAAElFTkSuQmCC',
	'style/workgroup.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1AUHCyo0UsaULAAAAgJJREFUeJytlDtvUlEAgD/uBQryCGITgokoOQNpJC2LGEUmXzHpYDTqYOJQp7r3H7gZJ2P0B+jSwbi03ri4dKCaakxzF700ggYItxSlF8ujcB1IC+VlGj3bOec7X3LOlxz4T8MybnNped3sna/pR7h7AYQQA+ekcSJDksidDlNLRCmdj1KfcKAoCul02uxnreNETdlJbtdFtQyGBPXtBpqmYRjGADtWVGu1SRXBZwdr28RXqIw8MFa0kW3jthSQ7RLHmjuU028JnXBjs9kOJ7p/USaT2WB+fo5IJIIQgpmZBH6/f7RoWKHetwgEAly6PNfZ+6iztLxu9lbcFxmSRGXqJH6fi6oJdSXHyorC1FQMALfbPZRRlNdAT/69Qt/LkK10Cplmg0DpAa8eOjjq9QxlNE07eLVhhZ68eI732lcAXr6psXDu3siK+6L+Qr++vSMej/NZnkWSZB49niU1ouIB0SlXg6i9QqlYJZ/doVBNkUgkiMVieDweivrPAabY+IQQV7qiZ08XzZtXj7Nt1NG+/MZi1vD4bhAKTZBMJi17zO07033Mdfx+R1d05uw07z9k2dysdBatu+h6hq2tSfL5vBkMBi2jmFJpsltNiICQpBJOZwPTrLG6qtBq/cDr9SLL8trfGOj5RnRdX1RV9ZaqqjSbTYQQRKNRwuHwoZh/Hn8AelUdaSkrxgYAAAAASUVORK5CYII=',
	'style/down.png' => 'iVBORw0KGgoAAAANSUhEUgAAAAsAAAALCAYAAACprHcmAAAABmJLR0QA6wDqANsTMxObAAAACXBIWXMAAAsQAAALEAGtI711AAAAB3RJTUUH1AUHCyYmDcqqaAAAADhJREFUeJxjYKAUrFkx8z82cUZ8CkMi0hlxKsZmIrIGRnwK0TUwElKIrAHZZHwKEc7ApxBdwyAAAB3iGPP2uU9fAAAAAElFTkSuQmCC',
	'style/up.png' => 'iVBORw0KGgoAAAANSUhEUgAAAAsAAAALCAYAAACprHcmAAAABmJLR0QA6wDqANsTMxObAAAACXBIWXMAAAsRAAALEQF/ZF+RAAAAB3RJTUUH1AUHCyYQwnA/8QAAAEhJREFUeJxjYCABMDIwMDCsWTHzPyGFIRHpjIwwDj4NIRHpjMgmw8SxaWCEamBANhmbBrhCOAeHBhSFGIrRNKAoxAmQNQxCAABCrxj04y1lDgAAAABJRU5ErkJggg==',
  'style/rename.png' => 'iVBORw0KGgoAAAANSUhEUgAAABIAAAASCAYAAABWzo5XAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsSAAALEgHS3X78AAAAB3RJTUUH1QEcDB4AdNMgEAAAAe9JREFUOI2tk01IFHEYxn+zM7vKmvshuCghaVuHIPpYlHIPIZQQVAgRxOYWoplCW0JEx/DQJYIgMPTSB2i3KKKLuUF1SetUeIgObrGHwAppG2dlZ2bn32HZdaeYZRZ6jg8PP573ff9/+E+S3IQSZxJiR3QnkfYo3QcP8erFU0KhIJbUQGo86YoBwK2bN0QuVxCLix/F2NhFsbS0IrJZIQ73HxfljOIGFG7bw9nhEeI93UxOTnFpIsH3tQKBlo5KRgFIDo0LRwrwMv2cgm6gaTkymQyrP/N0bOvEI3ROJq+IJ3O3pUqj2QfTjiBdN1DXNe5OzWAYEAgGeHT/DqcSwxTlpn9Hkw88c4QV3w0AYBigyHIpL8sYlgWAp9ZIf8sSJZDHUzqUBJR3UhdICDDNKqPq8PWBLGEHVanuRoZh9ySpVMu27PJCnWSaRUwTNC1f8RRF2QTNPZyRfuv+mm8JoLO1CV2HL9lvDAxeRaKZQPMWe6PLqVRNyEZe5f2bBcBLe6SVvbt3sW9/jJZwmNnp65ugI/Htjp/vwvmU8DV20ds7gs8XJt5zjs8f5lGsdfqPnY7aGtXS2q8f9PWNsrz8mKJYxShsJRa7xkJ6An+obQWQXIGOnhhkPn0PS2rA6/Xib/zKp7evCUa6UFXVDcK9/gBonqZcTQIbiwAAAABJRU5ErkJggg==',
);

var $strings = array (
	'es' => array(
		'Red Windows',
		'Nombre',
		'Tama&ntilde;o',
		'Comentarios',
		'Modificado',
		'Tipo',
		'd/m/Y H:i',
		'Imprimir un archivo',
		'Borrar elementos seleccionados',
		'Nuevo archivo (subir)',
		'Cancelar trabajos seleccionados',
		'Carpeta',
		'Archivo %s',
		'Enviar un mensaje',
		'Subir',
		'Nueva carpeta',
		'Descargar esta carpeta',
		'Desconectar',
		'Renombrar los elementos seleccionados'
	),
	'no' => array('Windowsnettverk','Navn','St&oslash;rrelse','Kommentar','Endret','','d-m-Y h:i','Utskrift','Slett valgte','Ny fil','Avbryt valgte','Filmappe','Fil %s','Send popup-melding','Opp','Ny mappe'),
	'eu' => array('Windows Sarea','Izena','Tamaina','Komentarioak','Aldatua','','Y/m/d h:i','Inprimatu','Ezabatu aukeratuak','Fitxategi berria','Ezeztatu hautespena','','','','',''),
	'fr' => array(
		'R&eacute;seau Windows',
		'Nom',
		'Taille',
		'Commentaire',
		'Modifi&eacute;',
		'Type',
		'd/m/Y h:i',
		'Imprimer',
		'Effacer la s&eacute;lection',
		'Nouveau fichier (envoi)',
		'Annuler la s&eacute;lection',
		'Dossier',
		'Fichier %s',
		'Envoyer un message popup',
		'Remonter',
		'Nouveau dossier',
		'T&eacute;l&eacute;charger ce dossier',
        'D&eacute;connexion',
        'Renommer la s&eacute;lection'
	),
	'gl' => array('Rede Windows','Nome','Tama&ntilde;o','Comentarios','Modificado','Tipo','d/m/Y h:i','Imprimir un arquivo','Borrar elementos seleccionados','Novo arquivo (subir)','Cancelar traballos seleccionados','Carpeta','Arquivo %s','Enviar unha mensaxe','Subir','Nova carpeta'),
	'el' => array('&#916;&#943;&#954;&#964;&#965;&#959; Windows','&#908;&#957;&#959;&#956;&#945;','&#924;&#941;&#947;&#949;&#952;&#959;&#962;','&#931;&#967;&#972;&#955;&#953;&#945;','&#932;&#961;&#959;&#960;&#959;&#960;&#959;&#953;&#942;&#952;&#951;&#954;&#949;','&#932;&#973;&#960;&#959;&#962;','m/d/Y h:i','&#917;&#954;&#964;&#973;&#960;&#969;&#963;&#951;','&#916;&#953;&#945;&#947;&#961;&#945;&#966;&#942; &#917;&#960;&#953;&#955;&#949;&#947;&#956;&#941;&#957;&#969;&#957;','&#925;&#941;&#959; &#913;&#961;&#967;&#949;&#943;&#959;','&#913;&#954;&#973;&#961;&#969;&#963;&#951; &#917;&#960;&#953;&#955;&#949;&#947;&#956;&#941;&#957;&#969;&#957;','&#934;&#940;&#954;&#949;&#955;&#959;&#962; &#913;&#961;&#967;&#949;&#943;&#969;&#957;','&#913;&#961;&#967;&#949;&#943;&#959; %s','&#913;&#960;&#959;&#963;&#964;&#959;&#955;&#942; &#956;&#951;&#957;&#973;&#956;&#945;&#964;&#959;&#962;','&#917;&#960;&#940;&#957;&#969;','&#925;&#941;&#959;&#962; &#934;&#940;&#954;&#949;&#955;&#959;&#962;'),
	'it' => array('Rete Windows','Nome','Dimensione','Commenti','Modificato','Tipo','d/m/Y h:i','Stampa un file','Rimuovi l\'oggetto selezionato','Nuovo file (upload)','Cancella l\'oggetto selezionato','Cartella','','Manda un messaggio popup','Su','Nuova cartella'),
	'pl' => array(
		'Sie&#263; Windows',
		'Nazwa',
		'Rozmiar',
		'Komentarz',
		'Zmodyfikowany',
		'Typ','d/m/Y h:i',
		'Drukuj',
		'Usu&#324; zaznaczone',
		'Nowy plik',
		'Anuluj zaznaczone',
		'Katalog',
		'Plik %s',
		'Wy&#347;lij wiadomo&#347;&#263;',
		'Powr&oacute;t',
		'Nowy katalog',
		'Sciagnij ten plik'
		),
	'ro' => array('Reteaua Windows','Nume','Marime','Comentarii','Modificat','Tip','m/d/Y h:i','Print','Sterge selectia','Fisier Nou','Renunta','Fisier Director','Fisier %s','Trimite mesaj popup','Sus','Director nou'),
	'nl' => array('Windows Netwerk','Naam','Grootte','Commentaar','Gewijzigd','Soort','d/m/Y h:i','Afdrukken','Selectie verwijderen','Nieuw bestand','Selectie afbreken','Bestandsmap','Bestand %s','Stuur een popup bericht','Omhoog','Nieuwe map'),
	'cs' => array('S&iacute;&#357; Windows','N&aacute;zev','Velikost','Pozn&aacute;mka','Zm&#283;n&#283;no','Typ','m&#283;s&iacute;c/den/rok hodina:minuta','Tisk','Smazat vybran&eacute;','Nov&yacute; soubor (nahr&aacute;t)','Zrušit v&yacute;ber','Nov&yacute; adres&aacute;&#345;','Soubor %s','Poslat zpr&aacute;vu','Nahoru','Nov&yacute; adres&aacute;&#345;'),
	'da' => array('Windows Netv&aelig;rk','Navn','St&oslash;relse','Kommentar','Sidst &aelig;ndret','Type','d.m.Y t:i','Print fil','Slet valgte emner','Ny fil (upload)','Afbryd valgte job','Fil mappe','File %s','Send popup besked','Up','Ny mappe'),
	'sv' => array('Windows N&auml;tverk','Namn','Storlek','Kommentar','&Auml;ndrad','Typ','Y/m/d H:i','Skriv ut','Ta bort markerad','Ny fil','Avbryt markerad','Filkatalog','Fil %s','Skicka ett popup-meddelande','Upp','Ny katalog'),
	'de' => array(
        'Windows Netzwerk',
        'Name',
        'Gr&ouml;&szlig;e',
        'Bemerkung',
        'Ge&auml;ndert',
        'Typ',
        'd.m.Y H:i',
        'Datei drucken',
        'Markierte Datei(en) l&ouml;schen',
        'Neue Datei (hochladen)',
        'Abbruch der ausgew&auml;hlten Aktion',
        'Dateiordner',
        'Datei %s',
        'Nachricht senden',
        'Aufw&auml;rts',
        'Neuer Ordner',
        'Dieser Ordner herunterladen',
        'Abmelden',
        'Markierte Datei(en) umbenennen'
     ),
	'ja' => array('&#12493;&#12483;&#12488;&#12527;&#12540;&#12463;','&#21517;&#21069;','&#12469;&#12452;&#12474;','&#12467;&#12513;&#12531;&#12488;','&#26356;&#26032;&#26085;&#26178;','&#12479;&#12452;&#12503;','Y/m/d H:i','&#12503;&#12522;&#12531;&#12488;','&#36984;&#25246;&#12375;&#12383;&#12418;&#12398;&#12434;&#21066;&#38500;','&#26032;&#35215;&#20316;&#25104;','&#36984;&#25246;&#12375;&#12383;&#12418;&#12398;&#12434;&#12461;&#12515;&#12531;&#12475;&#12523;','','','&#12509;&#12483;&#12503;&#12450;&#12483;&#12503;&#12513;&#12483;&#12475;&#12540;&#12472;&#12398;&#36865;&#20449;','','&#26032;&#35215;&#12501;&#12457;&#12523;&#12480;'),
	'tr' => array('Windows A&#287;&#305;','&#304;sim','Boyut','Yorumlar','De&#287;i&#351;tirilme Tarihi','Tip','m/d/Y h:i','Yazd&#305;r','Se&ccedil;imi sil','Yeni dosya','Se&ccedil;im &#304;ptal','Dosya Dizini','Dosya boyu','Uyari iletisi gonder','Yukari','Yeni dizin'),
	'ca' => array('Xarxa Windows','Nom','Tamany','Comentaris','Modificat','Tipus','','Imprimeix','Esborra els seleccionats','Nou arxiu','Cancel&middot;la selecci&oacute;','Carpeta','Fitxer %s','Enviar un missatge','Pujar','Nova carpeta'),
	'eo' => array('Reto  de Windows','Nomo','Grandeco','komentaroj','Modifii','','','Presi','','Nova dosiero','Nuligi','','','','',''),
	'et' => array('Windowsi v&otilde;rk','Nimi','Suurus','Kommentaarid','Muudetud','','d/m/Y h:i','Tr&uuml;ki','Kustuta valitud','Uus fail','T&uuml;hista valitud','Kataloog','Fail %s','Saada popup teade','&Uuml;les','Uus kataloog'),
	'uk' => array('&#1052;&#1077;&#1088;&#1077;&#1078;&#1072; &#1042;&#1110;&#1085;&#1076;&#1086;&#1074;&#1079;','&#1030;&#1084;\'&#1103;','&#1056;&#1086;&#1079;&#1084;&#1110;&#1088;','&#1050;&#1086;&#1084;&#1077;&#1085;&#1090;&#1072;&#1088;&#1110;','&#1047;&#1084;&#1110;&#1085;&#1077;&#1085;&#1080;&#1081;','','d.m.Y h:i','&#1044;&#1088;&#1091;&#1082;&#1091;&#1074;&#1072;&#1090;&#1080;','&#1042;&#1080;&#1076;&#1072;&#1083;&#1080;&#1090;&#1080; &#1074;&#1110;&#1076;&#1084;&#1110;&#1095;&#1077;&#1085;&#1077;','&#1053;&#1086;&#1074;&#1080;&#1081; &#1092;&#1072;&#1081;&#1083;','&#1042;&#1110;&#1076;&#1084;&#1110;&#1085;&#1080;&#1090;&#1080; &#1074;&#1110;&#1076;&#1084;&#1110;&#1095;&#1077;&#1085;&#1077;','','','','',''),
	'bg' => array('&#1059;&#1080;&#1085;&#1076;&#1086;&#1091;&#1089; &#1084;&#1088;&#1077;&#1078;&#1072;','&#1048;&#1084;&#1077;','&#1056;&#1072;&#1079;&#1084;&#1077;&#1088;','&#1050;&#1086;&#1084;&#1077;&#1085;&#1090;&#1072;&#1088;','&#1055;&#1088;&#1086;&#1084;&#1103;&#1085;&#1072;','&#1058;&#1080;&#1087;','&#1084;/&#1076;/&#1075; &#1063;:&#1084;&#1080;&#1085;','&#1055;&#1077;&#1095;&#1072;&#1090;','&#1048;&#1079;&#1090;&#1088;&#1080;&#1081; &#1080;&#1079;&#1073;&#1088;&#1072;&#1085;&#1080;&#1090;&#1077;','&#1053;&#1086;&#1074; &#1092;&#1072;&#1081;&#1083;','&#1054;&#1090;&#1082;&#1072;&#1078;&#1080; &#1080;&#1079;&#1073;&#1086;&#1088;','&#1055;&#1072;&#1087;&#1082;&#1072;','&#1060;&#1072;&#1081;&#1083; %','&#1048;&#1079;&#1087;&#1088;&#1072;&#1090;&#1080; &#1089;&#1098;&#1086;&#1073;&#1097;&#1077;&#1085;&#1080;&#1077;','&#1053;&#1072;&#1075;&#1086;&#1088;&#1077;','&#1053;&#1086;&#1074;&#1072; &#1087;&#1072;&#1087;&#1082;&#1072;'),
	'sr' => array('Windows mreža','Ime','veli&#269;ina','komentari','promenjen','tip','d/m/Y h:i','Odštampaj','Obriši selektovano','Nova datoteka','Odustajem od izabranog','Direktorijum','','Pošalji poruku','Nazad','Novi direktorijum'),
	'hr' => array('Windows mreža','Naziv','Veli&#269;ina','Komentar','Modificirano','','','Ispiši','Obriši selektirano','Nova datoteka','','','','','',''),
	'lv' => array('Windows T&#299;kls','Nosaukums','Izm&#275;rs','Koment&#257;ri','Izmain&#299;ts','','m/d/g s:m','Druk&#257;t','Dz&#275;st izv&#275;l&#275;tos','Jauns fails','Atcelt izv&#275;l&#275;tos','','','','',''),
	'fi' => array('Windows Verkko','Nimi','Koko','Kommentit','Muokattu','Tyyppi','p/k/v t:m','Tulosta','Poista valitut','Uusi tiedosto','Peruuta valinta','Tiedosto kansio','Tiedosto %s','L&auml;het&auml; popup viesti','Yl&ouml;s','Uusi kansio'),
	'hu' => array('Windows h&aacute;l&oacute;zat','N&eacute;v','M&eacute;ret','Megjegyz&eacute;s','M&oacute;dos&iacute;tva','T&iacute;pus','h/n/&Eacute; &oacute;:p','Nyomtat','Kiv&aacute;lasztottak t&ouml;rl&eacute;se','&Uacute;j &aacute;llom&aacute;ny','Kijel&ouml;l&eacute;s elvet&eacute;se','Mappa','F&aacute;jl','El&#337;ugr&oacute; &uuml;zenet k&uuml;ld&eacute;se','Fel','&Uacute;j mappa'),
	'pt-br' => array('Rede Windows','Nome','Tamanho','Coment&aacute;rios','Modificado','Tipo','d/m/Y h:i','Imprimir','Apagar selecionados','Novo arquivo','Cancelar Sele&ccedil;&atilde;o','Pasta de arquivo','Arquivo %s','Enviar Mensagem','Acima','Nova Pasta'),
	'th' => array('Samba Server','&#3594;&#3639;&#3656;&#3629;','&#3586;&#3609;&#3634;&#3604;','&#3627;&#3617;&#3634;&#3618;&#3648;&#3627;&#3605;&#3640;','&#3611;&#3619;&#3633;&#3610;&#3611;&#3619;&#3640;&#3591;&#3621;&#3656;&#3634;&#3626;&#3640;&#3604;','&#3594;&#3609;&#3636;&#3604;','&#3648;&#3604;&#3639;&#3629;&#3609;/&#3623;&#3633;&#3609;/&#3611;&#3637; &#3594;&#3633;&#3656;&#3623;&#3650;&#3617;&#3591;','&#3614;&#3636;&#3617;&#3614;&#3660; File','&#3621;&#3610;&#3626;&#3636;&#3656;&#3591;&#3607;&#3637;&#3656;&#3648;&#3621;&#3639;&#3629;&#3585;&#3652;&#3623;&#3657;','&#3626;&#3656;&#3591; File &#3648;&#3586;&#3657;&#3634;&#3652;&#3611;&#3648;&#3585;&#3655;&#3610;','&#3618;&#3585;&#3648;&#3621;&#3636;&#3585;&#3626;&#3636;&#3656;&#3591;&#3607;&#3637;&#3656;&#3648;&#3621;&#3639;&#3629;&#3585;&#3652;&#3623;&#3657;','','','&#3626;&#3656;&#3591;&#3586;&#3657;&#3629;&#3588;&#3623;&#3634;&#3617; Popup','&#3585;&#3621;&#3633;&#3610;&#3652;&#3611;&#3585;&#3656;&#3629;&#3609;&#3627;&#3609;&#3657;&#3634;','&#3626;&#3619;&#3657;&#3634;&#3591; Folder &#3651;&#3627;&#3617;&#3656;'),
	'zh-tw' => array('&#35222;&#31383;&#32178;&#36335;','&#21517;&#31281;','&#22823;&#23567;','&#35498;&#26126;','&#20462;&#27491;','&#22411;&#24335;','','&#27284;&#26696;&#21015;&#21360;','&#21034;&#38500;&#36984;&#38917;','&#26032;&#27284;&#26696;(&#19978;&#20659;)','&#21462;&#28040;&#25152;&#36984;&#30340;&#24037;&#20316;','&#27284;&#26696;&#36039;&#26009;&#22846;','&#27284;&#26696;%s','&#20659;&#36865;&#35338;&#24687;','&#19978;&#19968;&#38913;','&#26032;&#36039;&#26009;&#22846;'),
	'sl' => array('Windows Omrežje','Ime','Velikost','Komentarji','Sprememba','Tip','m/d/y H:i','Natisni','Izbriši izbrano','Nova datoteka','Prekli&#269;i izbrano','Mapa','Datoteka %s','Pošlji sporo&#269;ilo','Gor','Nova mapa'),
	'id' => array('Jaringan Windows','Nama','Ukuran','Komentar','Terakhir diubah','Tipe','bulan/tanggal/tahun  Jam:menit','Cetak file ini','Hapus item yang dipilih','File baru ( upload )','Batalkan pekerjaan yang dipilih','File dalam folder','','Kirim pesan popup','Naik','Folder baru'),
	'lt' => array('Windows Tinklas','Vardas','Dydis','Komentarai','Pakeista','Tipas','m/d/Y H:i','Spausdinti byl&#261;','Trinti parinktus','&#302;kelti byl&#261;','Nutraukti parinktas užduotis','Katalogas','Byla %s','Si&#371;sti žinut&#281;','Aukštyn','Naujas katalogas'),
	'pt' => array('rede windows','Nome','Tamanho','Comentario','Modificado','Tipo','d/m/Y h:i','Imprimir Ficheiro','Apagar a Selec&ccedil;&atilde;o','Novo Ficheiro','Cancelar a Selec&ccedil;&atilde;o','Ficheiro','Ficheiro %s','Enviar uma mensagem Instantanea','Para Cima','Nova Pasta'),
	'sk' => array('Sie&#357; Windows','Meno','Ve&#318;kos&#357;','Pozn&aacute;mky','Zmenen&eacute;','Typ','m/d/Y H:i','Vytla&#269;i&#357; s&uacute;bor','Vymaza&#357; vybran&eacute; polo&#382;ky','Nov&yacute; s&uacute;bor (upload)','Zru&#353;i&#357; vybran&eacute; &uacute;lohy','Adres&aacute;r','S&uacute;bor %s','Posla&#357; popup spr&aacute;vu','Hore','Nov&yacute; adres&aacute;r'),
	'zh' => array('Windows &#32593;&#32476;','&#22995;&#21517;','&#22823;&#23567;','&#27880;&#37322;','&#20462;&#25913;','&#31867;&#22411;','&#26376;/&#26085;/&#24180; &#26102;:&#20998;','&#25171;&#21360;&#19968;&#20010;&#25991;&#20214;','&#21024;&#38500;&#36873;&#25321;&#39033;&#30446;','&#26032;&#24314;&#25991;&#20214;(&#19978;&#20256;)','&#21462;&#28040;&#36873;&#25321;&#23545;&#35937;','&#25991;&#20214;&#22841;','&#25991;&#20214;','&#21457;&#36865;&#19968;&#20010;&#24377;&#20986;&#28040;&#24687;','&#21521;&#19978;','&#26032;&#24314;&#25991;&#20214;&#22841;'),
	'ru' => array('&#1057;&#1077;&#1090;&#1100; Windows','&#1053;&#1072;&#1079;&#1074;&#1072;&#1085;&#1080;&#1077;','&#1056;&#1072;&#1079;&#1084;&#1077;&#1088;','&#1050;&#1086;&#1084;&#1084;&#1077;&#1085;&#1090;&#1072;&#1088;&#1080;&#1080;','&#1048;&#1079;&#1084;&#1077;&#1085;&#1077;&#1085;','&#1058;&#1080;&#1087;','d/m/Y H:i','&#1055;&#1077;&#1095;&#1072;&#1090;&#1100;','&#1059;&#1076;&#1072;&#1083;&#1080;&#1090;&#1100; &#1074;&#1099;&#1076;&#1077;&#1083;&#1077;&#1085;&#1085;&#1099;&#1077;','&#1053;&#1086;&#1074;&#1099;&#1081; &#1092;&#1072;&#1081;&#1083; (&#1087;&#1086;&#1084;&#1077;&#1089;&#1090;&#1080;&#1090;&#1100;)','&#1054;&#1090;&#1084;&#1077;&#1085;&#1080;&#1090;&#1100;','&#1055;&#1072;&#1087;&#1082;&#1072; &#1092;&#1072;&#1081;&#1083;&#1072;','&#1060;&#1072;&#1081;&#1083; %s','&#1055;&#1086;&#1089;&#1083;&#1072;&#1090;&#1100; &#1089;&#1086;&#1086;&#1073;&#1097;&#1077;&#1085;&#1080;&#1077;','&#1042;&#1074;&#1077;&#1088;&#1093;','&#1053;&#1086;&#1074;&#1072;&#1103; &#1087;&#1072;&#1087;&#1082;&#1072;'),
	'en' => array(
		'Windows Network',
		'Name',
		'Size',
		'Comments',
		'Modified',
		'Type',
		'm/d/Y H:i',
		'Print a file',
		'Delete selected items',
		'New file (upload)',
		'Cancel selected jobs',
		'File Folder',
		'File %s',
		'Send a popup message',
		'Up',
		'New folder',
		'Download this folder',
		'Logout',
	        'Rename selected items'
	),
);

# constructor
function smbwebclient ()
{
	$this->InitSession ();
	$this->InitLanguage();

	if (isset($_GET['O'])) {
		$_SESSION['swcOrder'] = $_GET['O'];
		unset($_GET['O']);
	}
	$this->debug = $this->cfgLogLevel;
	$this->order = @$_SESSION['swcOrder'];

	# load MIME types
	foreach (preg_split('/\n/',$this->GetInlineFile('data/mime.types')) as $line) {
		$a = preg_split('/ /', $line);
		$this->mimeTypes[$a[0]] = $a[1];
	}
}
	
function Run ()
{
	$this->where = $path = stripslashes(@$_REQUEST['path']);

	if (isset($this->inlineFiles[$path])) {
		$this->DumpInlineFile($path);
		exit;
	}

	$this->Go(preg_replace('/\/$/','',preg_replace('/^\//','',$this->cfgSambaRoot.'/'.$path)));

	$this->GetCachedAuth();

	if (isset($_REQUEST['action'])) {
		if (preg_match('/^UpAction([0-9]+)$/', $_REQUEST['action'], $regs)) {
			$this->UpAction($regs[1]);
		} elseif (method_exists($this, $_REQUEST['action'])) {
			$action = $_REQUEST['action'];
			$this->$action ();
		}
	}

	$this->Debug($path);

	switch ($this->Browse()) {
		case '': break;
		case 'ACCESS_DENIED':
		case 'LOGON_FAILURE':
			$this->CleanCachedAuth();
			header('Location: '.$this->GetUrl($path, 'auth', '1'));
			exit;
		default:
			$this->ErrorMessage($this->status);
			header('Location: '.$this->FromPath('..'));
			exit;
	}

	$this->View ();
}




function View ()
{
	$selected = (is_array(@$_POST['selected'])) ? $_POST['selected'] : array(); 
	switch ($this->type) {
		case 'file':  exit;
		case 'network':
		case 'workgroup':
		case 'server':
			$headers = array ('Name' => 'N', 'Comments' => 'C');
			break;
		case 'printer':
			$headers = array ('Name' => 'N', 'Size' => 'S');
			break;
		default:
			$headers = array ('Name' => 'N', 'Size' => 'S', 'Type' => 'T', 'Modified' => 'D');
	}

	$icons = array ('A' => 'up', 'D' => 'down');
	foreach ($headers as $title => $order) {
		if ($this->order[0] == $order) {
			$ad = ($this->order[1] == 'A') ? 'D' : 'A';
			$icon = $this->Icon($icons[$ad],'',11);
			$style[$title] = 'class="order-by"';
		} else {
			$ad = 'A';
			$icon = '';
			$style[$title] = '';
		}
		$url = $this->GetUrl($this->where, 'O', $order.$ad);
		$header .= "<th>".$this->Link($this->_($title), $url).' '.$icon."</th>";
	}
	$lang = $this->Link(strtoupper($this->lang),$this->GetUrl($this->where,'action','ChangeLanguageInput'));
	$time = date("H:i");
	$logout = $this->Icon('logout', $this->GetUrl($this->where, 'auth', '1'));
	$header .= "<th width=\"100%\">&nbsp;</th><th class=\"language\">{$lang}</th><th class=\"toolbar\"><nobr>{$logout}&nbsp;&nbsp;{$time}</nobr></th></tr>";

	$lines = $this->ViewForm ($this->type, $style, $headers);
	foreach ($this->results as $file => $data) {
		if ($this->cfgHideDotFiles AND $file[0] == '.') continue;
		if ($data['type']=='file' OR $data['type']=='printjob') {
			$size = $this->PrintableBytes($data['size']);
			$pi = pathinfo(strtolower($file));
			if (@$this->mimeTypes[$pi['extension']] <> '') {
				$type = sprintf($this->_("File %s"), strtoupper($pi['extension']));
			} else {
				$type = '';
			}
		} else {
			$size = '';
			$type = $this->_("File Folder");
		}
		$modified = date($this->_("m/d/Y h:i"), @$data['time']);
		$check = $this->CheckBox("selected[]", ($data['type'] <> 'printjob') ? $file : $data['id'], in_array($file, $selected));
		$icon = $this->Icon($data['type']);
		$comment = @$data['comment'];
		if ($data['type'] <> 'printjob') {
			$filelink = $this->Link(htmlentities($file, ENT_COMPAT, $this->cfgDefaultCharset), $this->FromPath($file));
		}
		$lines .= "<tr>".
			"<td class=\"checkbox\">{$check}</td>".
			"<td {$style['Name']}><nobr>{$icon} {$filelink}</nobr></td>".
			(isset($headers['Size']) ? "<td {$style['Size']} align=\"right\"><nobr>{$size}</nobr></td>" : "").
			(isset($headers['Type']) ? "<td {$style['Type']}><nobr>{$type}</nobr></td>" : "").
			(isset($headers['Comments']) ? "<td {$style['Comments']}><nobr>{$comment}</nobr></td>" : "").
			(isset($headers['Modified']) ? "<td {$style['Modified']}><nobr>{$modified}</nobr></td>" : "").
			"<td width=\"100%\" colspan=\"3\">{$renamelink}</td>".
			"</tr>\n";
	}

	$macros['{action}'] = $this->GetUrl($this->where);
	$macros['{ok}'] = $this->_("Ok");
	$macros['{header}'] = $header;
	$macros['{lines}'] = $lines;

	print $this->Page($this->name, $this->Template("style/view.thtml", $macros));
}

function ViewForm ($type, $style, $headers)
{
	$icon = $this->Icon('dotdot');
	$amenu = array();
	$html = $widget = '';

	$where = $this->_DirectoryName($this->where);
	$n = 0;
	while ($where <> '') {
		$amenu['UpAction'.$n] = $where;
		$where = $this->_DirectoryName($where);
		$n++;
	}
	if ($this->where <> '') {
		$amenu['UpAction'.$n] = $this->_("Windows Network");
	}
	if (isset($amenu['UpAction0'])) {
		$amenu['UpAction0'] .= ' ('.$this->_("Up").')';
	}

	switch ($type) {
		case 'network':
		case 'server':  break;
		case 'printer':
			switch (@$_REQUEST['action']) {
				case 'PrintFileInput':
					$amenu = array();
					$icon = $this->Icon('file');
					$widget = $this->Input("action", "PrintFileAction", "hidden").
						$this->Input("file","", "file").
						$this->Input('ok', $this->_("Ok"), 'submit').
						' (max. '.ini_get('post_max_size').')';
					break;
				default:
					if (count($amenu) > 0) $amenu['--'] = '--';
					$amenu['PrintFileInput'] = $this->_("Print a file");
					$amenu['CancelSelectedAction'] = $this->_("Cancel selected jobs");
			}
			break;
		case 'share':
			switch (@$_REQUEST['action']) {
				case 'NewFolderInput':
					$amenu = array();
					$icon = $this->Icon('folder');
					$widget = $this->Input("action", "NewFolderAction", "hidden").
						$this->Input("folder","").
						$this->Input('ok', $this->_("Ok"), 'submit');
					break;
				case 'NewFileInput':
					$amenu = array();
					$icon = $this->Icon('file');
					$widget = $this->Input("action", "NewFileAction", "hidden").
						$this->Input("file","", "file").
						$this->Input('ok', $this->_("Ok"), 'submit').
						' (max. '.ini_get('post_max_size').')';
					break;
				case 'RenameSelectedInput':
					if (is_array(@$_POST['selected'])) {
						$amenu = array();
						$icon = $this->Icon('rename');
						$widget = '';
						$i = 0;
						foreach ($_POST['selected'] as $file) {
							$widgets[] .= $icon.' '.$this->Input("oldname[{$i}]", $file, "hidden").
								$this->Input("renameto[{$i}]",$file);
							$i++;
						}
						$widget .= join("<br />", $widgets).$this->Input('ok', $this->_("Ok"), 'submit');
						$widget .= $this->Input("action", "RenameSelectedAction", "hidden");
						$icon = '';
						break;
					}
				default:
					if (count($amenu) > 0) $amenu['--'] = '--';
					$amenu['NewFolderInput'] = $this->_("New folder");
					$amenu['NewFileInput'] = $this->_("New file (upload)");
					$amenu['DeleteSelectedAction'] = $this->_("Delete selected items");
					$amenu['RenameSelectedInput'] = $this->_("Rename selected items");
					if ($this->cfgArchiver) $amenu['DownloadFolderAction'] = $this->_("Download this folder")." (".$this->cfgArchiver.")";
			}
			break;
		case 'workgroup':
			if (@$_REQUEST['action'] == 'SendMessageInput') {
				$amenu = array();
				$icon = $this->Icon('file');
				$widget = $this->Input("action", "SendMessageAction", "hidden").
					$this->Input("message","").
					$this->Input('ok', $this->_("Ok"), 'submit');
			} else {
				if (count($amenu) > 0) $amenu['--'] = '--';
				$amenu['SendMessageInput'] = $this->_("Send a popup message");
			}
			break;
		default: print $type;
	}
	if (@$_REQUEST['action'] == 'ChangeLanguageInput') {
		$amenu = array();
		foreach (array_keys($this->strings) as $lang) $amenu[$lang] = strtoupper($lang);
		$widget = $this->Select("lang", "", $amenu) . $this->Input('ok', $this->_("Ok"), 'submit');
		$_GET['action'] = '';
	} elseif (count($amenu)) {
		$amenu['---'] = '--';
		$amenu['LogoutAction'] = $this->_("Logout");
		$widget = $this->Select("action", "", $amenu) . $this->Input('ok', $this->_("Ok"), 'submit');
	}
	if ($widget <> '') {
		$html = "<tr>".
			"<td>&nbsp;</td>".
			"<td {$style['Name']}><nobr>{$icon} {$widget}</nobr></td>".
			(isset($headers['Size']) ? "<td {$style['Size']} align=\"right\">&nbsp;</td>" : "").
			(isset($headers['Type']) ? "<td {$style['Type']}>&nbsp;</td>" : "").
			(isset($headers['Comments']) ? "<td {$style['Comments']}>&nbsp;</td>" : "").
			(isset($headers['Modified']) ? "<td {$style['Modified']}>&nbsp;</td>" : "").
			"<td width=\"100%\" colspan=\"3\">&nbsp;</td>".
			"</tr>\n";
	}
	return $html;
}

function UpAction ($level=0)
{
	$times = $level+1;
	for ($i=0, $where = $this->where; $i < $times; $i++) {
		$where = $this->_DirectoryName($where);
	}
	header('Location: '.$this->GetUrl($where));
	exit;
}

function LogoutAction ()
{
	header('Location: '.$this->GetUrl($this->where, 'auth', '1'));
	exit;
}

function NewFolderAction ()
{
	if (trim($_POST['folder']) <> '') {
		$this->parent = $this->path;
		$this->name = $_POST['folder'];
		$this->MakeDirectory();
		$this->Debug('new folder "'.$this->name.'"');
	}
	if ($this->status <> '') $this->ErrorMessage($this->status);
	header('Location: '.$this->FromPath('.'));
	exit;
}

function NewFileAction ()
{
	if ($_FILES['file']['tmp_name'] <> '') {
		$this->parent = $this->path;
		$this->name = $_FILES['file']['name'];
		if ($this->cfgAntivirus) {
			$fn = $this->cfgAntivirus;
			$infected = $this->$fn($_FILES['file']['tmp_name']);
		}
		if (! $infected) {
			$this->UploadFile($_FILES['file']['tmp_name']);
			$this->Debug('new file "'.$this->name);
		}
	}
	if ($this->status <> '') $this->ErrorMessage($this->status);
	header('Location: '.$this->FromPath('.'));
	exit;
}

function DeleteSelectedAction ()
{
	$status = '';
	if (is_array(@$_POST['selected'])) {
		$base = $this->fullPath;
		foreach ($_POST['selected'] as $file) {
			$this->Go($base.'/'.$file);
			$this->Remove();
			$this->Debug('delete '.$base.'/'.$file);
			if ($this->status <> '') $status = $this->status;
		}
		$this->Go($base);
		if ($status <> '') $this->ErrorMessage($status);
	}
	header('Location: '.$this->FromPath('.'));
	exit;
}


function RenameSelectedAction ()
{
	$status = '';
	$this->parent = $this->path;
	if (is_array(@$_POST['renameto'])) {
		$base = $this->fullPath;
		for ($i=0; $i < count($_POST['renameto']); $i++) {
			if ($_POST['oldname'][$i] <> $_POST['renameto'][$i]) {
				$this->Debug('rename "'.$_POST['oldname'][$i].'" to "'.$_POST['renameto'][$i].'"');
				$this->RenameFile($_POST['oldname'][$i], $_POST['renameto'][$i]);
				if ($this->status <> '') $status = $this->status;
			}
		}
	}
	$_GET['action'] = '';
	if ($status <> '') $this->ErrorMessage($status);
	header('Location: '.$this->FromPath('.'));
	exit;
}


# print KB
function PrintableBytes ($bytes)
{
	if ($bytes < 1024) return "0 KB";
	elseif ($bytes < 10*1024*1024) return number_format($bytes / 1024,0) . " KB";
	elseif ($bytes < 1024*1024*1024) return number_format($bytes / (1024 * 1024),0) . " MB";
	else return number_format($bytes / (1024*1024*1024),0) . " GB";
}

function PrintablePath ()
{
	switch ($this->type) {
		case 'network':		return $this->_("Windows Network");
		case 'workgroup':	return $this->workgroup;
		case 'server':		return '\\\\'.$this->server;
		case 'share':
			$pp = '\\\\'.$this->server.'\\'.$this->share;
			if ($this->where <> '') {
				$pp .= '\\'.str_replace('/','\\',$this->where);
			}
			return $pp;
	}
}


# builds a new path from current and a relative path
function FromPath ($relative='')
{
	switch ($relative) {
		case '.':
		case '':		$path = $this->where; break;
		case '..':	$path = $this->_DirectoryName($this->where); break;
		default:		$path = preg_replace('/^\//', '', $this->where.'/'.$relative);
	}
	return $this->GetUrl($path);
}

}



###################################################################
# MAIN SECTION - come on !
###################################################################

/*
if (! isset($SMBWEBCLIENT_CLASS)) {
	$swc = new smbwebclient;
	$swc->Run();
}
*/

?>