<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Unit;

use Akeeba\PHPFinder\Configuration;
use Akeeba\PHPFinder\PHPFinder;
use Akeeba\PHPFinder\Tests\Support\FakeSystem;
use Akeeba\PHPFinder\Tests\Support\KnownBug;
use PHPUnit\Framework\TestCase;

/**
 * How PHPFinder builds its candidate binary names and paths. These are private methods, called through a
 * bound closure: they are pure functions of the version, the configuration and PHP_OS_FAMILY, and testing
 * them directly is the only way to cover the branches for operating systems other than the host's.
 */
final class CandidatesTest extends TestCase
{
	use FakeSystem;
	use KnownBug;

	protected function setUp(): void
	{
		$this->setUpFakeSystem();
	}

	protected function tearDown(): void
	{
		$this->tearDownFakeSystem();
	}

	public function testMajorMinor(): void
	{
		$finder = new PHPFinder();
		$cases  = [
			[null, [null, null]],
			['', [null, null]],
			['abc', [null, null]],
			['8', [8, null]],
			['8.4', [8, 4]],
			['8.4.12', [8, 4]],
			['7.4.33-dev', [7, 4]],
			['10.11', [10, 11]],
		];

		foreach ($cases as $case)
		{
			[$version, $expected] = $case;

			$this->assertSame(
				$expected,
				$this->callPrivate($finder, 'getMajorMinor', [$version]),
				'getMajorMinor(' . var_export($version, true) . ')'
			);
		}
	}

	public function testMajorMinorKeepsAZeroMinor(): void
	{
		$this->expectKnownBug(
			'getMajorMinor() does `$minor ?: null`, so a minor version of 0 becomes NULL: "8.0" is treated as '
			. 'the bare major "8". PHP 8.0 / 7.0 are then searched as php8 / php7 instead of php80 / php8.0, and '
			. 'the cPanel, CloudLinux and Plesk paths (which need a minor) are skipped altogether.',
			function () {
				$finder = new PHPFinder();

				$this->assertSame([8, 0], $this->callPrivate($finder, 'getMajorMinor', ['8.0']));
				$this->assertContains('php80', $this->callPrivate($finder, 'getBinaryNamesForVersion', ['8.0']));
			}
		);
	}

	public function testBinaryNamesWithoutAVersion(): void
	{
		$this->assertSame(['php'], $this->callPrivate(new PHPFinder(), 'getBinaryNamesForVersion', [null]));
	}

	public function testBinaryNamesForAVersion(): void
	{
		$names = $this->callPrivate(new PHPFinder(), 'getBinaryNamesForVersion', ['8.4']);

		$this->assertSame('php', $names[0], 'The generic name comes first');

		foreach (['php84', 'ea-php84', 'alt-php84', 'php-cli', 'php84-cli', 'php-84', 'php-84cli', 'php-cli-84', 'php-cli84'] as $name)
		{
			$this->assertContains($name, $names);
		}
	}

	public function testBinaryNamesWithoutTheExtendedSearch(): void
	{
		$finder = new PHPFinder(Configuration::make()->setExtendedBinaryNameSearch(false));
		$names  = $this->callPrivate($finder, 'getBinaryNamesForVersion', ['8.4']);

		foreach (['php', 'php84', 'ea-php84', 'alt-php84'] as $name)
		{
			$this->assertContains($name, $names);
		}

		foreach (['php-cli', 'php84-cli', 'php-84', 'php-cli-84', 'php-cli84'] as $name)
		{
			$this->assertNotContains($name, $names);
		}
	}

	public function testBinaryNamesIncludeTheDottedName(): void
	{
		// Used to be spelled with a colon: php8:4, php8:4-cli, php-8:4.
		$names = $this->callPrivate(new PHPFinder(), 'getBinaryNamesForVersion', ['8.4']);

		foreach (['php8.4', 'php8.4-cli', 'php-8.4'] as $name)
		{
			$this->assertContains($name, $names);
		}

		foreach ($names as $name)
		{
			$this->assertStringNotContainsString(':', $name);
		}

		$this->assertContains(
			'php8.4',
			$this->callPrivate(
				new PHPFinder(Configuration::make()->setExtendedBinaryNameSearch(false)), 'getBinaryNamesForVersion', ['8.4']
			)
		);
	}

