<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

/**
 * provision.ps1 installs this as C:\inetpub\wwwroot\pf-probe.php, so that IIS runs probe.php the way a web
 * application runs the library: FastCGI (php-cgi.exe, with C:\php\php.ini), as the site's identity, not the
 * Administrator of the SSH session. relay.php requests http://localhost/pf-probe.php?r=<base64 request>; for the
 * iis-x86 runner, http://localhost/pf-x86/pf-probe.php, an application whose PHP is the 32-bit 8.4.
 */

if (isset($_GET['ping']))
{
	echo 'pf-probe-ok', isset($_GET['bits']) ? (' ' . (PHP_INT_SIZE * 8)) : '';

	return;
}

$argv = [__FILE__, isset($_GET['r']) ? (string) $_GET['r'] : ''];

require 'C:\\pf\\probe.php';
