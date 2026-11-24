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

		// Expected by run.sh when it starts the stack, e.g. 8.6 for the 8.6 image.
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
		$constants = self::isolated(['useConstants' => true]);

		$this->assertSame(self::RUNNER, $this->bestPath($this->runner(), $constants));
		$this->assertSame([self::RUNNER], $this->probe('getPossiblePaths', $this->runner(), $constants)['result']);
		$this->assertNull($this->bestPath($this->runner(), self::isolated()));
	}

	public function testTheFixedUnixPathsFindTheRunner(): void
	{
		// /usr/local/bin/php is a fixed UNIX candidate, searched without `which` (which finds it too).
		$fixed = self::isolated(['useFixedPaths' => true]);

		$this->assertSame(self::RUNNER, $this->bestPath($this->runner(), $fixed));
		$this->assertSame([self::RUNNER], $this->probe('getPossiblePaths', $this->runner(), $fixed)['result']);
		$this->assertNull($this->bestPath($this->runner(), self::isolated()));
		$this->assertNull($this->bestPath($this->runner(), self::isolated(['useFixedPaths' => true, 'osSpecific' => false])));
	}

	public function testBestPathWithoutAVersionReturnsTheRunner(): void
	{
		// Used to be NULL on PHP 7: the version was validated with strpos($version, null).
		$this->assertSame(self::RUNNER, $this->probe('getBestPath', null)['result']);
		$this->assertSame(self::RUNNER, $this->probe('getBestPath', '')['result']);
	}

	public function testBestPathWithoutAVersionRaisesNoDiagnostics(): void
	{
		// Used to raise a strpos() deprecation for the null needle on PHP 7.3/7.4 and 8.1+.
		$this->assertNoDiagnostics($this->probe('getBestPath', null));
	}

	public function testBestPathWithoutAVersionAndValidationReturnsTheRunner(): void
	{
		$response = $this->probe('getBestPathMeta', null, ['validateVersion' => false]);

		$this->assertNoDiagnostics($response);
		$this->assertSame(self::RUNNER, $response['result']['path']);
		$this->assertTrue($response['result']['cli']);
	}
}
