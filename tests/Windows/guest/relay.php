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
 *   {"runner": "8.5" | "8.4-x86" | "8.4-dx" | "iis" | "iis-x86", "request": "<base64 of probe.php's JSON request>", "noProcOpen": false}
 *
 * With "noProcOpen", the probe runs with proc_open() disabled (-d disable_functions=proc_open), so that the library
 * falls back to exec(); not for "iis", whose php.ini the relay cannot change per request.
 *
 * {"hidden": {"wmic": true, "fsutil": false}} hides (renames) or restores Windows' own WMIC.exe and fsutil.exe, both
 * the System32 and the SysWOW64 copies, for the tests of a Windows without them (provision.ps1 made them renamable; the
 * VM's snapshot restores them anyway).
 * It answers {"status": 0, "stdout": "", "stderr": ""}, or a status of 1 and the error.
 *
 * {"plant": {"C:\\PHP89\\php.exe": "<base64 of its bytes>", …}} writes files for a test, wherever a search strategy
 * looks, creating their directories; {"unplant": true} removes them all, and those directories. It never overwrites a
 * file it did not write. The same answer as above.
 *
 * A runner <X.Y>-dx is <X.Y> with cmd.exe's delayed expansion turned on in the registry (HKLM and HKCU) while the probe
 * runs.
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

	if (is_array($message) && (isset($message['plant']) || isset($message['unplant'])))
	{
		fwrite($stdout, json_encode(plantFiles((array) ($message['plant'] ?? []), !empty($message['unplant']))) . "\n");
		fflush($stdout);

		continue;
	}

	$runner  = is_array($message) ? (string) ($message['runner'] ?? '') : '';
	$request = is_array($message) ? (string) ($message['request'] ?? '') : '';

	if ($runner === 'iis' || $runner === 'iis-x86')
	{
		$context  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 300]]);
		$url      = 'http://localhost/' . ($runner === 'iis' ? '' : 'pf-x86/') . 'pf-probe.php?r=' . rawurlencode($request);
		$body     = @file_get_contents($url, false, $context);
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
	elseif (
		preg_match('/^(\d+\.\d+(?:-x86)?)(-dx)?$/', $runner, $runnerParts)
		&& is_file('C:\\pf\\run\\' . $runnerParts[1] . '\\php.exe')
	)
	{
		// A -dx runner: the same PHP, with cmd.exe's delayed expansion turned on in the registry for the request.
		$delayedExpansion = !empty($runnerParts[2]);
		$delayedExpansion && setDelayedExpansion(true);

		$errFile = tempnam(sys_get_temp_dir(), 'pfe');
		$process = proc_open(
			array_merge(
				['C:\\pf\\run\\' . $runnerParts[1] . '\\php.exe', '-n'],
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

		$delayedExpansion && setDelayedExpansion(false);
	}
	else
	{
		$answer = ['status' => -1, 'stdout' => '', 'stderr' => 'relay.php: unknown runner ' . $runner];
	}

	fwrite($stdout, json_encode($answer) . "\n");
	fflush($stdout);
}

/**
 * Turn cmd.exe's delayed expansion on (DelayedExpansion = 1) or off (no value, Windows' default) in the registry, for
 * the machine and for the user the relay and the probe run as. The VM's snapshot restores the registry anyway.
 */
function setDelayedExpansion(bool $on): void
{
	foreach (['HKLM', 'HKCU'] as $hive)
	{
		$key = $hive . '\\Software\\Microsoft\\Command Processor';
		$command = $on
			? 'C:\\Windows\\System32\\reg.exe add "' . $key . '" /v DelayedExpansion /t REG_DWORD /d 1 /f'
			: 'C:\\Windows\\System32\\reg.exe delete "' . $key . '" /v DelayedExpansion /f';

		exec($command . ' >NUL 2>&1');
	}
}

/**
 * Write files for a test, anywhere (as Administrator), creating their directories; or remove every file this relay
 * wrote, and the directories it created for them. A file which exists, and which the relay did not write, is never
 * overwritten: the fixtures stay as provision.ps1 made them.
 *
 * @param   string[]  $files    Path => base64 of its bytes
 * @param   bool      $unplant  Remove everything planted so far instead
 */
function plantFiles(array $files, bool $unplant): array
{
	static $planted = [], $createdDirs = [];

	if ($unplant)
	{
		foreach (array_keys($planted) as $path)
		{
			@unlink($path);
		}

		// Deepest first.
		usort($createdDirs, function ($a, $b) {
			return strlen($b) - strlen($a);
		});

		foreach ($createdDirs as $dir)
		{
			@rmdir($dir);
		}

		clearstatcache();
		$left = array_values(array_filter(array_merge(array_keys($planted), $createdDirs), 'file_exists'));
		[$planted, $createdDirs] = [[], []];

		return $left === []
			? ['status' => 0, 'stdout' => '', 'stderr' => '']
			: ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: could not remove ' . implode(', ', $left)];
	}

	foreach ($files as $path => $base64)
	{
		clearstatcache();

		if (file_exists($path) && !isset($planted[$path]))
		{
			return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: will not overwrite ' . $path];
		}

		$missing = [];

		for ($dir = dirname($path); !is_dir($dir) && dirname($dir) !== $dir; $dir = dirname($dir))
		{
			$missing[] = $dir;
		}

		foreach (array_reverse($missing) as $dir)
		{
			if (!@mkdir($dir))
			{
				return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: could not create ' . $dir];
			}

			$createdDirs[] = $dir;
		}

		if (@file_put_contents($path, base64_decode((string) $base64)) === false)
		{
			return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: could not write ' . $path];
		}

		$planted[$path] = true;
	}

	return ['status' => 0, 'stdout' => '', 'stderr' => ''];
}

/**
 * Hide (rename to <name>.pf-hidden) or restore Windows' own wmic and fsutil: the 64-bit copies in System32 and the
 * 32-bit ones in SysWOW64, which a 32-bit (x86) runner sees at System32 instead. The relay is 64-bit: it sees both
 * where they are. A copy which is not there (the VMs without WMIC, tests/Windows/vm) is neither hidden nor restored.
 *
 * @param   bool[]  $hidden  wmic and/or fsutil => whether it is to be hidden
 */
function hideTools(array $hidden): array
{
	$tools = ['wmic' => 'wbem\\WMIC.exe', 'fsutil' => 'fsutil.exe'];

	foreach ($hidden as $tool => $hide)
	{
		if (!isset($tools[$tool]))
		{
			return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: unknown tool ' . $tool];
		}

		foreach (['System32', 'SysWOW64'] as $directory)
		{
			$path = 'C:\\Windows\\' . $directory . '\\' . $tools[$tool];

			[$from, $to] = $hide ? [$path, $path . '.pf-hidden'] : [$path . '.pf-hidden', $path];

			clearstatcache();

			// Only what is there: a Windows without WMIC (tests/Windows/vm) has no copy to hide, nor one to restore.
			if (is_file($from) && !@rename($from, $to))
			{
				return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: could not rename ' . $from . ': ' . (error_get_last()['message'] ?? '')];
			}

			clearstatcache();

			if (is_file($from))
			{
				return ['status' => 1, 'stdout' => '', 'stderr' => 'relay.php: ' . $from . ' is still there'];
			}
		}
	}

	return ['status' => 0, 'stdout' => '', 'stderr' => ''];
}
