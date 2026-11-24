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
 * PHPFinder with proc_open() disabled (-d disable_functions=proc_open), as some hosts do: it falls back to exec(),
 * which runs every command through `cmd.exe /c "…"` (PHP 7) or `cmd.exe /s /c "…"` (PHP 8). Every CLI runner; not IIS,
 * whose php.ini the relay cannot change per request.
 */
final class ExecFallbackTest extends AbstractWindowsTestCase
{
	private const NO_PROC_OPEN = ['noProcOpen' => true];

	protected function tearDown(): void
	{
		self::hideTools(['wmic' => false, 'fsutil' => false]);

		parent::tearDown();
	}

	public function testPathsWithSpacesAndCmdMetacharactersRun(): void
	{
		$this->assertAnalyzed(self::PROGRAM_FILES_82, '8.2.');
		$this->assertAnalyzed(self::PROGRAM_FILES_X86_81, '8.1.');
		// &, (, ), ! and ^ between double quotes: literal for cmd.exe (delayed expansion is off unless the registry
		// turns it on).
		$this->assertAnalyzed(self::ODD_NO_PERCENT_85, '8.5.');
	}

	public function testPathsWithAPercentSignAreNotRun(): void
	{
		// cmd.exe expands %OS% even between double quotes: C:\pf\odd %OS%\php.exe used to run C:\pf\odd Windows_NT\php.exe,
		// another binary (here, PHP 8.3), and report ITS version for this path.
		$this->assertAnalyzed(self::ODD_EXPANDED_83, '8.3.');
		$this->assertAnalyzed(self::ODD_DEFINED_VAR_85, null);

		// An undefined %NAME% would be left alone, but whether a name is defined is not known in advance (cmd.exe
		// inherits the environment, which putenv() changes): no path with a % runs.
		$this->assertAnalyzed(self::ODD_85, null);
	}

	public function testWhere(): void
	{
		$response = $this->probe(
			'getPossiblePaths', '8.2', self::isolated(['useWhere' => true]),
			self::PATH_A . ';' . self::PATH_B . ';' . self::PATH_SYSTEM, [], null, null, self::NO_PROC_OPEN
		);

		$this->assertSamePaths([self::PATH_A_81, self::PATH_B_82, self::PATH_B_PHP82_82], $response['result']);
		$this->assertClean($response);
	}

	public function testDrivesFromEachTool(): void
	{
		// wmic, then fsutil without wmic, then PowerShell without either: Windows' own tools, through cmd.exe.
		foreach ([[], ['wmic' => true], ['wmic' => true, 'fsutil' => true]] as $hidden)
		{
			self::hideTools($hidden);

			$response = $this->probe('drives', null, [], self::PATH_SYSTEM, [], null, null, self::NO_PROC_OPEN);

			$this->assertSame(self::ALL_DRIVES, $response['result'], 'Hiding ' . json_encode($hidden));
			$this->assertClean($response);
		}
	}

	public function testToolsInTheWorkingDirectoryNeverRun(): void
	{
		// Windows' tools by their full paths. (A cmd.exe in the working directory would run instead of Windows' own:
		// PHP's exec() starts it by its bare name; see WorkingDirectoryTest. Nothing the library can do about that
		// without proc_open().)
		$response = $this->probe(
			'getBestPath', '8.1', ['useWhere' => true], self::PATH_A . ';' . self::PATH_SYSTEM, [], null,
			self::PLANTED_DIR, self::NO_PROC_OPEN
		);

		$this->assertSame([], $response['ran'], 'Programs in the working directory ran');
		$this->assertClean($response);

		$expected = $response['runner'] === '8.1' ? $response['phpBinary'] : self::PATH_A_81;
		$this->assertSame($expected, $response['result']);
	}

	public function testBinaryWhichCannotRunPrintsNothing(): void
	{
		$this->assertAnalyzed(self::BROKEN_EXE, null);
	}

	private function assertAnalyzed(string $binary, ?string $versionPrefix): void
	{
		$response = $this->probe('analyze', null, [], self::PATH_SYSTEM, [], $binary, null, self::NO_PROC_OPEN);

		$this->assertClean($response);

		if ($versionPrefix === null)
		{
			$this->assertNull($response['result']['version'], $binary);

			return;
		}

		$this->assertStringStartsWith($versionPrefix, (string) $response['result']['version'], $binary);
		$this->assertTrue($response['result']['cli'], $binary);
	}
}
