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
use PHPUnit\Framework\TestCase;

/**
 * The search strategies which shell out to a system tool, against fake `whereis`, `update-alternatives` and
 * `brew` scripts placed first in the PATH. The integration suite runs the real tools in a Linux container.
 */
final class StrategiesTest extends TestCase
{
	use FakeSystem;

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
		if (!in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD'], true))
		{
			$this->markTestSkipped('update-alternatives is only used on Linux and BSD (Debian GNU/kFreeBSD).');
		}

		$php = $this->fakePhp('ua/php59', '5.9.1');
		$fpm = $this->fakePhp('ua/php-fpm59', '5.9.1', 'fpm-fcgi');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"[ \"\$1\" = '--list' ] && [ \"\$2\" = 'php' ] || exit 2\n"
			. "echo '" . $php . "'\n"
			. "echo '" . $fpm . "'\n"
		);

		// Used to return [] on Linux and BSD: the OS check was inverted.
		$finder = $this->finder(['useUpdateAlternatives' => true]);
		$paths  = $finder->getPossiblePaths('5.9');

		$this->assertContains($php, $paths);
		$this->assertNotContains($fpm, $paths, 'Only names for the requested version are kept');
		$this->assertSame($php, $finder->getBestPath('5.9'));
	}

	public function testUpdateAlternativesOnlyRunsOnLinuxAndBsd(): void
	{
		if (in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD'], true))
		{
			$this->markTestSkipped('Not applicable on Linux and BSD.');
		}

		$php = $this->fakePhp('ua/php59', '5.9.1');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"echo \"\$*\" >> '" . $this->root() . "/update-alternatives.log'\n"
			. "echo '" . $php . "'\n"
		);

		// Used to run everywhere BUT Linux and BSD (the check was inverted).
		$paths = $this->finder(['useUpdateAlternatives' => true])->getPossiblePaths('5.9');

		$this->assertNotContains($php, $paths);
		$this->assertFalse(is_file($this->root() . '/update-alternatives.log'), 'update-alternatives was run');
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

		// Used to ask only for the unversioned "php" formula (the php@X.Y check could never be true), and to
		// return `brew --prefix`, a DIRECTORY, which getPossiblePaths() then dropped because it is not a file.
		$finder = $this->finder(['softwareSpecific' => true]);
		$paths  = $finder->getPossiblePaths('5.9');

		$this->assertSame($expected, $finder->getBestPath('5.9'));
		$this->assertContains($expected, $paths);
		$this->assertNotContains($this->root() . '/brew/opt/php@5.9', $paths, 'The prefix directory is no candidate');
		$this->assertContains('--prefix php@5.9', $this->brewLog());
	}

	public function testHomebrewFallsBackToThePhpFormula(): void
	{
		$this->fakeBrew();

		// There is no php@5.8 formula installed, but the unversioned "php" formula is PHP 5.8.
		$this->assertSame(
			$this->root() . '/brew/opt/php/bin/php',
			$this->finder(['softwareSpecific' => true])->getBestPath('5.8')
		);
		$this->assertContains('--prefix php@5.8', $this->brewLog());
		$this->assertContains('--prefix php', $this->brewLog());
	}

	public function testHomebrewFindsTheLinkedPhp(): void
	{
		$this->fakeBrew();

		// <prefix>/bin/php, the PHP `brew link` put in Homebrew's own prefix (here a PHP 5.7, from no formula
		// the fake knows about).
		$linked = $this->fakePhp('brew/bin/php', '5.7.3');

		$this->assertSame($linked, $this->finder(['softwareSpecific' => true])->getBestPath('5.7'));
		$this->assertContains('--prefix', $this->brewLog());
	}

	public function testHomebrewWithoutAMinorVersionOnlyAsksForThePhpFormula(): void
	{
		$this->fakeBrew();

		$finder = $this->finder(['softwareSpecific' => true]);

		$this->assertContains($this->root() . '/brew/opt/php/bin/php', $finder->getPossiblePaths());
		$this->assertContains($this->root() . '/brew/opt/php/bin/php', $finder->getPossiblePaths('5'));

		foreach ($this->brewLog() as $line)
		{
			$this->assertStringNotContainsString('php@', $line, 'There is no php@X formula for a bare major version');
		}
	}

	public function testHomebrewIsGatedBySoftwareSpecific(): void
	{
		$this->fakeBrew();

		// Used to be guarded by `!softwareSpecific && !isUnix`, so on Linux and macOS it ran `brew` even with
		// softwareSpecific off.
		$this->assertSame([], $this->callPrivate($this->finder(['softwareSpecific' => false]), 'pathsHomeBrew', ['5.9']));
		$this->finder(['softwareSpecific' => false])->getPossiblePaths('5.9');

		// Not assertFileDoesNotExist(): it does not exist in PHPUnit 8.5.
		$this->assertFalse(is_file($this->root() . '/brew.log'), 'brew was run');
	}

	public function testHomebrewDefaultPrefixesWithoutBrew(): void
	{
		// No `brew` in the PATH, as is common under a web server: only the default prefixes are searched.
		$paths = $this->callPrivate($this->finder(['softwareSpecific' => true]), 'pathsHomeBrew', ['8.4']);

		switch (strtoupper(PHP_OS_FAMILY))
		{
			case 'DARWIN':
				$this->assertSame(
					[
						'/opt/homebrew/opt/php@8.4/bin/php',
						'/opt/homebrew/opt/php/bin/php',
						'/usr/local/opt/php@8.4/bin/php',
						'/usr/local/opt/php/bin/php',
					],
					$paths
				);
				break;

			case 'LINUX':
				$this->assertSame(
					['/home/linuxbrew/.linuxbrew/opt/php@8.4/bin/php', '/home/linuxbrew/.linuxbrew/opt/php/bin/php'],
					$paths
				);
				break;

			default:
				// Homebrew supports neither BSD nor Windows.
				$this->assertSame([], $paths);
		}
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
	 * A `brew` which logs its arguments and prints a prefix like the real one: ROOT/brew for a bare
	 * `brew --prefix`, and ROOT/brew/opt/<formula> for `brew --prefix <formula>`, whether that formula is
	 * installed or not. The formulae `php` (a PHP 5.8) and `php@5.9` are installed.
	 *
	 * @return  string  The PHP 5.9 binary a correct lookup returns
	 */
	private function fakeBrew(): string
	{
		$this->fakePhp('brew/opt/php/bin/php', '5.8.0');
		$php59 = $this->fakePhp('brew/opt/php@5.9/bin/php', '5.9.1');

		$this->fakeExecutable(
			'bin/brew',
			"echo \"\$*\" >> '" . $this->root() . "/brew.log'\n"
			. "[ \"\$1\" = '--prefix' ] || exit 1\n"
			. "if [ -z \"\$2\" ]; then echo '" . $this->root() . "/brew'; exit 0; fi\n"
			. "echo '" . $this->root() . "/brew/opt/'\"\$2\"\n"
		);

		return $php59;
	}

	/**
	 * The fake brew's invocations, one array element per call.
	 *
	 * @return  string[]
	 */
	private function brewLog(): array
	{
		return array_map('trim', @file($this->root() . '/brew.log') ?: []);
	}
}
