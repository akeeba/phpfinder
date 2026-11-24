<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Windows;

use Akeeba\PHPFinder\Tests\Support\KnownBug;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the Windows tests.
 *
 * PHPUnit runs on the host. Each test asks guest/probe.php, inside the Windows VM, to run PHPFinder with a given
 * configuration, version and PATH, under one runner: a real PHP CLI (C:\pf\run\<X.Y>\php.exe), or IIS. Requests go
 * through guest/relay.php, over one SSH session kept open for the whole PHPUnit run. Nothing here loads the library
 * into PHPUnit's process.
 *
 * The fixtures (real PHP builds at the paths the search strategies look in) are made by guest/provision.ps1; their
 * paths are the constants below. See README.md.
 */
abstract class AbstractWindowsTestCase extends TestCase
{
	use KnownBug;

	/** The guest's system PATH, as an SSH session and IIS see it (without the per-user WindowsApps entries). */
	protected const PATH_SYSTEM = 'C:\\Windows\\system32;C:\\Windows;C:\\Windows\\System32\\Wbem;'
		. 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\;C:\\Windows\\System32\\OpenSSH\\';

	/** A PATH with nothing in it, not even Windows' own directories. */
	protected const EMPTY_PATH = 'C:\\pf\\fake\\empty';

	// The fixtures, see guest/provision.ps1.
	protected const COMMON_ROOT_84       = 'C:\\PHP\\php.exe';
	protected const COMMON_VERSIONED_83  = 'C:\\PHP83\\php.exe';
	protected const MISLABELLED_82       = 'C:\\PHP82\\php.exe';
	protected const PROGRAM_FILES_82     = 'C:\\Program Files\\PHP\\v8.2\\php.exe';
	protected const PROGRAM_FILES_X86_81 = 'C:\\Program Files (x86)\\PHP8.1\\php.exe';
	protected const SECOND_DRIVE_85      = 'D:\\PHP85\\php.exe';
	protected const WAMP_80              = 'C:\\wamp64\\bin\\php\\php8.0.30\\php.exe';
	protected const WAMP_72              = 'C:\\wamp64\\bin\\php\\php7.2.34\\php.exe';
	protected const MAMP_86              = 'C:\\MAMP\\bin\\php\\php8.6.0\\php.exe';
	protected const XAMPP_72             = 'C:\\xampp\\php\\php.exe';
	protected const PLESK_DIR            = 'C:\\Program Files (x86)\\Plesk\\';
	protected const PLESK_83             = 'C:\\Program Files (x86)\\Plesk\\Additional\\PleskPHP83\\php.exe';
	protected const PLESK_LEGACY_82      = 'C:\\Program Files (x86)\\Plesk\\Additional\\PHP82\\php.exe';
	protected const PATH_A               = 'C:\\pf\\path-a';
	protected const PATH_A_81            = 'C:\\pf\\path-a\\php.exe';
	protected const PATH_B               = 'C:\\pf\\path-b';
	protected const PATH_B_82            = 'C:\\pf\\path-b\\php.exe';
	protected const PATH_B_PHP82_82      = 'C:\\pf\\path-b\\php82.exe';
	protected const PATH_CGI             = 'C:\\pf\\path-cgi';
	protected const PATH_CGI_83          = 'C:\\pf\\path-cgi\\php.exe';
	protected const PATH_EXT             = 'C:\\pf\\path-ext';
	protected const PATH_EXT_82          = 'C:\\pf\\path-ext\\php.exe';
	protected const ODD_DIR              = 'C:\\pf\\odd %PF_UNDEFINED% & (x) !bang ^caret';
	protected const ODD_85               = self::ODD_DIR . '\\php.exe';
	protected const ODD_DEFINED_VAR_DIR  = 'C:\\pf\\odd %OS%';
	protected const ODD_DEFINED_VAR_85   = self::ODD_DEFINED_VAR_DIR . '\\php.exe';
	protected const ODD_NO_PERCENT_85    = 'C:\\pf\\odd & (x) !bang ^caret\\php.exe';
	protected const ODD_EXPANDED_83      = 'C:\\pf\\odd Windows_NT\\php.exe';
	protected const ODD_NO_BANG_85       = 'C:\\pf\\odd & (x) ^caret\\php.exe';
	/** What cmd.exe makes of it with delayed expansion on: ODD_EXPANDED_83. */
	protected const ODD_DELAYED_VAR_85   = 'C:\\pf\\odd !OS!\\php.exe';
	protected const PATH_EXT_COM         = 'C:\\pf\\path-ext\\php.com';
	protected const BROKEN_EXE           = 'C:\\pf\\fake\\broken\\php.exe';
	protected const EMPTY_EXE            = 'C:\\pf\\fake\\zero\\php.exe';
	/** PHP 8.4's php.exe without its DLLs: a program which starts, and stops at once (STATUS_DLL_NOT_FOUND). */
	protected const NO_DLL_EXE           = 'C:\\pf\\fake\\nodll\\php.exe';
	/** PHP 8.4, whose php.ini makes it print a startup warning on STDERR before its banner. */
	protected const STDERR_EXE           = 'C:\\pf\\fake\\stderr\\php.exe';

