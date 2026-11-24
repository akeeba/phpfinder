<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

/**
 * Runs INSIDE the Windows guest, under one of the runner PHP versions (7.2 … 8.6), so it must stay PHP 7.2
 * compatible. relay.php runs it as
 *
 *   C:\pf\run\<X.Y>\php.exe -n C:\pf\probe.php <base64 of a JSON request>
 *
 * and IIS runs it through iis-probe.php, with the request in the query string.
 *
 * Request:  {"call": "getBestPath" | "getBestPathMeta" | "getPossiblePaths" | "drives" | "analyze" | "facts" | "tools" | "wmic",
 *            "version": "8.3" | null, "config": {"useWhere": true, …}, "path": "C:\\pf\\path-a;…" | null,
 *            "env": {"plesk_dir": "…"}, "arg": "C:\\…\\php.exe" (analyze only), "cwd": "C:\\…" | null,
 *            "windowsDirectory": "C:\\pf\\fakewin\\…" | null}
 * Response: {"result": …, "diagnostics": ["…"], "runner": "8.5", "runnerVersion": "8.5.13", "sapi": "cli",
 *            "phpBinary": "C:\\pf\\run\\8.5\\php.exe", "bits": 64, "ran": ["where", …], "procOpen": true}
 *
 * "windowsDirectory" sets PHPFinder's private $windowsDirectory, where it runs Windows' own tools from (when the
 * library has it: not an older one given to run.sh with --src). "ran" lists the stand-in tools (provision.ps1's fake
 * tool) which ran in the working directory during the call: the <name>.ran files there, removed before the call.
 *
 * "exec" runs the command in "arg" with PHP's exec() and returns what it printed, its exit code and "ran" (above).
 *
 * "drives" and "analyze" call PHPFinder's private getWindowsDrives() and analyzePHPVersion() through a bound
 * closure, as the unit tests do. "facts" returns what the Windows tools print, byte for byte, as PHP's exec() sees it.
 * "tools" returns which of Windows' tools exist at the paths PHPFinder runs them from, as this runner sees them.
 * "wmic" returns which copies of WMIC.exe exist (System32's, SysWOW64's) and, from a 64-bit CLI runner, the state of
 * Microsoft's WMIC Feature on Demand.
 *
 * Every PHP diagnostic the call raises (warnings, notices, deprecations; not those silenced with @) is collected and
 * returned, so the suite can assert that the library runs cleanly on each PHP version.
 */

require __DIR__ . '/src/Configuration.php';
require __DIR__ . '/src/PHPFinder.php';

use Akeeba\PHPFinder\Configuration;
use Akeeba\PHPFinder\PHPFinder;

$request = json_decode(base64_decode(isset($argv[1]) ? $argv[1] : ''), true);

if (!is_array($request) || !isset($request['call']))
{
	fwrite(STDERR, "probe.php: invalid request\n");

	exit(2);
}

// PHPFinder reads the PATH with getenv() and runs `where` & co. through exec(), which inherits putenv().
if (!empty($request['path']))
{
	putenv('PATH=' . $request['path']);
}

// The working directory, which `where` and cmd.exe search before the PATH. relay.php starts in an empty one.
if (!empty($request['cwd']))
{
	chdir($request['cwd']);
}

foreach (isset($request['env']) ? $request['env'] : [] as $name => $value)
{
	putenv($name . '=' . $value);
}

$config = new Configuration();

foreach (isset($request['config']) ? $request['config'] : [] as $key => $value)
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

if (!empty($request['windowsDirectory']) && property_exists($finder, 'windowsDirectory'))
{
	\Closure::bind(
		function ($directory) {
			$this->windowsDirectory = $directory;
		},
		$finder,
		PHPFinder::class
	)($request['windowsDirectory']);
}

// The stand-in tools leave a <name>.ran file next to themselves when they run.
$markerDir = getcwd();

foreach (glob($markerDir . '\\*.ran') ?: [] as $marker)
{
	@unlink($marker);
}
$version = isset($request['version']) ? $request['version'] : null;
$private = function ($method, array $args = []) use ($finder) {
	return \Closure::bind(
		function () use ($method, $args) {
			return call_user_func_array([$this, $method], $args);
		},
		$finder,
		PHPFinder::class
	)();
};

/**
 * Run a command the way PHPFinder does, and return everything about its output.
 */
$run = function ($command) {
	$output = [];
	$status = null;
	$last   = @exec($command, $output, $status);

	return [
		'command' => $command,
		'return'  => $last === false ? false : $last,
		'status'  => $status,
		'lines'   => $output,
		'hex'     => array_map('bin2hex', $output),
	];
};

/**
 * The raw bytes a command writes to STDOUT, without exec()'s line splitting and trimming.
 */
$raw = function ($command) {
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

	if (!is_resource($process))
	{
		return null;
	}

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	return ['stdout' => bin2hex($stdout), 'stderr' => bin2hex($stderr)];
};

/**
 * Run a command line with proc_open() and bypass_shell (CreateProcessW(), no cmd.exe): its exit code, and the first
 * line of its STDOUT and of its STDERR.
 */
$bypass = function ($command) {
	$process = proc_open($command, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);

	if (!is_resource($process))
	{
		return null;
	}

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	$first = function ($text) {
		return rtrim(explode("\n", str_replace("\r", '', $text))[0]);
	};

	return ['status' => proc_close($process), 'stdout' => $first($stdout), 'stderr' => $first($stderr)];
};

