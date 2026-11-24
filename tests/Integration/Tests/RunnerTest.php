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
 * The PHP running PHPFinder: found through the PHP constants, and through the fixed UNIX paths.
 */
final class RunnerTest extends AbstractIntegrationTestCase
{
	public function testTheRunnerIsTheVersionUnderTest(): void
	{
		$info = $this->runnerInfo();

		$this->assertSame(self::RUNNER, $info['phpBinary']);
		$this->assertStringStartsWith($info['runner'] . '.', $info['runnerVersion']);

		// Expected by run.sh when it starts the stack, e.g. 8.6 for the 8.6-rc image.
		$expected = getenv('PHPFINDER_E2E_RUNNER');

		if ($expected)
		{
			$this->assertSame($expected, $info['runner'], 'The container runs the PHP version run.sh asked for');
		}
	}

	public function testDefaultsFindTheRunnerForItsOwnVersion(): void
	{
		$response = $this->probe('getBestPathMeta', $this->runner());

		$this->assertNoDiagnostics($response);
		$this->assertSame(
			['version' => $this->runnerInfo()['runnerVersion'], 'cli' => true, 'path' => self::RUNNER],
			$response['result']
		);
	}

	public function testTheConstantsAloneFindTheRunner(): void
	{
		$this->assertSame(self::RUNNER, $this->bestPath($this->runner(), self::isolated(['useConstants' => true])));
		$this->assertSame(
			[self::RUNNER],
			$this->probe('getPossiblePaths', $this->runner(), self::isolated(['useConstants' => true]))['result']
		);
		$this->assertNull($this->bestPath($this->runner(), self::isolated()));
	}

	public function testTheFixedUnixPathsFindTheRunner(): void
	{
		// /usr/local/bin/php is a fixed UNIX candidate (and `which php` finds it too).
		$this->assertSame(self::RUNNER, $this->bestPath($this->runner(), self::isolated(['useWhich' => true])));
	}

	public function testBestPathWithoutAVersionReturnsTheRunner(): void
	{
		$this->expectKnownBug(
			'getBestPathMeta(null) with validateVersion (the default) calls strpos($version, null). On PHP 7 a '
			. 'null needle is chr(0), which never matches: getBestPath() without a version ALWAYS returns NULL.',
			function () {
				$this->assertSame(self::RUNNER, $this->probe('getBestPath', null)['result']);
			},
			version_compare($this->runner(), '8.0', '<')
		);
	}

	public function testBestPathWithoutAVersionRaisesNoDiagnostics(): void
	{
		$this->expectKnownBug(
			'getBestPathMeta(null) with validateVersion (the default) calls strpos($version, null): a "Non-string '
			. 'needles" deprecation on PHP 7.3/7.4, and "Passing null to parameter #2 ($needle)" on PHP 8.1+.',
			function () {
				$this->assertNoDiagnostics($this->probe('getBestPath', null));
			},
			$this->runner() !== '8.0'
		);
	}

	public function testBestPathWithoutAVersionAndValidationReturnsTheRunner(): void
	{
		$response = $this->probe('getBestPathMeta', null, ['validateVersion' => false]);

		$this->assertNoDiagnostics($response);
		$this->assertSame(self::RUNNER, $response['result']['path']);
		$this->assertTrue($response['result']['cli']);
	}
}
