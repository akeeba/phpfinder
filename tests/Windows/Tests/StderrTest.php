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

		$this->assertNothingOnStderr($response);
		$this->assertClean($response);
		$this->assertNull($response['result']);
	}

	public function testBinaryWhichPrintsOnStderr(): void
	{
		// PHP 8.4, whose php.ini makes it print a startup warning on STDERR, then its banner on STDOUT.
		$response = $this->probe('analyze', null, [], self::PATH_SYSTEM, [], self::STDERR_EXE);

		$this->assertNothingOnStderr($response);
		$this->assertClean($response);
		$this->assertStringStartsWith('8.4.', (string) $response['result']['version']);

		// The control: it does print the warning on STDERR (here sent to STDOUT, which IIS's runner sees too).
		$response = $this->probe('exec', null, [], self::PATH_SYSTEM, [], '"' . self::STDERR_EXE . '" -v 2>&1');

		$this->assertStringContainsString('Unable to load dynamic library', implode("\n", $response['result']['lines']));
	}

	public function testBinaryWhichCannotRun(): void
	{
		// A program which stops at once (PHP 8.4's php.exe without its DLLs), and a php.exe which is not a program at all,
		// which is never run (on PHP 7, through cmd.exe, Windows' error message went to STDERR).
		foreach ([self::NO_DLL_EXE, self::BROKEN_EXE] as $binary)
		{
			$response = $this->probe('analyze', null, [], self::PATH_SYSTEM, [], $binary);

			$this->assertNothingOnStderr($response);
			$this->assertClean($response);
			$this->assertNull($response['result']['version'], $binary);
		}
	}
}
