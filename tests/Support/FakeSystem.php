<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Support;

use Akeeba\PHPFinder\Configuration;
use Akeeba\PHPFinder\PHPFinder;

/**
 * A throwaway, fake system for the unit tests: a temporary directory of fake executables (shell scripts
 * which print `PHP x.y.z (cli)`, or play `which`, `whereis`, `update-alternatives`, `brew`), and a PATH
 * which puts that directory first.
 *
 * The fake PHP binaries report FICTIONAL versions (5.9.x and the like). Whatever real PHP the host has
 * in /usr/bin, /usr/local/bin, Homebrew or PHP_BINARY can therefore never satisfy a version-validated
 * lookup, which keeps the assertions independent of the machine running them.
 */
trait FakeSystem
{
	/** @var string|null */
	private $fakeRoot;

	/** @var string|false */
	private $savedPath;

	protected function setUpFakeSystem(): void
	{
		if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS')
		{
			$this->markTestSkipped('The fake executables are POSIX shell scripts.');
		}

		if (!function_exists('exec'))
		{
			$this->markTestSkipped('exec() is disabled; PHPFinder cannot run anything.');
		}

		$base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
		$root = $base . DIRECTORY_SEPARATOR . 'phpfinder-unit-' . bin2hex(random_bytes(6));

		mkdir($root . '/bin', 0700, true);

		$this->fakeRoot  = $root;
		$this->savedPath = getenv('PATH');

		// Only our fakes, plus the system's basic tools (which, sh). No Homebrew, no /usr/local/bin.
		putenv('PATH=' . $root . '/bin' . PATH_SEPARATOR . '/usr/bin' . PATH_SEPARATOR . '/bin');
	}

	protected function tearDownFakeSystem(): void
	{
		if ($this->savedPath !== null)
		{
			putenv($this->savedPath === false ? 'PATH' : ('PATH=' . $this->savedPath));
		}

		if ($this->fakeRoot !== null && is_dir($this->fakeRoot))
		{
			$this->removeTree($this->fakeRoot);
		}

		$this->fakeRoot  = null;
		$this->savedPath = null;
	}

	/**
	 * The fake system's root directory.
	 */
	protected function root(): string
	{
		return $this->fakeRoot;
	}

	/**
	 * Create a fake PHP binary.
	 *
	 * @param   string  $relPath  Path relative to the fake root, e.g. bin/php59
	 * @param   string  $version  The version it reports, e.g. 5.9.1
	 * @param   string  $sapi     The SAPI it reports: cli, cgi-fcgi, fpm-fcgi
	 *
	 * @return  string  The absolute path
	 */
	protected function fakePhp(string $relPath, string $version, string $sapi = 'cli'): string
	{
		$line = sprintf('PHP %s (%s) (built: Jan  1 2026 00:00:00) (NTS)', $version, $sapi);

		return $this->fakeExecutable(
			$relPath,
			"echo '" . $line . "'\n"
			. "echo 'Copyright (c) The PHP Group'\n"
			. "echo 'Zend Engine v9.9.9, Copyright (c) Zend Technologies'\n"
		);
	}

	/**
	 * Create an executable shell script.
	 *
	 * @param   string  $relPath  Path relative to the fake root
	 * @param   string  $body     The script's body (without the shebang)
	 *
	 * @return  string  The absolute path
	 */
	protected function fakeExecutable(string $relPath, string $body): string
	{
		$path = $this->fakeRoot . '/' . $relPath;

		if (!is_dir(dirname($path)))
		{
			mkdir(dirname($path), 0700, true);
		}

		file_put_contents($path, "#!/bin/sh\n" . $body);
		chmod($path, 0755);

		return $path;
	}