	// Windows directories with stand-ins for misbehaving tools (see guest/provision.ps1), for $windowsDirectory.
	protected const WIN_WMIC_NONE    = 'C:\\pf\\fakewin\\wmic-none';
	protected const WIN_WMIC_ERROR   = 'C:\\pf\\fakewin\\wmic-error';
	protected const WIN_FSUTIL_ERROR = 'C:\\pf\\fakewin\\fsutil-error';
	protected const WIN_ALL_FAIL     = 'C:\\pf\\fakewin\\all-fail';
	protected const WIN_EMPTY        = 'C:\\pf\\fakewin\\empty';
	/** A `where` which finds nothing; with delayed expansion on, cmd.exe makes WIN_DX_EXPANDED of this path. */
	protected const WIN_DX_DELAYED_VAR = 'C:\\pf\\fakewin\\dx !OS!';
	/** A `where` which finds PATH_A_81, and leaves where.ran in System32. */
	protected const WIN_DX_EXPANDED    = 'C:\\pf\\fakewin\\dx Windows_NT';

	/** A working directory with stand-ins for where, wmic, fsutil and PowerShell, which leave a <name>.ran file. */
	protected const PLANTED_DIR     = 'C:\\pf\\planted';
	/** The same, with a stand-in for cmd.exe. */
	protected const PLANTED_CMD_DIR = 'C:\\pf\\planted-cmd';

	/** Every drive of the guest: the system drive, the VHD provision.ps1 attaches, and the (empty) CD drive. */
	protected const ALL_DRIVES = ['C:', 'D:', 'E:'];

	/** @var resource|null */
	private static $relay;

	/** @var resource[] */
	private static $relayPipes = [];

	/** @var string|null */
	private static $relayErrors;

	/**
	 * The runner of this PHPUnit run: a PHP version (e.g. 8.5), a 32-bit PHP version (e.g. 8.4-x86), a PHP version with
	 * cmd.exe's delayed expansion on (e.g. 8.4-dx) or "iis".
	 */
	protected static function runnerName(): string
	{
		return getenv('PHPFINDER_WINDOWS_RUNNER') ?: '8.4';
	}

	/**
	 * The Windows VM of this run: "iis" (the shared IIS VM, Windows Server 2022, ../buildfiles/vm/iis), "server2025" or
	 * "win11" (this project's VMs without WMIC, vm/pfvm.sh).
	 */
	protected static function vmName(): string
	{
		return getenv('PHPFINDER_WINDOWS_VM') ?: 'iis';
	}

	/**
	 * The shell command which runs a VM command (ssh, ps, …) on this run's VM, on the KVM host $host.
	 */
	public static function vmCommand(string $host): string
	{
		$vm = self::vmName();

		if ($vm === 'iis')
		{
			$script = getenv('PHPFINDER_WINDOWS_IISVM') ?: (__DIR__ . '/../../../buildfiles/vm/iis/iisvm.sh');

			return 'bash ' . escapeshellarg($script) . ' --host=' . escapeshellarg($host);
		}

		$script = getenv('PHPFINDER_WINDOWS_PFVM') ?: (__DIR__ . '/vm/pfvm.sh');

		return 'bash ' . escapeshellarg($script) . ' --os=' . escapeshellarg($vm) . ' --host=' . escapeshellarg($host);
	}

	/**
	 * Whether this run's runner is IIS: with the VM's 64-bit PHP 8.4 ("iis"), or with the 32-bit one ("iis-x86").
	 */
	protected static function isIis(): bool
	{
		return in_array(self::runnerName(), ['iis', 'iis-x86'], true);
	}

	/**
	 * A configuration with every search strategy OFF, so that a test can switch on exactly the one it is about.
	 * Validation stays on.
	 */
	protected static function isolated(array $overrides = []): array
	{
		return array_merge(
			[
				'useConstants'              => false,
				'osSpecific'                => true,
				'softwareSpecific'          => false,
				'extendedBinaryNameSearch'  => true,
				'useFixedPaths'             => false,
				'useWhich'                  => false,
				'useUpdateAlternatives'     => false,
				'useWhereIs'                => false,
				'useWhere'                  => false,
				'usePHPCommonWinDirsSearch' => false,
				'validateVersion'           => true,
				'validateCli'               => true,
			],
			$overrides
		);
	}

