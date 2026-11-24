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
 * Nothing the library runs may print on STDERR: a CLI application's user would see it in their console. The relay
 * captures the probe's STDERR apart from its STDOUT; under IIS there is none to capture.
 */
final class StderrTest extends AbstractWindowsTestCase
{
	public function testWhereForNamesWhichAreNotInThePath(): void
	{
		// `where` prints "INFO: Could not find files for the given pattern(s)." on STDERR for every name it does not
		// find: php82, php8.2, ea-php82, … for getBestPath('8.2').
		$response = $this->probe(
			'getBestPath', '8.2', self::isolated(['useWhere' => true]), self::PATH_A . ';' . self::PATH_SYSTEM
		);

		$this->assertClean($response);
		$this->assertNull($response['result']);

		$this->expectKnownBug(
			'pathsUsingWhere() runs `where` without redirecting its STDERR (known-issues row 25).',
			function () use ($response) {
				$this->assertNothingOnStderr($response);
			},
			!self::isIis()
		);
	}

	public function testBinaryWhichCannotRun(): void
	{
		// A php.exe which is not a program: Windows' error message goes to STDERR.
		$response = $this->probe('analyze', null, [], self::PATH_SYSTEM, [], self::BROKEN_EXE);

		$this->assertClean($response);
		$this->assertNull($response['result']['version']);

		$this->expectKnownBug(
			'analyzePHPVersion() runs the binary without redirecting its STDERR (known-issues row 25).',
			function () use ($response) {
				$this->assertNothingOnStderr($response);
			},
			!self::isIis()
		);
	}
}