	/**
	 * A configuration with every strategy OFF, so that a test can switch on exactly the ones it is about.
	 *
	 * That includes the fixed UNIX paths (/usr/bin, /usr/local/bin, /opt/bin): useFixedPaths is OFF, while
	 * osSpecific is ON.
	 *
	 * @param   array  $overrides  Properties to set, through the public fluent setters.
	 */
	protected function isolatedConfig(array $overrides = []): Configuration
	{
		$config = Configuration::make()
			->setUseConstants(false)
			->setOsSpecific(true)
			->setSoftwareSpecific(false)
			->setExtendedBinaryNameSearch(true)
			->setUseFixedPaths(false)
			->setUseWhich(false)
			->setUseUpdateAlternatives(false)
			->setUseWhereIs(false)
			->setUseWhere(false)
			->setUsePHPCommonWinDirsSearch(false)
			->setValidateVersion(true)
			->setValidateCli(true);

		foreach ($overrides as $key => $value)
		{
			$config->{'set' . ucfirst($key)}($value);
		}

		return $config;
	}

	protected function finder(array $overrides = []): PHPFinder
	{
		return new PHPFinder($this->isolatedConfig($overrides));
	}

	/**
	 * Call one of PHPFinder's private methods. Closure binding works on every PHP version, unlike
	 * ReflectionMethod::setAccessible(), which is deprecated on recent PHP versions.
	 *
	 * @return  mixed
	 */
	protected function callPrivate($object, string $method, array $args = [])
	{
		$caller = \Closure::bind(
			function ($method, $args) {
				return call_user_func_array([$this, $method], $args);
			},
			$object,
			get_class($object)
		);

		return $caller($method, $args);
	}

	/**
	 * Set one of an object's private properties. Closure binding, as in callPrivate().
	 */
	protected function setPrivate($object, string $property, $value): void
	{
		$setter = \Closure::bind(
			function ($property, $value) {
				$this->{$property} = $value;
			},
			$object,
			get_class($object)
		);

		$setter($property, $value);
	}

	/**
	 * Make a PHPFinder behave as if it ran on another OS family (WINDOWS, LINUX, BSD, DARWIN, …), through its
	 * private $osFamily, which takes the place of PHP_OS_FAMILY. Everything else is still the host's: the
	 * file system, DIRECTORY_SEPARATOR, and the shell which runs the fake executables.
	 */
	protected function simulateOs(PHPFinder $finder, string $osFamily): PHPFinder
	{
		$this->setPrivate($finder, 'osFamily', $osFamily);

		// Windows' own tools run from the Windows directory: the fake one, ROOT/Windows (see windowsTool()). Only on
		// a library which has that property (not on an older src/ given through PHPFINDER_SRC).
		if (strtoupper($osFamily) === 'WINDOWS' && property_exists($finder, 'windowsDirectory'))
		{
			$this->setPrivate($finder, 'windowsDirectory', $this->fakeRoot . '/Windows');
		}

		// On Windows a PHP binary must be a PE image. The fakes are shell scripts: those fakeExecutable() made count as
		// programs, unless made as fakeNonProgramPhp(); any other file gets the library's real check (isPeImage()).
		if (strtoupper($osFamily) === 'WINDOWS' && property_exists($finder, 'peImageCheck'))
		{
			$this->setPrivate(
				$finder,
				'peImageCheck',
				function (string $path) use ($finder): bool {
					return $this->isFakeProgram($path) || $this->callPrivate($finder, 'isPeImage', [$path]);
				}
			);
		}

		return $finder;
	}

	/**
	 * Create a fake PHP binary which stands for a file that is NOT a Windows program (a text file, an empty file named
	 * php.exe): a shell script which the host can run, and which would report a PHP version, but which a finder
	 * simulating Windows must never accept nor run. Each time it runs it appends a line to ran-not-programs.log under
	 * the fake root (see notProgramRuns()).
	 *
	 * @param   string  $relPath  Path relative to the fake root
	 * @param   string  $version  The version it would report
	 *
	 * @return  string  The absolute path
	 */
	protected function fakeNonProgramPhp(string $relPath, string $version): string
	{
		return $this->fakeExecutable(
			$relPath,
			self::$notAProgram . "\n"
			. "echo \"\$0\" >> '" . $this->fakeRoot . "/ran-not-programs.log'\n"
			. "echo 'PHP " . $version . " (cli) (built: Jan  1 2026 00:00:00) (NTS)'\n"
		);
	}