	/**
	 * Run PHPFinder in the guest, under this run's runner.
	 *
	 * @param   string       $call     getBestPath, getBestPathMeta, getPossiblePaths, drives, analyze or facts
	 * @param   string|null  $version  The version to look for
	 * @param   array        $config   Configuration overrides; [] is the library's defaults
	 * @param   string|null  $path     The PATH to run with; NULL keeps the guest's
	 * @param   array        $env      More environment variables to set, e.g. ['plesk_dir' => '…']
	 * @param   string|null  $arg      The binary to analyse (analyze only)
	 * @param   string|null  $cwd      The working directory; NULL keeps the relay's empty C:\pf\work (IIS: the site's)
	 * @param   array        $options  windowsDirectory: PHPFinder's private $windowsDirectory; noProcOpen: run with
	 *                                 proc_open() disabled (not under IIS)
	 *
	 * @return  array  The probe's response (result, diagnostics, runner, runnerVersion, sapi, phpBinary, ran,
	 *                 procOpen), plus the probe's STDERR as `stderr`
	 */
	protected function probe(
		string $call, ?string $version, array $config = [], ?string $path = null, array $env = [], ?string $arg = null,
		?string $cwd = null, array $options = []
	): array
	{
		$request = base64_encode(json_encode([
			'call'             => $call,
			'version'          => $version,
			'config'           => (object) $config,
			'path'             => $path,
			'env'              => (object) $env,
			'arg'              => $arg,
			'cwd'              => $cwd,
			'windowsDirectory' => $options['windowsDirectory'] ?? null,
		]));

		$noProcOpen = !empty($options['noProcOpen']);

		if ($noProcOpen && self::isIis())
		{
			$this->markTestSkipped('IIS runs PHP with its own php.ini: proc_open() cannot be disabled per request.');
		}

		$answer = self::relayRequest(
			['runner' => self::runnerName(), 'request' => $request, 'noProcOpen' => $noProcOpen]
		);
		$response = json_decode($answer['stdout'], true);

		$this->assertSame(
			0, $answer['status'],
			sprintf("probe.php failed (%s):\n%s\n%s", self::runnerName(), $answer['stdout'], $answer['stderr'])
		);
		$this->assertIsArray($response, "probe.php did not answer with JSON:\n" . $answer['stdout'] . "\n" . $answer['stderr']);

		$response['stderr'] = $answer['stderr'];

		$this->assertSame(!$noProcOpen, $response['procOpen'], 'proc_open() availability in the probe');

		return $response;
	}

	/**
	 * Hide (rename) or restore Windows' own wmic and/or fsutil in the guest, for real, e.g. ['wmic' => true].
	 * Every test which hides one restores it in tearDown(): the VM is shared by every test and runner of the run.
	 */
	protected static function hideTools(array $hidden): void
	{
		$answer = self::relayRequest(['hidden' => (object) $hidden]);

		if ($answer['status'] !== 0)
		{
			self::fail('Could not hide or restore ' . implode(', ', array_keys($hidden)) . ': ' . $answer['stderr']);
		}
	}

	/**
	 * Write files in the guest for a test, e.g. ['C:\\PHP89\\php.exe' => 'not a program'], creating their directories.
	 * A test which plants files calls unplant() in tearDown(). Never overwrites a fixture.
	 *
	 * @param   string[]  $files  Path => bytes
	 */
	protected static function plant(array $files): void
	{
		$answer = self::relayRequest(['plant' => (object) array_map('base64_encode', $files)]);

		if ($answer['status'] !== 0)
		{
			self::fail('Could not plant ' . implode(', ', array_keys($files)) . ': ' . $answer['stderr']);
		}
	}

	/**
	 * Remove every file plant() wrote, and the directories it made for them.
	 */
	protected static function unplant(): void
	{
		$answer = self::relayRequest(['unplant' => true]);

		if ($answer['status'] !== 0)
		{
			self::fail('Could not remove the planted files: ' . $answer['stderr']);
		}
	}

	/**
	 * Hide or restore tools as hideTools() does, then check that this run's runner sees them so at the paths PHPFinder
	 * runs them from. A 32-bit runner sees SysWOW64's copies at C:\\Windows\\System32, not System32's own.
	 */
	protected function hideToolsAndCheck(array $hidden): void
	{
		self::hideTools($hidden);

		$response = $this->probe('tools', null);

		foreach ($hidden as $tool => $hide)
		{
			$this->assertSame(
				!$hide, $response['result'][$tool],
				sprintf('The %d-bit runner %s %s', $response['bits'], $hide ? 'still sees' : 'does not see', $tool)
			);
		}
	}

