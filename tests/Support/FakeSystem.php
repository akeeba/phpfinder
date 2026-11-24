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
	 * @param   array  $overrides  Properties to set, through the public fluent setters.
	 */
	protected function isolatedConfig(array $overrides = []): Configuration
	{
		$config = Configuration::make()
			->setUseConstants(false)
			->setOsSpecific(true)
			->setSoftwareSpecific(false)
			->setExtendedBinaryNameSearch(true)
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
