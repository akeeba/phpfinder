<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Windows\Tests;

use Akeeba\PHPFinder\Tests\Windows\AbstractWindowsTestCase;

/**
 * Each Windows search strategy, on its own, against real PHP builds at the paths it looks in.
 */
final class StrategiesTest extends AbstractWindowsTestCase
{
	private const PATH_WHERE = self::PATH_A . ';' . self::PATH_B . ';' . self::PATH_SYSTEM;

	public function testWhereFindsEveryBinaryInThePath(): void
	{
		$config = self::isolated(['useWhere' => true]);

		// `where php` lists both php.exe, in PATH order; `where php82` the php82.exe.
		$this->assertSamePaths(
			[self::PATH_A_81, self::PATH_B_82, self::PATH_B_PHP82_82],
			$this->possiblePaths('8.2', $config, self::PATH_WHERE)
		);
		$this->assertSame(self::PATH_A_81, $this->bestPath('8.1', $config, self::PATH_WHERE));
		$this->assertSame(self::PATH_B_82, $this->bestPath('8.2', $config, self::PATH_WHERE));
		$this->assertNull($this->bestPath('8.3', $config, self::PATH_WHERE));
	}

	public function testWhereFindsNothingWithoutPhpInThePath(): void
	{
		$config = self::isolated(['useWhere' => true]);

		$this->assertSame([], $this->possiblePaths('8.2', $config, self::PATH_SYSTEM));
		$this->assertNull($this->bestPath(null, $config, self::PATH_SYSTEM));
	}

	public function testWhereOnlySearchesThePath(): void
	{
		// The working directory holds a php.exe, and is not in the PATH.
		$response = $this->probe(
			'getPossiblePaths', '8.2', self::isolated(['useWhere' => true]), self::PATH_SYSTEM, [], null, self::PATH_B
		);

		$this->assertClean($response);

		$this->expectKnownBug(
			'pathsUsingWhere() runs `where php`, which searches the current directory before the PATH, so a php.exe '
			. 'in the working directory of the application is found and run (known-issues row 28).',
			function () use ($response) {
				$this->assertSame([], $response['result']);
			}
		);
	}

	public function testWhereIsGated(): void
	{
		$this->assertSame([], $this->possiblePaths('8.2', self::isolated(), self::PATH_WHERE));
		$this->assertSame(
			[], $this->possiblePaths('8.2', self::isolated(['useWhere' => true, 'osSpecific' => false]), self::PATH_WHERE)
		);
	}

	public function testCommonWindowsDirectories(): void
	{
		$config = self::isolated(['usePHPCommonWinDirsSearch' => true]);

		$this->assertSame(self::COMMON_VERSIONED_83, $this->bestPath('8.3', $config, self::PATH_SYSTEM));
		$this->assertSame(self::COMMON_ROOT_84, $this->bestPath('8.4', $config, self::PATH_SYSTEM));
		$this->assertSame(self::PROGRAM_FILES_82, $this->bestPath('8.2', $config, self::PATH_SYSTEM));
		$this->assertSame(self::PROGRAM_FILES_X86_81, $this->bestPath('8.1', $config, self::PATH_SYSTEM));
		$this->assertSame(self::SECOND_DRIVE_85, $this->bestPath('8.5', $config, self::PATH_SYSTEM));
		$this->assertNull($this->bestPath('8.0', $config, self::PATH_SYSTEM));
	}

	public function testCommonWindowsDirectoriesOrder(): void
	{
		// The versioned directories (C:\PHP83, scanned at depth 0) first, then the generic PHP directories of every
		// drive, with their subdirectories (depth 1), in the order they were listed: C:\PHP before C:\Program Files\PHP.
		$paths = $this->possiblePaths('8.3', self::isolated(['usePHPCommonWinDirsSearch' => true]), self::PATH_SYSTEM);

		// Used to be ordered with asort(), which is not stable before PHP 8.0: on PHP 7, C:\Program Files\PHP came
		// before C:\PHP.
		$this->assertSamePaths([self::COMMON_VERSIONED_83, self::COMMON_ROOT_84, self::PROGRAM_FILES_82], $paths);
	}

	public function testCommonWindowsDirectoriesAreGated(): void
	{
		$this->assertNull($this->bestPath('8.3', self::isolated(), self::PATH_SYSTEM));
		$this->assertNull(
			$this->bestPath('8.3', self::isolated(['usePHPCommonWinDirsSearch' => true, 'osSpecific' => false]), self::PATH_SYSTEM)
		);
	}

	public function testWampServer(): void
	{
		$config = self::isolated(['softwareSpecific' => true]);
		$paths  = $this->possiblePaths('8.0', $config, self::PATH_SYSTEM);

		$this->assertContainsPath(self::WAMP_80, $paths);
		$this->assertContainsPath(self::WAMP_72, $paths);
		$this->assertSame(self::WAMP_80, $this->bestPath('8.0', $config, self::PATH_SYSTEM));
	}

	public function testMamp(): void
	{
		$config = self::isolated(['softwareSpecific' => true]);

		$this->assertContainsPath(self::MAMP_86, $this->possiblePaths(null, $config, self::PATH_SYSTEM));
		$this->assertSame(self::MAMP_86, $this->bestPath('8.6', $config, self::PATH_SYSTEM));
	}

	public function testXampp(): void
	{
		$config = self::isolated(['softwareSpecific' => true]);

		// XAMPP comes before WAMPServer, whose 7.2 is found too.
		$this->assertSame(self::XAMPP_72, $this->bestPath('7.2', $config, self::PATH_SYSTEM));
	}

	public function testPlesk(): void
	{
		$config = self::isolated(['softwareSpecific' => true]);

		// Plesk sets plesk_dir, in lower case and with a trailing backslash; Windows' environment is case-insensitive.
		$this->assertSame(
			self::PLESK_83,
			$this->bestPath('8.3', $config, self::PATH_SYSTEM, ['plesk_dir' => self::PLESK_DIR])
		);
		$this->assertNull($this->bestPath('8.3', $config, self::PATH_SYSTEM));
	}

	public function testSoftwareSpecificIsGated(): void
	{
		$this->assertNull($this->bestPath('8.6', self::isolated(), self::PATH_SYSTEM));
		$this->assertNull($this->bestPath('8.0', self::isolated(), self::PATH_SYSTEM));
	}

	public function testDefaultConfiguration(): void
	{
		$response = $this->probe('getBestPath', '8.0', [], self::PATH_SYSTEM);

		$this->assertClean($response);

		// The runner itself (PHP_BINARY) when it is PHP 8.0, otherwise WAMPServer's 8.0.
		$expected = $response['runner'] === '8.0' && $response['sapi'] === 'cli' ? $response['phpBinary'] : self::WAMP_80;

		$this->assertSame($expected, $response['result']);
	}

	public function testTheProcessEnvironment(): void
	{
		// No PATH given: the probe keeps the one it inherited (the SSH session's, or the IIS site's), with no PHP in it.
		$response = $this->probe('getBestPath', '8.5', []);

		$this->assertClean($response);

		$expected = $response['runner'] === '8.5' && $response['sapi'] === 'cli' ? $response['phpBinary'] : self::SECOND_DRIVE_85;

		$this->assertSame($expected, $response['result']);
	}

	public function testNothingFoundIsNull(): void
	{
		$this->assertNull($this->bestPath('5.6', [], self::PATH_WHERE));
		$this->assertNull($this->bestPath('8.9', [], self::PATH_WHERE));
	}
}
