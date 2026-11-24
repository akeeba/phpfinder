<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Unit;

use Akeeba\PHPFinder\Tests\Support\FakeSystem;
use Akeeba\PHPFinder\Tests\Support\KnownBug;
use PHPUnit\Framework\TestCase;

/**
 * The search strategies which shell out to a system tool, against fake `whereis`, `update-alternatives` and
 * `brew` scripts placed first in the PATH. The integration suite runs the real tools in a Linux container.
 */
final class StrategiesTest extends TestCase
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

	public function testWhereIsFindsBinaries(): void
	{
		$php = $this->fakeWhereIs();

		[$result, $diagnostics] = $this->withDiagnostics(function () {
			return $this->finder(['useWhereIs' => true])->getBestPath('5.9');
		});

		$this->assertSame($php, $result);
		$this->assertSame([], $diagnostics);
	}

	public function testWhereIsIsGated(): void
	{
		$php = $this->fakeWhereIs();

		$this->assertNotContains($php, $this->finder(['useWhereIs' => false])->getPossiblePaths('5.9'));
		$this->assertNotContains($php, $this->finder(['useWhereIs' => true, 'osSpecific' => false])->getPossiblePaths('5.9'));
		$this->assertNull($this->finder(['useWhereIs' => false])->getBestPath('5.9'));
	}

	public function testUpdateAlternativesFindsBinaries(): void
	{
		$php = $this->fakePhp('ua/php59', '5.9.1');
		$fpm = $this->fakePhp('ua/php-fpm59', '5.9.1', 'fpm-fcgi');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"[ \"\$1\" = '--list' ] && [ \"\$2\" = 'php' ] || exit 2\n"
			. "echo '" . $php . "'\n"
			. "echo '" . $fpm . "'\n"
		);

		$this->expectKnownBug(
			'pathsUsingUpdateAlternatives() returns [] when PHP_OS_FAMILY IS Linux or BSD (the in_array() check '
			. 'lacks its "!"). update-alternatives only exists on Debian-family Linux, so the strategy never runs '
			. 'anywhere it could work.',
			function () use ($php, $fpm) {
				$finder = $this->finder(['useUpdateAlternatives' => true]);
				$paths  = $finder->getPossiblePaths('5.9');

				$this->assertContains($php, $paths);
				$this->assertNotContains($fpm, $paths, 'Only names for the requested version are kept');
				$this->assertSame($php, $finder->getBestPath('5.9'));
			},
			in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD'])
		);
	}

	public function testUpdateAlternativesIsGated(): void
	{
		$php = $this->fakePhp('ua/php59', '5.9.1');

		$this->fakeExecutable('bin/update-alternatives', "echo '" . $php . "'\n");

		$this->assertNotContains($php, $this->finder(['useUpdateAlternatives' => false])->getPossiblePaths('5.9'));
		$this->assertNotContains(
			$php, $this->finder(['useUpdateAlternatives' => true, 'osSpecific' => false])->getPossiblePaths('5.9')
		);
	}

	public function testHomebrewFindsTheVersionedFormula(): void
	{
		$expected = $this->fakeBrew();

		$this->expectKnownBug(
			'pathsHomeBrew() only asks for php@X.Y when `$minor !== null && $major === null`, which can never be '
			. 'true, so it only ever asks for the unversioned "php" formula; and it returns `brew --prefix`, a '
			. 'DIRECTORY, instead of <prefix>/bin/php, which getPossiblePaths() then drops because it is not a file.',
			function () use ($expected) {
				$this->assertSame($expected, $this->finder(['softwareSpecific' => true])->getBestPath('5.9'));
				$this->assertStringContainsString('--prefix php@5.9', (string) @file_get_contents($this->root() . '/brew.log'));
			}
		);
	}

	public function testHomebrewIsGatedBySoftwareSpecific(): void
	{
		$this->fakeBrew();

		$this->expectKnownBug(
			'pathsHomeBrew() is guarded by `!softwareSpecific && !isUnix`, so on Linux and macOS it runs `brew` '
			. 'even when softwareSpecific is off (it should be ||). pathsWAMPServer() has the same && mistake.',
			function () {
				$this->finder(['softwareSpecific' => false])->getPossiblePaths('5.9');

				// Not assertFileDoesNotExist(): it does not exist in PHPUnit 8.5.
				$this->assertFalse(is_file($this->root() . '/brew.log'), 'brew was run');
			},
			strtoupper(PHP_OS_FAMILY) !== 'WINDOWS'
		);
	}

	/**
	 * A `whereis` which knows about ROOT/wi/php59, printing the util-linux / macOS format:
	 * `name: /path/one /path/two` — and `name:` alone when it finds nothing.
	 */
	private function fakeWhereIs(): string
	{
		$php = $this->fakePhp('wi/php59', '5.9.1');
		$dir = $this->root() . '/wi';

		$this->fakeExecutable(
			'bin/whereis',
			"[ \"\$1\" = '-b' ] || exit 2\n"
			. "if [ -x '" . $dir . "/'\"\$2\" ]; then echo \"\$2: " . $dir . "/\$2 " . $dir . "\"; else echo \"\$2:\"; fi\n"
		);

		return $php;
	}

	/**
	 * A `brew` which logs its arguments and prints a prefix like the real one, for the formulae `php` (a
	 * PHP 5.8) and `php@5.9`.
	 *
	 * @return  string  The PHP 5.9 binary a correct lookup returns
	 */
	private function fakeBrew(): string
	{
		$this->fakePhp('Cellar/php/bin/php', '5.8.0');
		$php59 = $this->fakePhp('Cellar/php@5.9/bin/php', '5.9.1');

		$this->fakeExecutable(
			'bin/brew',
			"echo \"\$*\" >> '" . $this->root() . "/brew.log'\n"
			. "[ \"\$1\" = '--prefix' ] || exit 1\n"
			. "echo '" . $this->root() . "/Cellar/'\"\$2\"\n"
		);

		return $php59;
	}
}