	/**
	 * The fakes made by fakeNonProgramPhp() which ran, one line each.
	 *
	 * @return  string[]
	 */
	protected function notProgramRuns(): array
	{
		$log = $this->fakeRoot . '/ran-not-programs.log';

		return is_file($log) ? array_values(array_filter(array_map('trim', file($log)))) : [];
	}

	/** The line which marks a fake as not being a Windows program (fakeNonProgramPhp()). */
	private static $notAProgram = '# phpfinder-unit: not a Windows program';

	/**
	 * Whether a file stands for a Windows program in a simulated Windows: a shell script fakeExecutable() made, other
	 * than those fakeNonProgramPhp() made.
	 */
	private function isFakeProgram(string $path): bool
	{
		$head = (string) @file_get_contents($path, false, null, 0, 256);

		return strpos($head, "#!/bin/sh\n") === 0 && strpos($head, self::$notAProgram) === false;
	}

	/**
	 * Where a fake of one of Windows' own tools goes, relative to the fake root: in the fake Windows directory, at
	 * the path the library runs it from, e.g. Windows/System32/where.exe.
	 *
	 * @param   string  $tool  where, wmic, fsutil or powershell
	 */
	protected static function windowsTool(string $tool): string
	{
		$paths = [
			'where'      => 'Windows/System32/where.exe',
			'wmic'       => 'Windows/System32/wbem/WMIC.exe',
			'fsutil'     => 'Windows/System32/fsutil.exe',
			'powershell' => 'Windows/System32/WindowsPowerShell/v1.0/powershell.exe',
		];

		return $paths[$tool];
	}

	/**
	 * A fake Windows drive: a directory under the fake root, named like a drive letter, e.g. ROOT/C:.
	 *
	 * The library builds Windows paths by appending backslash-separated names to the drive, e.g.
	 * `$drive . '\\wamp\\bin\\php'`. A backslash is an ordinary file name character on macOS and Linux, so
	 * that path is ONE real directory, literally named `C:\wamp\bin\php`, inside ROOT. fakeWindowsDir() creates
	 * such directories, so that the library's real is_dir() and DirectoryIterator calls find them.
	 */
	protected function fakeWindowsDrive(string $letter = 'C'): string
	{
		return $this->fakeRoot . '/' . $letter . ':';
	}

	/**
	 * Create a directory for a Windows path under a fake drive, e.g. fakeWindowsDir('C', 'wamp\\bin\\php').
	 *
	 * @return  string  The path the library builds for it: the drive, a backslash, and the given path
	 */
	protected function fakeWindowsDir(string $letter, string $windowsPath): string
	{
		$path = $this->fakeWindowsDrive($letter) . '\\' . $windowsPath;

		if (!is_dir($path))
		{
			mkdir($path, 0700, true);
		}

		return $path;
	}

	/**
	 * Run a callable, collecting every PHP diagnostic (warning, notice, deprecation) raised while it runs,
	 * except those silenced with `@`.
	 *
	 * @return  array  [mixed $result, string[] $diagnostics]
	 */
	protected function withDiagnostics(callable $callable): array
	{
		$diagnostics = [];

		// Whatever the runner set (some PHPUnit setups lower it), report everything for the duration.
		$oldReporting = error_reporting(E_ALL);

		set_error_handler(
			function ($errno, $errstr, $errfile, $errline) use (&$diagnostics) {
				if (!(error_reporting() & $errno))
				{
					return true;
				}

				$diagnostics[] = sprintf('%s (%s:%d)', $errstr, basename($errfile), $errline);

				return true;
			}
		);

		try
		{
			$result = $callable();
		}
		finally
		{
			restore_error_handler();
			error_reporting($oldReporting);
		}

		return [$result, $diagnostics];
	}

	/**
	 * Do the host's standard locations hold any real `php` binary? Some assertions can only be exact
	 * when they don't.
	 */
	protected function hostHasGenericPhp(): bool
	{
		foreach (['/usr/bin/php', '/usr/local/bin/php', '/opt/bin/php', '/bin/php'] as $path)
		{
			if (@is_file($path))
			{
				return true;
			}
		}

		return false;
	}

	private function removeTree(string $dir): void
	{
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item)
		{
			$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}

		@rmdir($dir);
	}
}