	/**
	 * Whether this run's runner has cmd.exe's delayed expansion turned on in the registry (a <X.Y>-dx runner).
	 */
	protected static function isDelayedExpansion(): bool
	{
		return substr(self::runnerName(), -3) === '-dx';
	}

	/**
	 * Whether this run's runner is a 32-bit (x86) PHP, which runs under WoW64.
	 */
	protected static function isX86(): bool
	{
		return substr(self::runnerName(), -4) === '-x86';
	}

	/**
	 * getBestPath(), asserting that the library raised no PHP diagnostics and printed nothing on STDERR.
	 */
	protected function bestPath(?string $version, array $config = [], ?string $path = null, array $env = []): ?string
	{
		$response = $this->probe('getBestPath', $version, $config, $path, $env);

		$this->assertClean($response);

		return $response['result'];
	}

	/**
	 * getPossiblePaths(), asserting that the library raised no PHP diagnostics and printed nothing on STDERR.
	 */
	protected function possiblePaths(?string $version, array $config = [], ?string $path = null, array $env = []): array
	{
		$response = $this->probe('getPossiblePaths', $version, $config, $path, $env);

		$this->assertClean($response);

		return $response['result'];
	}

	/**
	 * No PHP diagnostics, and nothing on STDERR.
	 */
	protected function assertClean(array $response): void
	{
		$this->assertNothingOnStderr($response);
		$this->assertSame(
			[],
			$response['diagnostics'],
			sprintf(
				"PHPFinder raised PHP diagnostics on PHP %s (%s):\n%s",
				$response['runnerVersion'],
				$response['sapi'],
				implode("\n", $response['diagnostics'])
			)
		);
	}

	/**
	 * Nothing on STDERR: the console of a CLI application which uses the library would show whatever the commands it
	 * runs print there. (Under IIS there is no STDERR to look at: always empty.)
	 */
	protected function assertNothingOnStderr(array $response): void
	{
		$this->assertSame(
			'',
			$response['stderr'],
			sprintf("PHPFinder printed this on STDERR on PHP %s:\n%s", $response['runnerVersion'], $response['stderr'])
		);
	}

	/**
	 * Case-insensitive path comparison: Windows file names are case-insensitive, and `where` prints them as the
	 * file system has them.
	 */
	protected function assertSamePaths(array $expected, array $actual, string $message = ''): void
	{
		$this->assertSame(
			array_map('strtolower', array_values($expected)),
			array_map('strtolower', array_values($actual)),
			$message
		);
	}

	protected function assertContainsPath(string $expected, array $paths, string $message = ''): void
	{
		$this->assertContains(strtolower($expected), array_map('strtolower', $paths), $message);
	}

	protected function assertNotContainsPath(string $expected, array $paths, string $message = ''): void
	{
		$this->assertNotContains(strtolower($expected), array_map('strtolower', $paths), $message);
	}

	/**
	 * Send one message to relay.php in the guest and return its answer: status, stdout, stderr.
	 */
	private static function relayRequest(array $message): array
	{
		self::openRelay();

		fwrite(self::$relayPipes[0], json_encode($message) . "\n");
		fflush(self::$relayPipes[0]);

		$read   = [self::$relayPipes[1]];
		$write  = null;
		$except = null;

		if (@stream_select($read, $write, $except, 300) !== 1)
		{
			self::fail('No answer from the guest within 300 seconds. ' . self::relayErrors());
		}

		$line   = fgets(self::$relayPipes[1]);
		$answer = $line === false ? null : json_decode($line, true);

		if (!is_array($answer))
		{
			self::fail("The relay in the guest did not answer with JSON:\n" . $line . "\n" . self::relayErrors());
		}

		return $answer;
	}

	private static function openRelay(): void
	{
		if (self::$relay !== null)
		{
			return;
		}

		$vm = self::vmCommand((string) getenv('PHPFINDER_WINDOWS_HOST'));

		self::$relayErrors = tempnam(sys_get_temp_dir(), 'phpfinder-relay');
		self::$relay       = proc_open(
			$vm . ' ssh ' . escapeshellarg('C:\\pf\\run\\8.4\\php.exe -n C:\\pf\\relay.php'),
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', self::$relayErrors, 'w']],
			self::$relayPipes
		);

		if (!is_resource(self::$relay))
		{
			self::$relay = null;
			self::fail('Could not open an SSH session to the guest through ' . $vm);
		}

		register_shutdown_function(
			function () {
				fclose(self::$relayPipes[0]);
				fclose(self::$relayPipes[1]);
				proc_close(self::$relay);
				@unlink(self::$relayErrors);
			}
		);
	}

	private static function relayErrors(): string
	{
		$errors = self::$relayErrors === null ? '' : trim((string) @file_get_contents(self::$relayErrors));

		return $errors === '' ? '' : ("SSH session errors:\n" . $errors);
	}
}
