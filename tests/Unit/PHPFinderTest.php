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
 * PHPFinder's public API against fake PHP binaries found through `which` and the PATH.
 */
final class PHPFinderTest extends TestCase
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

	public function testMakeAndConstructorUseTheGivenOrADefaultConfiguration(): void
	{
		$this->assertInstanceOf(PHPFinder::class, PHPFinder::make());
		$this->assertInstanceOf(PHPFinder::class, new PHPFinder());

		$config = Configuration::make(['useWhich' => false]);
		$finder = PHPFinder::make($config);

		$this->assertSame($config, $this->readProperty($finder, 'configuration'));
		$this->assertInstanceOf(Configuration::class, $this->readProperty(new PHPFinder(), 'configuration'));
		$this->assertTrue((new \ReflectionClass(PHPFinder::class))->isFinal());
	}

	public function testFindsAVersionedBinaryThroughWhich(): void
	{
		$php = $this->fakePhp('bin/php59', '5.9.1');

		$finder = $this->finder(['useWhich' => true]);

		$this->assertSame($php, $finder->getBestPath('5.9'));
		$this->assertSame($php, $finder->getBestPath('5.9.1'));

		$meta = $finder->getBestPathMeta('5.9');

		$this->assertIsObject($meta);
		$this->assertSame('5.9.1', $meta->version);
		$this->assertTrue($meta->cli);
		$this->assertSame($php, $meta->path);
	}

	public function testFindsExtendedBinaryNamesOnlyWhenEnabled(): void
	{
		$php = $this->fakePhp('bin/php-cli-59', '5.9.2');

		$this->assertSame($php, $this->finder(['useWhich' => true])->getBestPath('5.9'));
		$this->assertNull(
			$this->finder(['useWhich' => true, 'extendedBinaryNameSearch' => false])->getBestPath('5.9')
		);
	}

	public function testReturnsNullWhenNothingMatches(): void
	{
		$this->fakePhp('bin/php59', '5.9.1');

		$finder = $this->finder(['useWhich' => true]);

		$this->assertNull($finder->getBestPath('5.8'));
		$this->assertNull($finder->getBestPathMeta('5.8'));
		$this->assertNull($finder->getBestPath('4.1'));
	}

	public function testEveryStrategyOffFindsNothing(): void
	{
		$this->fakePhp('bin/php59', '5.9.1');

		$finder = new PHPFinder(
			Configuration::make()
				->setUseConstants(false)
				->setOsSpecific(false)
				->setSoftwareSpecific(false)
		);

		$this->assertSame([], $finder->getPossiblePaths('5.9'));
		$this->assertSame([], $finder->getPossiblePaths());
		$this->assertNull($finder->getBestPath('5.9'));
		$this->assertNull($finder->getBestPathMeta('5.9'));
	}

	public function testOsSpecificGatesTheOsSearches(): void
	{
		$php = $this->fakePhp('bin/php59', '5.9.1');

		$this->assertSame($php, $this->finder(['useWhich' => true])->getBestPath('5.9'));
		$this->assertNull($this->finder(['useWhich' => true, 'osSpecific' => false])->getBestPath('5.9'));
		$this->assertNotContains(
			$php, $this->finder(['useWhich' => true, 'osSpecific' => false])->getPossiblePaths('5.9')
		);
	}

	public function testUseWhichGatesWhich(): void
	{
		$php = $this->fakePhp('bin/php59', '5.9.1');

		$this->assertContains($php, $this->finder(['useWhich' => true])->getPossiblePaths('5.9'));
		$this->assertNotContains($php, $this->finder(['useWhich' => false])->getPossiblePaths('5.9'));
		$this->assertNull($this->finder(['useWhich' => false])->getBestPath('5.9'));
	}

	public function testUseConstantsGatesPhpBinary(): void
	{
		$this->assertContains(PHP_BINARY, $this->finder(['useConstants' => true])->getPossiblePaths());
		$this->assertNotContains(PHP_BINARY, $this->finder(['useConstants' => false])->getPossiblePaths());
	}

	public function testCurrentInterpreterIsFoundForItsOwnVersion(): void
	{
		$version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$meta    = $this->finder(['useConstants' => true])->getBestPathMeta($version);

		$this->assertIsObject($meta);
		$this->assertSame(PHP_BINARY, $meta->path);
		$this->assertStringStartsWith($version . '.', $meta->version);
		$this->assertTrue($meta->cli);
	}

	public function testPossiblePathsAreExistingFilesWithoutDuplicates(): void
	{
		$this->fakePhp('bin/php59', '5.9.1');
		$this->fakePhp('bin/php-cli', '5.9.3');

		$paths = (new PHPFinder())->getPossiblePaths('5.9');

		$this->assertNotEmpty($paths);
		$this->assertSame(array_values(array_unique($paths)), array_values($paths));

		foreach ($paths as $path)
		{
			$this->assertIsString($path);
			$this->assertFileExists($path);
		}
	}

	public function testValidateVersionRejectsABinaryReportingAnotherVersion(): void
	{
		// A binary named for 5.9 which is really 5.8, like a stale symlink.
		$php = $this->fakePhp('bin/php59', '5.8.3');

		$this->assertNull($this->finder(['useWhich' => true])->getBestPath('5.9'));

		// Without validation, the first CLI candidate wins whatever its version. On a host with no real
		// `php` in the standard locations, that is our mislabelled binary.
		$result = $this->finder(['useWhich' => true, 'validateVersion' => false])->getBestPathMeta('5.9');

		$this->assertIsObject($result);
		$this->assertContains($result->path, $this->finder(['useWhich' => true])->getPossiblePaths('5.9'));

		if (!$this->hostHasGenericPhp())
		{
			$this->assertSame($php, $result->path);
			$this->assertSame('5.8.3', $result->version);
		}
	}

	public function testValidateCliRejectsANonCliSapi(): void
	{
		$cgi = $this->fakePhp('bin/php59', '5.9.1', 'cgi-fcgi');

		$this->assertNull($this->finder(['useWhich' => true])->getBestPath('5.9'));

		$meta = $this->finder(['useWhich' => true, 'validateCli' => false])->getBestPathMeta('5.9');

		$this->assertIsObject($meta);
		$this->assertSame($cgi, $meta->path);
		$this->assertFalse($meta->cli);
		$this->assertSame('5.9.1', $meta->version);
	}

	public function testCliIsPreferredOverAnEarlierNonCliCandidate(): void
	{
		// `which php59` (the non-CLI one) comes before `which php-cli-59` in the candidate order.
		$this->fakePhp('bin/php59', '5.9.1', 'fpm-fcgi');
		$cli = $this->fakePhp('bin/php-cli-59', '5.9.1');

		$this->assertSame($cli, $this->finder(['useWhich' => true])->getBestPath('5.9'));
	}

	public function testBinariesWhichAreNotExecutableOrNotPhpAreIgnored(): void
	{
		$notExecutable = $this->fakePhp('bin/php59', '5.9.1');
		chmod($notExecutable, 0644);
		$this->fakeExecutable('bin/php-cli-59', "echo 'Hello, world'\n");

		$this->assertNull($this->finder(['useWhich' => true])->getBestPath('5.9'));
	}

	public function testVersionValidationMatchesWholeVersionComponents(): void
	{
		$fake59 = $this->fakePhp('bin/php59', '7.5.9');
		$fake7  = $this->fakePhp('bin/php7', '8.1.7');

		$this->expectKnownBug(
			'getBestPathMeta() validates with strpos($info->version, $version), a substring match: asking for '
			. '"5.9" accepts PHP 7.5.9, and asking for "7" accepts PHP 8.1.7. It should be a prefix match on '
			. 'whole version components ("5.9" matches 5.9 and 5.9.x only).',
			function () use ($fake59, $fake7) {
				$finder = $this->finder(['useWhich' => true]);

				// Not assertNull: a real PHP 7 on the host would rightly be returned for "7".
				$this->assertNotSame($fake59, $finder->getBestPath('5.9'), 'PHP 7.5.9 accepted as 5.9');
				$this->assertNotSame($fake7, $finder->getBestPath('7'), 'PHP 8.1.7 accepted as 7');
			}
		);
	}

	public function testBestPathWithoutAVersionFindsTheCurrentInterpreter(): void
	{
		$this->expectKnownBug(
			'getBestPathMeta(null) with validateVersion (the default) calls strpos($version, null). On PHP 7 a '
			. 'null needle is the character chr(0), which never matches, so getBestPath() without a version '
			. 'ALWAYS returns NULL there.',
			function () {
				[$result] = $this->withDiagnostics(function () {
					return (new PHPFinder())->getBestPath();
				});

				$this->assertSame(PHP_BINARY, $result);
			},
			PHP_MAJOR_VERSION < 8
		);
	}

	public function testBestPathWithoutAVersionRaisesNoDiagnostics(): void
	{
		$this->expectKnownBug(
			'getBestPathMeta(null) with validateVersion (the default) calls strpos($version, null): "Non-string '
			. 'needles" deprecation on PHP 7.3/7.4, "Passing null to parameter #2 ($needle)" deprecation on PHP 8.1+ '
			. '(a TypeError once PHP makes that fatal).',
			function () {
				[, $diagnostics] = $this->withDiagnostics(function () {
					return (new PHPFinder())->getBestPath();
				});

				$this->assertSame([], $diagnostics);
			},
			PHP_VERSION_ID >= 70300 && !(PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100)
		);
	}

	public function testBestPathWithoutAVersionAndWithoutValidationReturnsACli(): void
	{
		$meta = (new PHPFinder(Configuration::make(['validateVersion' => false])))->getBestPathMeta();

		$this->assertIsObject($meta);
		$this->assertTrue($meta->cli);
		$this->assertFileExists($meta->path);
	}

	public function testPathsWithSpacesAreAnalysed(): void
	{
		$php = $this->fakePhp('dir with spaces/php59', '5.9.1');

		$this->expectKnownBug(
			'analyzePHPVersion() runs escapeshellcmd($path) . " -v". escapeshellcmd() does not quote, so a path '
			. 'with a space (C:\\Program Files\\…, /Applications/MAMP PRO/…) is split into several words and the '
			. 'binary is never run. It should use escapeshellarg().',
			function () use ($php) {
				$info = $this->callPrivate(new PHPFinder(), 'analyzePHPVersion', [$php]);

				$this->assertSame('5.9.1', $info->version);
				$this->assertTrue($info->cli);
			}
		);
	}

	public function testAnalyzePhpVersionParsesTheVersionLine(): void
	{
		$finder = new PHPFinder();

		$info = $this->callPrivate($finder, 'analyzePHPVersion', [$this->fakePhp('bin/a', '8.4.0-dev')]);
		$this->assertSame('8.4.0-dev', $info->version);
		$this->assertTrue($info->cli);

		$info = $this->callPrivate($finder, 'analyzePHPVersion', [$this->fakePhp('bin/b', '8.3.1', 'fpm-fcgi')]);
		$this->assertSame('8.3.1', $info->version);
		$this->assertFalse($info->cli);

		// Not PHP at all.
		$info = $this->callPrivate($finder, 'analyzePHPVersion', [$this->fakeExecutable('bin/c', "echo hello\n")]);
		$this->assertNull($info->version);
		$this->assertFalse($info->cli);

		// A truncated version line.
		$info = $this->callPrivate($finder, 'analyzePHPVersion', [$this->fakeExecutable('bin/d', "echo 'PHP 8.3.1'\n")]);
		$this->assertNull($info->version);

		// Missing file.
		$info = $this->callPrivate($finder, 'analyzePHPVersion', [$this->root() . '/nope']);
		$this->assertNull($info->version);
	}

	private function readProperty($object, string $property)
	{
		$reader = \Closure::bind(
			function ($property) {
				return $this->{$property};
			},
			$object,
			get_class($object)
		);

		return $reader($property);
	}
}