	public function testBinaryNamesHaveNoDuplicates(): void
	{
		foreach (['8', '8.4', '8.4.1'] as $version)
		{
			$names = $this->callPrivate(new PHPFinder(), 'getBinaryNamesForVersion', [$version]);

			$this->assertSame(array_values(array_unique($names)), $names, "Binary names for $version");
		}

		$this->assertContains('php8', $this->callPrivate(new PHPFinder(), 'getBinaryNamesForVersion', ['8']));
	}

	public function testUnixPaths(): void
	{
		if (!in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD', 'DARWIN']))
		{
			$this->markTestSkipped('pathsUNIX() only applies to Linux, BSD and macOS.');
		}

		$paths = $this->callPrivate(new PHPFinder(), 'pathsUNIX', ['8.4']);

		$this->assertSame(
			['/usr/bin/php', '/usr/local/bin/php', '/opt/bin/php', '/usr/bin/php84', '/usr/local/bin/php84', '/opt/bin/php84'],
			array_slice($paths, 0, 6)
		);
		$this->assertContains('/usr/local/bin/php-cli-84', $paths);
		$this->assertContains('/usr/bin/php8.4', $paths);
		$this->assertSame(['/usr/bin/php', '/usr/local/bin/php', '/opt/bin/php'], $this->callPrivate(new PHPFinder(), 'pathsUNIX', [null]));

		$this->assertSame([], $this->callPrivate(new PHPFinder(Configuration::make()->setOsSpecific(false)), 'pathsUNIX', ['8.4']));
		// The fixed UNIX paths are switched off by useWhich too, not only by osSpecific.
		$this->assertSame([], $this->callPrivate(new PHPFinder(Configuration::make()->setUseWhich(false)), 'pathsUNIX', ['8.4']));
	}

	public function testConstantsPaths(): void
	{
		$this->assertContains(PHP_BINARY, $this->callPrivate(new PHPFinder(), 'pathsFromConstants'));
		$this->assertSame([], $this->callPrivate(new PHPFinder(Configuration::make()->setUseConstants(false)), 'pathsFromConstants'));
	}

	public function testPleskPaths(): void
	{
		$finder = new PHPFinder();
		$paths  = $this->callPrivate($finder, 'pathsPlesk', ['8.4.1']);

		switch (strtoupper(PHP_OS_FAMILY))
		{
			case 'LINUX':
			case 'BSD':
				$this->assertSame(['/opt/plesk/php/8.4/bin/php', '/usr/bin/php8.4', '/usr/local/bin/php8.4'], $paths);
				break;

			case 'WINDOWS':
				$this->assertIsArray($paths);
				break;

			default:
				$this->assertSame([], $paths);
		}

		$this->assertSame([], $this->callPrivate($finder, 'pathsPlesk', [null]));
		$this->assertSame([], $this->callPrivate($finder, 'pathsPlesk', ['8']));
		$this->assertSame([], $this->callPrivate(new PHPFinder(Configuration::make()->setSoftwareSpecific(false)), 'pathsPlesk', ['8.4']));
	}

	public function testCPanelAndCloudLinuxPathsNeedAVersionAndSoftwareSpecific(): void
	{
		$finder = new PHPFinder();
		$off    = new PHPFinder(Configuration::make()->setSoftwareSpecific(false));

		foreach (['pathsCPanel', 'pathsCloudLinux'] as $method)
		{
			$this->assertSame([], $this->callPrivate($finder, $method, [null]), "$method(null)");
			$this->assertSame([], $this->callPrivate($finder, $method, ['8']), "$method('8')");
			$this->assertSame([], $this->callPrivate($off, $method, ['8.4']), "$method with softwareSpecific off");
		}
	}

	public function testCPanelAndCloudLinuxPathsOnLinux(): void
	{
		if (strtoupper(PHP_OS_FAMILY) !== 'LINUX')
		{
			$this->markTestSkipped(
				'cPanel and CloudLinux only exist on Linux. The integration suite checks this in a Linux container.'
			);
		}

		$this->expectKnownBug(
			'pathsCPanel() and pathsCloudLinux() return [] when PHP_OS_FAMILY IS Linux or BSD (the in_array() '
			. 'check lacks its "!"), so on the only OS where cPanel and CloudLinux exist they never contribute a '
			. 'path, and they do on macOS and Windows instead.',
			function () {
				$finder = new PHPFinder();

				$this->assertSame(
					['/opt/cpanel/ea-php84/root/usr/bin/php', '/usr/bin/ea-php84', '/usr/local/bin/ea-php84'],
					$this->callPrivate($finder, 'pathsCPanel', ['8.4'])
				);
				$this->assertSame(
					['/opt/cloudlinux/alt-php84/root/usr/bin/php', '/usr/bin/alt-php84', '/usr/local/bin/alt-php84'],
					$this->callPrivate($finder, 'pathsCloudLinux', ['8.4'])
				);
			}
		);
	}

	public function testXamppPaths(): void
	{
		$paths = $this->callPrivate(new PHPFinder(), 'pathsXAMPP', [null]);

		switch (strtoupper(PHP_OS_FAMILY))
		{
			case 'LINUX':
			case 'BSD':
				$this->assertSame(['/opt/lampp/php/php'], $paths);
				break;

			case 'DARWIN':
				$this->assertSame(['/Applications/XAMPP/php/php'], $paths);
				break;

			case 'WINDOWS':
				$this->assertNotEmpty($paths);
				break;

			default:
				$this->assertSame([], $paths);
		}

		$this->assertSame([], $this->callPrivate(new PHPFinder(Configuration::make()->setSoftwareSpecific(false)), 'pathsXAMPP', [null]));
	}

	public function testWindowsOnlyStrategiesStayQuietElsewhere(): void
	{
		if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS')
		{
			$this->markTestSkipped('Not applicable on Windows.');
		}

		$finder = new PHPFinder();

		$this->assertSame([], $this->callPrivate($finder, 'pathsUsingWhere', ['8.4']));
		$this->assertSame([], $this->callPrivate($finder, 'pathsFromCommonWindowsInstallPaths', ['8.4']));

		$off = new PHPFinder(Configuration::make()->setSoftwareSpecific(false));

		$this->assertSame([], $this->callPrivate($off, 'pathsWAMPServer', ['8.4']));
	}

	public function testWindowsProgramFilesPaths(): void
	{
		$finder = new PHPFinder();

		$this->assertSame([], $this->callPrivate($finder, 'getWindowsProgramFilesPHPPaths', ['', '8.4']));
		$this->assertSame(
			['C:\\Program Files\\PHP' => 1],
			$this->callPrivate($finder, 'getWindowsProgramFilesPHPPaths', ['C:\\Program Files\\', null])
		);

		$this->expectKnownBug(
			'getWindowsProgramFilesPHPPaths() calls $this->getMajorMinor() WITHOUT the $version, so $minor is '
			. 'always NULL and the version-specific directories (PHP84, PHP8.4, PHP8.4.1) are never added.',
			function () use ($finder) {
				$this->assertSame(
					[
						'C:\\Program Files\\PHP'      => 1,
						'C:\\Program Files\\PHP84'    => 0,
						'C:\\Program Files\\PHP8.4'   => 0,
						'C:\\Program Files\\PHP8.4.1' => 0,
					],
					$this->callPrivate($finder, 'getWindowsProgramFilesPHPPaths', ['C:\\Program Files\\', '8.4.1'])
				);
			}
		);
	}

	public function testRecursivePathScannerHonoursTheDepth(): void
	{
		$this->fakePhp('scan/php', '5.9.1');
		$this->fakePhp('scan/one/php', '5.9.1');
		$this->fakePhp('scan/one/two/php', '5.9.1');
		$this->fakePhp('scan/one/php-not-wanted', '5.9.1');

		$finder = new PHPFinder();
		$root   = $this->root() . '/scan';

		$this->assertCount(1, $this->callPrivate($finder, 'recursivePathScanner', [$root, 0, 0, ['php']]));
		$this->assertCount(2, $this->callPrivate($finder, 'recursivePathScanner', [$root, 0, 1, ['php']]));
		$this->assertCount(3, $this->callPrivate($finder, 'recursivePathScanner', [$root, 0, 2, ['php']]));
		$this->assertSame([], $this->callPrivate($finder, 'recursivePathScanner', [$root . '/missing', 0, 2, ['php']]));
	}

	public function testRecursivePathScannerReturnsFullPaths(): void
	{
		$php = $this->fakePhp('scan/one/php', '5.9.1');

		$this->expectKnownBug(
			'recursivePathScanner() returns $file->getBasename() ("php", "php.exe") instead of the full path, so '
			. 'everything it finds (MAMP on macOS, the common Windows directories, WAMPServer) is later checked with '
			. 'is_file() relative to the current working directory and dropped.',
			function () use ($php) {
				$this->assertSame(
					[$php],
					$this->callPrivate(new PHPFinder(), 'recursivePathScanner', [$this->root() . '/scan', 0, 1, ['php']])
				);
			}
		);
	}
}
