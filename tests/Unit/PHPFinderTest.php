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
use PHPUnit\Framework\TestCase;

/**
 * PHPFinder's public API against fake PHP binaries found through `which` and the PATH.
 */
final class PHPFinderTest extends TestCase
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

	public function testFindsTheDottedBinaryNameThroughWhich(): void
	{
		// The phpX.Y name Debian and Ubuntu install, e.g. /usr/bin/php8.4.
		$php = $this->fakePhp('bin/php5.9', '5.9.1');

		$this->assertSame($php, $this->finder(['useWhich' => true])->getBestPath('5.9'));
		$this->assertSame($php, $this->finder(['useWhich' => true, 'extendedBinaryNameSearch' => false])->getBestPath('5.9'));
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
		// Without the fixed UNIX paths: PHP_BINARY may also be one of them, e.g. /usr/local/bin/php.
		$this->assertContains(PHP_BINARY, $this->finder(['useConstants' => true, 'useFixedPaths' => false])->getPossiblePaths());
		$this->assertNotContains(PHP_BINARY, $this->finder(['useConstants' => false, 'useFixedPaths' => false])->getPossiblePaths());
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
		// Used to be a substring match: "5.9" accepted PHP 7.5.9, "7" accepted PHP 8.1.7.
		$fake59 = $this->fakePhp('bin/php59', '7.5.9');
		$fake7  = $this->fakePhp('bin/php7', '8.1.7');

		$finder = $this->finder(['useWhich' => true]);

		// Not assertNull: a real PHP 7 on the host would rightly be returned for "7".
		$this->assertNotSame($fake59, $finder->getBestPath('5.9'), 'PHP 7.5.9 accepted as 5.9');
		$this->assertNotSame($fake7, $finder->getBestPath('7'), 'PHP 8.1.7 accepted as 7');
	}

	public function testVersionValidationAcceptsTheRequestedMajorOrMinor(): void
	{
		// A major version is searched for as php5, a minor one as php59.
		$php5  = $this->fakePhp('bin/php5', '5.9.1');
		$php59 = $this->fakePhp('bin/php59', '5.9.1');

		$finder = $this->finder(['useWhich' => true]);

		$this->assertSame($php5, $finder->getBestPath('5'));
		$this->assertSame($php59, $finder->getBestPath('5.9'));
		$this->assertSame($php59, $finder->getBestPath('5.9.1'));
		$this->assertNull($finder->getBestPath('5.9.10'));
	}

	public function testVersionMatches(): void
	{
		$finder = new PHPFinder();
		$cases  = [
			// requested, actual, matches?
			['5.9', '5.9', true],
			['5.9', '5.9.1', true],
			['5.9', '7.5.9', false],
			['5.9', '5.90.1', false],
			['7', '7.4.33', true],
			['7', '8.1.7', false],
			['7', '17.0.0', false],
			['8.3.1', '8.3.1', true],
			['8.3.1', '8.3.10', false],
			['8.4', '8.40.0', false],
			['8.4', '8.4.0RC1', true],
			['8.4.0', '8.4.0-dev', true],
			['8.4.0-dev', '8.4.0', false],
			['8.1.2-1ubuntu2.14', '8.1.2-1ubuntu2.14', true],
		];

		foreach ($cases as $case)
		{
			[$requested, $actual, $expected] = $case;

			$this->assertSame(
				$expected,
				$this->callPrivate($finder, 'versionMatches', [$actual, $requested]),
				sprintf('"%s" requested, "%s" found', $requested, $actual)
			);
		}
	}

	public function testBestPathWithoutAVersionFindsTheCurrentInterpreter(): void
	{
		// Used to be NULL on PHP 7: the version was validated with strpos($version, null).
		[$result] = $this->withDiagnostics(function () {
			return (new PHPFinder())->getBestPath();
		});

		$this->assertSame(PHP_BINARY, $result);

		// An empty string means "any version" too.
		[$result] = $this->withDiagnostics(function () {
			return (new PHPFinder())->getBestPath('');
		});

		$this->assertSame(PHP_BINARY, $result);
	}

	public function testBestPathWithoutAVersionRaisesNoDiagnostics(): void
	{
		// Used to raise a strpos() deprecation for the null needle on PHP 7.3/7.4 and 8.1+.
		[, $diagnostics] = $this->withDiagnostics(function () {
			return (new PHPFinder())->getBestPath();
		});

		$this->assertSame([], $diagnostics);
	}

	public function testBestPathWithoutAVersionStillValidatesTheBinary(): void
	{
		// Without a version, a binary still has to report a PHP version, and the CLI SAPI when validateCli is on.
		$cgi = $this->fakePhp('bin/php', '5.9.1', 'cgi-fcgi');

		$this->assertNotContains($cgi, [$this->finder(['useWhich' => true])->getBestPath()]);

		$meta = $this->finder(['useWhich' => true, 'validateCli' => false])->getBestPathMeta();

		$this->assertIsObject($meta);
		$this->assertNotNull($meta->version);

		if (!$this->hostHasGenericPhp())
		{
			$this->assertNull($this->finder(['useWhich' => true])->getBestPath());
			$this->assertSame($cgi, $meta->path);
			$this->assertSame('5.9.1', $meta->version);
		}
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
		// Used to run escapeshellcmd($path) . " -v". escapeshellcmd() does not quote, so a path with a space
		// (C:\Program Files\…, /Applications/MAMP PRO/…) was split into several words and never run.
		$php = $this->fakePhp('dir with spaces/php59', '5.9.1');

		$info = $this->callPrivate(new PHPFinder(), 'analyzePHPVersion', [$php]);

		$this->assertSame('5.9.1', $info->version);
		$this->assertTrue($info->cli);
	}

	public function testPathsWithShellMetacharactersAreAnalysed(): void
	{
		// escapeshellcmd() backslash-escaped these, which inside a path made it point somewhere else.
		$php = $this->fakePhp("it's a \$HOME & (dir); `x`/php59", '5.9.1');

		$info = $this->callPrivate(new PHPFinder(), 'analyzePHPVersion', [$php]);

		$this->assertSame('5.9.1', $info->version);
		$this->assertTrue($info->cli);
	}

	public function testExecutablePathQuoting(): void
	{
		$finder = new PHPFinder();

		// POSIX: escapeshellarg().
		foreach (['Linux', 'Darwin', 'BSD', 'Solaris'] as $os)
		{
			$this->assertSame(
				"'/Applications/MAMP PRO/php'",
				$this->callPrivate($finder, 'quoteExecutablePath', ['/Applications/MAMP PRO/php', $os]),
				$os
			);
		}

		$this->assertSame(
			"'/opt/it'\\''s/php'",
			$this->callPrivate($finder, 'quoteExecutablePath', ["/opt/it's/php", 'Linux'])
		);

		// Windows: plain double quotes, which cmd.exe strips. escapeshellarg() would turn the ! and % of a legal
		// path into spaces.
		$this->assertSame(
			'"C:\\Program Files\\PHP 8.4!\\php.exe"',
			$this->callPrivate($finder, 'quoteExecutablePath', ['C:\\Program Files\\PHP 8.4!\\php.exe', 'Windows'])
		);
		$this->assertSame(
			'"C:\\100% PHP\\php.exe"',
			$this->callPrivate($finder, 'quoteExecutablePath', ['C:\\100% PHP\\php.exe', 'Windows'])
		);

		// A double quote cannot be part of a Windows path; it must never close the quoted string early.
		$this->assertSame(
			'"C:\\a  & calc &  \\php.exe"',
			$this->callPrivate($finder, 'quoteExecutablePath', ['C:\\a" & calc & "\\php.exe', 'Windows'])
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