/**
 * Which of Windows' tools PHPFinder runs exist in a directory of the Windows directory, as this runner sees it: a
 * 32-bit runner sees SysWOW64 at System32 (WoW64 file system redirection).
 */
$tools = function ($directory) {
	return array_map(
		function ($tool) use ($directory) {
			return is_file(getenv('SystemRoot') . '\\' . $directory . '\\' . $tool);
		},
		[
			'where'      => 'where.exe',
			'wmic'       => 'wbem\\WMIC.exe',
			'fsutil'     => 'fsutil.exe',
			'powershell' => 'WindowsPowerShell\\v1.0\\powershell.exe',
		]
	);
};

switch ($request['call'])
{
	case 'tools':
		$result = $tools('System32');
		break;

	case 'wmic':
		// Both copies of WMIC.exe, wherever this runner is: a 32-bit one sees the real System32 at Sysnative. And, from a
		// 64-bit CLI runner (elevated, as the SSH session is; not IIS), Microsoft's WMIC Feature on Demand, as
		// Get-WindowsCapability lists it: "<name>=<state>", or '' when it lists none (Windows Server 2022).
		$windows = getenv('SystemRoot');
		$result  = [
			'System32' => is_file($windows . '\\' . (PHP_INT_SIZE === 4 ? 'Sysnative' : 'System32') . '\\wbem\\WMIC.exe'),
			'SysWOW64' => is_file($windows . '\\SysWOW64\\wbem\\WMIC.exe'),
		];

		if (PHP_INT_SIZE === 8 && PHP_SAPI === 'cli')
		{
			$output = [];
			$status = null;
			exec(
				'"' . $windows . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe" -NoProfile -NonInteractive -Command '
				. '"Get-WindowsCapability -Online | Where-Object Name -like \'WMIC*\' | ForEach-Object { $_.Name + \'=\' + $_.State }" 2>&1',
				$output,
				$status
			);
			$result['capability'] = $status === 0 ? implode(';', array_filter(array_map('trim', $output), 'strlen')) : ('ERROR ' . implode(' ', $output));
		}
		break;

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

	case 'drives':
		$result = array_values($private('getWindowsDrives'));
		break;

	case 'analyze':
		$result = (array) $private('analyzePHPVersion', [$request['arg']]);
		break;

	case 'exec':
		$result = $run($request['arg']);
		break;

	case 'facts':
		$result = [
			'systemDrive'  => getenv('SYSTEMDRIVE'),
			'systemRoot'   => getenv('SystemRoot'),
			'tools'        => $tools('System32'),
			'toolsWow64'   => $tools('SysWOW64'),
			'bits'         => PHP_INT_SIZE * 8,
			'architew6432' => getenv('PROCESSOR_ARCHITEW6432'),
			'sysnative'    => is_dir(getenv('SystemRoot') . '\\Sysnative'),
			'path'         => getenv('PATH'),
			'wmicPath'     => trim((string) @exec('where wmic 2>NUL')),
			'wmic'         => $run('wmic logicaldisk get name'),
			'wmicRaw'      => $raw('wmic logicaldisk get name'),
			'wmicNone'     => $raw('wmic logicaldisk where "Name=\'Q:\'" get name'),
			'fsutil'       => $run('fsutil fsinfo drives'),
			'fsutilRaw'    => $raw('fsutil fsinfo drives'),
			'where'        => $run('where php'),
			'whereRaw'     => $raw('where php'),
			'whereNone'    => $raw('where pf-no-such-binary'),
			'whereExt'     => $run('where "C:\\pf\\path-ext:php"'),
			'isExecutable' => array_map(
				function ($file) {
					return is_executable('C:\\pf\\path-ext\\' . $file);
				},
				['php.exe' => 'php.exe', 'php.bat' => 'php.bat', 'php.cmd' => 'php.cmd', 'php.com' => 'php.com', 'php' => 'php']
			),
			'pleskDir'     => getenv('PLESK_DIR'),
			// is_executable() is GetBinaryTypeW(): TRUE for more than programs.
			'isExecutableOther' => array_map(
				function ($file) {
					return is_executable($file);
				},
				[
					'text php.exe'  => 'C:\\pf\\fake\\broken\\php.exe',
					'empty php.exe' => 'C:\\pf\\fake\\zero\\php.exe',
					'PE php.bat'    => 'C:\\pf\\pe-names\\php.bat',
					'PE php.cmd'    => 'C:\\pf\\pe-names\\php.cmd',
				]
			),
			// How a program named like a script runs (PHP 8.1 copied as php.bat, php.cmd and php.com): started by Windows
			// itself (proc_open() with bypass_shell: CreateProcessW()), and by cmd.exe (System32\cmd.exe /d /s /c "…").
			'peNames'      => array_map(
				function ($name) use ($bypass) {
					$file = 'C:\\pf\\pe-names\\' . $name;

					return [
						'direct' => $bypass('"' . $file . '" -n -v'),
						'cmd'    => $bypass('"C:\\Windows\\System32\\cmd.exe" /d /s /c ""' . $file . '" -n -v"'),
					];
				},
				['php.bat' => 'php.bat', 'php.cmd' => 'php.cmd', 'php.com' => 'php.com']
			),
		];
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
		'sapi'          => PHP_SAPI,
		'phpBinary'     => PHP_BINARY,
		'bits'          => PHP_INT_SIZE * 8,
		'ran'           => array_map(
			function ($marker) {
				return strtolower(basename($marker, '.ran'));
			},
			glob($markerDir . '\\*.ran') ?: []
		),
		'procOpen'      => function_exists('proc_open'),
	]
);
