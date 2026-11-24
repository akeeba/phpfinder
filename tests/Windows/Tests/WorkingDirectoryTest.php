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
 * Nothing in the application's working directory (under IIS, the script's directory, which a site's users may be able
 * to write to) may run instead of Windows' own programs.
 *
 * Windows looks for a program given by its bare name in the current directory before the system directories: cmd.exe
 * does for `where`, `wmic`, `fsutil` and `powershell`, and so does CreateProcess() for the cmd.exe which PHP's exec()
 * starts. The working directories here hold stand-ins (provision.ps1) which leave a <name>.ran file when they run, and
 * print nothing, so that code which ran them would go on to try every tool in turn.
 */
final class WorkingDirectoryTest extends AbstractWindowsTestCase
{
	protected function tearDown(): void
	{
		self::hideTools(['wmic' => false, 'fsutil' => false]);

		parent::tearDown();
	}

	public function testToolsInTheWorkingDirectoryNeverRun(): void
	{
		$this->assertNothingRunsFrom(self::PLANTED_DIR);
	}

	public function testACmdExeInTheWorkingDirectoryNeverRuns(): void
	{
		$this->assertNothingRunsFrom(self::PLANTED_CMD_DIR);
	}

	public function testPhpRunsACmdExeFromTheWorkingDirectory(): void
	{
		// Why the library never runs anything through exec() while it can use proc_open(): PHP starts cmd.exe by its
		// bare name, and Windows finds the one in the current directory first.
		$response = $this->probe('exec', null, [], self::PATH_SYSTEM, [], 'echo pf', self::PLANTED_CMD_DIR);

		$this->assertSame(['cmd'], $response['ran']);
		$this->assertSame([], $response['result']['lines'], 'The stand-in prints nothing; the real cmd.exe prints pf');
	}

	private function assertNothingRunsFrom(string $cwd): void
	{
		// Every strategy which runs a Windows tool: `where` (with PHP in the PATH), the drive list, and running the
		// binaries found.
		$response = $this->probe(
			'getBestPath', '8.1', ['useWhere' => true], self::PATH_A . ';' . self::PATH_SYSTEM, [], null, $cwd
		);

		$this->assertSame([], $response['ran'], 'Programs in the working directory ran');
		$this->assertClean($response);

		$expected = $response['runner'] === '8.1' && $response['sapi'] === 'cli' ? $response['phpBinary'] : self::PATH_A_81;
		$this->assertSame($expected, $response['result']);

		// The drive list, from each tool in turn: wmic, fsutil, PowerShell.
		foreach ([[], ['wmic' => true], ['wmic' => true, 'fsutil' => true]] as $hidden)
		{
			self::hideTools($hidden);

			$response = $this->probe('drives', null, [], self::PATH_SYSTEM, [], null, $cwd);

			$this->assertSame([], $response['ran'], 'Programs in the working directory ran, hiding ' . json_encode($hidden));
			$this->assertSame(self::ALL_DRIVES, $response['result']);
			$this->assertClean($response);
		}
	}
}
