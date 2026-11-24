<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Integration\Tests;

use Akeeba\PHPFinder\Tests\Integration\AbstractIntegrationTestCase;

/**
 * getBestPath() with the library's DEFAULT configuration, the way an application uses it: for each PHP
 * version in the container it must return a binary which really is that version's CLI, and NULL for
 * versions which are not installed.
 */
final class BestPathTest extends AbstractIntegrationTestCase
{
	public function testEveryInstalledVersionIsFoundWithTheDefaults(): void
	{
		$versions = array_unique(['8.2', '8.3', '8.4', $this->runner()]);

		foreach ([self::PATH_DEFAULT, self::PATH_WITH_WHICH_DIR] as $path)
		{
			foreach ($versions as $version)
			{
				$response = $this->probe('getBestPathMeta', $version, [], $path);

				$this->assertNoDiagnostics($response);
				$this->assertIsArray($response['result'], "PHP $version not found with PATH=$path");
				$this->assertStringStartsWith($version . '.', $response['result']['version']);
				$this->assertTrue($response['result']['cli']);

				// Run what it returned, independently of PHPFinder: it must really be that PHP's CLI.
				$banner = $this->banner($response['result']['path']);

				$this->assertStringStartsWith('PHP ' . $version . '.', $banner);
				$this->assertStringContainsString('(cli)', $banner);
			}
		}
	}

	public function testDefaultsPreferTheDottedNameInTheFixedUnixPaths(): void
	{
		// The fixed UNIX paths, /usr/bin/php8.2 among them, come before `which`, which comes before the
		// software-specific candidates.
		$this->assertSame(self::DOTTED_82, $this->bestPath('8.2', [], self::PATH_WITH_WHICH_DIR));
		$this->assertSame(self::DOTTED_82, $this->bestPath('8.2', [], self::PATH_DEFAULT));
	}

	public function testVersionsWhichAreNotInstalledAreNotFound(): void
	{
		$everything = ['useWhereIs' => true];

		// Versions no binary in the container has. That a version which is merely a SUBSTRING of a real one
		// is not found either is testVersionIsMatchedOnWholeComponents().
		foreach (['10.1', '11.3', '9.9.9', '8.2.999'] as $version)
		{
			$this->assertNull($this->bestPath($version, $everything, self::PATH_WITH_WHICH_DIR), "PHP $version");

			$response = $this->probe('getBestPathMeta', $version, $everything, self::PATH_WITH_WHICH_DIR);

			$this->assertNull($response['result'], "PHP $version (meta)");
			$this->assertNoDiagnostics($response);
		}
	}

	public function testVersionIsMatchedOnWholeComponents(): void
	{
		// The runner, e.g. PHP 8.5.6, is the generic /usr/local/bin/php, tried for every version. Asking for
		// "5.6" used to return it: version validation was a substring match.
		$runnerVersion = $this->runnerInfo()['runnerVersion'];
		$substring     = substr($runnerVersion, strpos($runnerVersion, '.') + 1);

		$this->assertNull($this->bestPath($substring, ['useWhereIs' => true], self::PATH_WITH_WHICH_DIR), "PHP $substring");
		$this->assertSame(self::RUNNER, $this->bestPath($runnerVersion, [], self::PATH_WITH_WHICH_DIR));
	}

	public function testAFullVersionMustMatch(): void
	{
		$meta = $this->probe('getBestPathMeta', '8.3', [])['result'];

		$this->assertSame($meta['path'], $this->bestPath($meta['version'], []));
	}

	public function testNothingIsFoundWithEveryStrategyOff(): void
	{
		$off = ['useConstants' => false, 'osSpecific' => false, 'softwareSpecific' => false];

		foreach (['8.2', '8.3', '8.4', $this->runner()] as $version)
		{
			$this->assertSame([], $this->probe('getPossiblePaths', $version, $off, self::PATH_WITH_WHICH_DIR)['result']);
			$this->assertNull($this->bestPath($version, $off, self::PATH_WITH_WHICH_DIR));
		}
	}
}
