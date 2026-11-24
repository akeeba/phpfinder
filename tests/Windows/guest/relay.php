<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

/**
 * Runs INSIDE the Windows guest, for the whole of one PHPUnit run, under the 8.4 runner:
 *
 *   iisvm.sh ssh 'C:\pf\run\8.4\php.exe -n C:\pf\relay.php'
 *
 * The host-side suite (AbstractWindowsTestCase) keeps that SSH session open and writes one JSON request per line:
 *
 *   {"runner": "8.5" | "iis", "request": "<base64 of probe.php's JSON request>", "noProcOpen": false}
 *
 * With "noProcOpen", the probe runs with proc_open() disabled (-d disable_functions=proc_open), so that the library
 * falls back to exec(); not for "iis", whose php.ini the relay cannot change per request.
 *
 * {"hidden": {"wmic": true, "fsutil": false}} hides (renames) or restores Windows' own WMIC.exe and fsutil.exe, for
 * the tests of a Windows without them (provision.ps1 made them renamable; the VM's snapshot restores them anyway).
 * It answers {"status": 0, "stdout": "", "stderr": ""}, or a status of 1 and the error.
 *
 * For a PHP version, the relay runs C:\pf\run\<X.Y>\php.exe -n C:\pf\probe.php <request> in a new process, without
 * a shell (so nothing is re-quoted on the way), in the empty directory C:\pf\work (`where` looks in the current
 * directory first). For "iis", it requests http://localhost/pf-probe.php?r=<request>: IIS runs the probe.
 *
 * It answers each request with one JSON line: {"status": <exit code or HTTP status>, "stdout": "…", "stderr": "…"}.
 * The probe's STDERR is kept apart, so the suite can assert that the library printed nothing there either: a CLI
 * application's user would see it in their console.
 *
 * One SSH session per PHPUnit run, instead of one per probe, because each SSH round trip to the guest (host → KVM
 * host → guest, PowerShell start-up) takes about two seconds.
 */

$stdin  = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');

while (($line = fgets($stdin)) !== false)
{
	$line = trim($line);

	if ($line === '')
	{
		continue;
	}

	$message = json_decode($line, true);

	if (is_array($message) && isset($message['hidden']))
	{
		fwrite($stdout, json_encode(hideTools((array) $message['hidden'])) . "\n");
		fflush($stdout);

		continue;
	}

	$runner  = is_array($message) ? (string) ($message['runner'] ?? '') : '';
	$request = is_array($message) ? (string) ($message['request'] ?? '') : '';

	if ($runner === 'iis')
	{
		$context  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 300]]);
		$body     = @file_get_contents('http://localhost/pf-probe.php?r=' . rawurlencode($request), false, $context);
		$status   = 0;

		foreach ($http_response_header ?? [] as $header)
		{
			if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m))
			{
				$status = (int) $m[1];
			}
		}

		$answer = ['status' => $status === 200 ? 0 : $status, 'stdout' => (string) $body, 'stderr' => ''];
	}
	elseif (preg_match('/^\d+\.\d+$/', $runner) && is_file('C:\\pf\\run\\' . $runner . '\\php.exe'))
	{
		$errFile = tempnam(sys_get_temp_dir(), 'pfe');
		$process = proc_open(
			array_merge(
				['C:\\pf\\run\\' . $runner . '\\php.exe', '-n'],
				empty($message['noProcOpen']) ? [] : ['-d', 'disable_functions=proc_open'],
				['C:\\pf\\probe.php', $request]
			),
			[0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
			$pipes,
			'C:\\pf\\work'
		);

		$out    = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$status = proc_close($process);
		$answer = ['status' => $status, 'stdout' => $out, 'stderr' => (string) file_get_contents($errFile)];
		@unlink($errFile);
	}
	else
	{
		$answer = ['status' => -1, 'stdout' => '', 'stderr' => 'relay.php: unknown runner ' . $runner];
	}

	fwrite($stdout, json_encode($answer) . "\n");
	fflush($stdout);
}

/**
 * Hide (rename to <name>.pf-hidden) or restore Windows' own wmic and fsutil.
 *
 * @param   bool[]  $hidden  wmic and/or fsutil => whether it is to be hidden
 */
function hideTools(array $hidden): array
{
	$tools = ['wmic' => 'C:\\Windows\\System32\\wbem\\WMIC.exe', 'fsutil' => 'C:\\Windows\\System32\\fsutil.exe'];

	foreach ($hidden as $tool => $hide)
	{
		if (!isset($tools[$tool]))
		{
			return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: unknown tool ' . $tool];
		}

		[$from, $to] = $hide ? [$tools[$tool], $tools[$tool] . '.pf-hidden'] : [$tools[$tool] . '.pf-hidden', $tools[$tool]];

		clearstatcache();

		if (is_file($from) && !@rename($from, $to))
		{
			return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: could not rename ' . $from . ': ' . (error_get_last()['message'] ?? '')];
		}

		clearstatcache();

		if (is_file($tools[$tool]) === (bool) $hide)
		{
			return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: ' . $tools[$tool] . ($hide ? ' is still there' : ' is missing')];
		}
	}

	return ['status' => 0, 'stdout' => '', 'stderr' => ''];
}
