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

	public function testOsFamilySeam(): void
	{
		// The tests simulate other operating systems through the private $osFamily. Outside them it is NULL,
		// and PHPFinder goes by PHP_OS_FAMILY.
		$finder = new PHPFinder();

		$this->assertSame(strtoupper(PHP_OS_FAMILY), $this->callPrivate($finder, 'getOsFamily'));
		$this->assertSame('WINDOWS', $this->callPrivate($this->simulateOs($finder, 'Windows'), 'getOsFamily'));
	}

	public function testWindowsDrivesAreCachedPerObject(): void
	{
		// Each object looks the drives up once; a test can preset them on a fresh object.
		$finder = new PHPFinder();
		$this->setPrivate($finder, 'windowsDrives', ['X:', 'Y:']);

		$this->assertSame(['X:', 'Y:'], $this->callPrivate($finder, 'getWindowsDrives'));
		$this->assertNotSame(['X:', 'Y:'], $this->callPrivate(new PHPFinder(), 'getWindowsDrives'));
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
			['8.0', [8, 0]],
			['7.0.33', [7, 0]],
			['8.0RC1', [8, 0]],
			['7.4-dev', [7, 4]],
			// A second component which does not start with a digit is no minor version at all.
			['8.', [8, null]],
			['8.x', [8, null]],
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
		// Used to be `$minor ?: null`, so "8.0" was treated as the bare major "8": PHP 8.0 / 7.0 were searched
		// as php8 / php7 instead of php80 / php8.0, and the cPanel, CloudLinux and Plesk paths (which need a
		// minor) were skipped altogether.
		$finder = new PHPFinder();

		$this->assertSame([8, 0], $this->callPrivate($finder, 'getMajorMinor', ['8.0']));

		$names = $this->callPrivate($finder, 'getBinaryNamesForVersion', ['8.0']);

		foreach (['php80', 'php8.0', 'ea-php80', 'alt-php80', 'php80-cli', 'php8.0-cli', 'php-8.0'] as $name)
		{
			$this->assertContains($name, $names);
		}

		$this->assertNotContains('php8', $names, 'PHP 8.0 is not searched for as the bare major');
	}

	public function testSoftwareSpecificPathsForAZeroMinor(): void
	{
		$finder = new PHPFinder();

		switch (strtoupper(PHP_OS_FAMILY))
		{
			case 'LINUX':
				$this->assertSame(
					['/opt/cpanel/ea-php80/root/usr/bin/php', '/usr/bin/ea-php80', '/usr/local/bin/ea-php80'],
					$this->callPrivate($finder, 'pathsCPanel', ['8.0'])
				);
				$this->assertSame(
					['/opt/cloudlinux/alt-php80/root/usr/bin/php', '/usr/bin/alt-php80', '/usr/local/bin/alt-php80'],
					$this->callPrivate($finder, 'pathsCloudLinux', ['8.0'])
				);
				// No break: Plesk has the same paths on Linux and BSD.

			case 'BSD':
				$this->assertSame(
					['/opt/plesk/php/8.0/bin/php', '/usr/bin/php8.0', '/usr/local/bin/php8.0'],
					$this->callPrivate($finder, 'pathsPlesk', ['8.0.30'])
				);
				break;

			default:
				$this->markTestSkipped(
					'The cPanel, CloudLinux and Plesk paths only exist on Linux and BSD. The integration suite checks '
					. 'the cPanel one in a Linux container.'
				);
		}
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

		// Used to return [] on Linux and BSD: the OS check was inverted.
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

	public function testCPanelAndCloudLinuxPathsOnlyOnLinux(): void
	{
		if (strtoupper(PHP_OS_FAMILY) === 'LINUX')
		{
			$this->markTestSkipped('Not applicable on Linux.');
		}

		// Used to return the cPanel and CloudLinux paths everywhere BUT Linux and BSD.
		$finder = new PHPFinder();

		$this->assertSame([], $this->callPrivate($finder, 'pathsCPanel', ['8.4']));
		$this->assertSame([], $this->callPrivate($finder, 'pathsCloudLinux', ['8.4']));
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

		// Used to call getMajorMinor() WITHOUT the version, so the version-specific directories were never added.
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

	public function testRecursivePathScannerHonoursTheDepth(): void
	{
		$zero = $this->fakePhp('scan/php', '5.9.1');
		$one  = $this->fakePhp('scan/one/php', '5.9.1');
		$two  = $this->fakePhp('scan/one/two/php', '5.9.1');
		$this->fakePhp('scan/one/php-not-wanted', '5.9.1');

		$finder = new PHPFinder();
		$root   = $this->root() . '/scan';
		$scan   = function (string $path, int $maxNesting) use ($finder): array {
			$found = $this->callPrivate($finder, 'recursivePathScanner', [$path, 0, $maxNesting, ['php']]);

			sort($found);

			return $found;
		};

		$this->assertSame([$zero], $scan($root, 0));
		$this->assertSame([$one, $zero], $scan($root, 1));
		$this->assertSame([$one, $two, $zero], $scan($root, 2));
		$this->assertSame([], $scan($root . '/missing', 2));
	}

	public function testRecursivePathScannerReturnsFullPaths(): void
	{
		// Used to return $file->getBasename() ("php", "php.exe"), so everything it found (MAMP on macOS, the
		// common Windows directories, WAMPServer) was later checked with is_file() relative to the current
		// working directory, and dropped.
		$php    = $this->fakePhp('scan/one/php', '5.9.1');
		$finder = new PHPFinder();

		$this->assertSame(
			[$php], $this->callPrivate($finder, 'recursivePathScanner', [$this->root() . '/scan', 0, 1, ['php']])
		);

		// pathsMAMP() passes its root with a trailing separator.
		$this->assertSame(
			[$php], $this->callPrivate($finder, 'recursivePathScanner', [$this->root() . '/scan/', 0, 1, ['php']])
		);
	}
}
