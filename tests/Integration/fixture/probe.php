<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

/**
 * Runs INSIDE the `php` container, under the runner PHP version (7.4 … 8.6), so it must stay PHP 7.2
 * compatible. The host-side PHPUnit suite calls it through `docker compose exec`:
 *
 *   php /app/fixture/probe.php <base64 of a JSON request>
 *
 * Request:  {"call": "getBestPath" | "getBestPathMeta" | "getPossiblePaths",
 *            "version": "8.3" | null, "config": {"useWhich": true, …}, "path": "/usr/bin:…" | null,
 *            "os": "Darwin" | null}
 *
 * "os" makes PHPFinder behave as if it ran on that OS family, through its private $osFamily (as the unit
 * tests do), e.g. to search the macOS-only locations, which the container has fixtures in.
 * Response: {"result": …, "diagnostics": ["…"], "runner": "8.5", "runnerVersion": "8.5.1",
 *            "phpBinary": "/usr/local/bin/php"}
 *
 * Every PHP diagnostic the call raises (warnings, notices, deprecations; not those silenced with @) is
 * collected and returned, so the suite can assert that the library runs cleanly on each PHP version.
 */

require '/app/src/Configuration.php';
require '/app/src/PHPFinder.php';

use Akeeba\PHPFinder\Configuration;
use Akeeba\PHPFinder\PHPFinder;

$request = json_decode(base64_decode($argv[1] ?? ''), true);

if (!is_array($request) || !isset($request['call']))
{
	fwrite(STDERR, "probe.php: invalid request\n");

	exit(2);
}

// PHPFinder reads the PATH with getenv() and runs `which` & co. through exec(), which inherits putenv().
if (!empty($request['path']))
{
	putenv('PATH=' . $request['path']);
}

$config = new Configuration();

foreach ($request['config'] ?? [] as $key => $value)
{
	$config->{'set' . ucfirst($key)}($value);
}

$diagnostics = [];

error_reporting(E_ALL);
set_error_handler(
	function ($errno, $errstr, $errfile, $errline) use (&$diagnostics) {
		if (!(error_reporting() & $errno))
		{
			return true;
		}

		$diagnostics[] = sprintf('%s in %s:%d', $errstr, $errfile, $errline);

		return true;
	}
);

$finder  = new PHPFinder($config);
$version = $request['version'] ?? null;

if (!empty($request['os']))
{
	\Closure::bind(
		function ($osFamily) {
			$this->osFamily = $osFamily;
		},
		$finder,
		PHPFinder::class
	)($request['os']);
}

switch ($request['call'])
{
	case 'getBestPath':
		$result = $finder->getBestPath($version);
		break;

	case 'getBestPathMeta':
		$result = $finder->getBestPathMeta($version);
		$result = $result === null ? null : (array) $result;
		break;

	case 'getPossiblePaths':
		$result = array_values($finder->getPossiblePaths($version));
		break;

	default:
		fwrite(STDERR, "probe.php: unknown call\n");

		exit(2);
}

restore_error_handler();

echo json_encode(
	[
		'result'        => $result,
		'diagnostics'   => $diagnostics,
		'runner'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
		'runnerVersion' => PHP_VERSION,
		'phpBinary'     => PHP_BINARY,
	]
);
