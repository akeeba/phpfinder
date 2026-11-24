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
		// &, (, ) and ^ between double quotes: literal for cmd.exe, with delayed expansion on too.
		$this->assertAnalyzed(self::ODD_NO_BANG_85, '8.5.');
	}

	public function testDelayedExpansionIsOnOnlyForItsRunners(): void
	{
		// The control for the test below: a -dx runner's cmd.exe expands !OS!, the others' leave it alone.
		$response = $this->probe('exec', null, [], self::PATH_SYSTEM, [], 'echo !OS!', null, self::NO_PROC_OPEN);

		$this->assertSame([self::isDelayedExpansion() ? 'Windows_NT' : '!OS!'], $response['result']['lines']);
	}

	public function testPathsWithAnExclamationMarkAreNotRun(): void
	{
		// With cmd.exe's delayed expansion on (the registry's DelayedExpansion), cmd.exe expands !OS! even between
		// double quotes: C:\pf\odd !OS!\php.exe used to run C:\pf\odd Windows_NT\php.exe, another binary (PHP 8.3),
		// and report ITS version for this path; and a lone ! is dropped, so C:\pf\odd & (x) !bang ^caret\php.exe ran
		// nothing. Whether delayed expansion is on is not known in advance: no path with a ! runs through cmd.exe.
		$this->assertAnalyzed(self::ODD_EXPANDED_83, '8.3.');
		$this->assertAnalyzed(self::ODD_DELAYED_VAR_85, null);
		$this->assertAnalyzed(self::ODD_NO_PERCENT_85, null);
	}

	public function testWindowsToolsInADirectoryWithAnExclamationMarkAreNotRun(): void
	{
		// Windows' tools run through cmd.exe too: the tools' wrapper with proc_open(), exec() without. With delayed
		// expansion on, the `where` of C:\pf\fakewin\dx !OS! used to be C:\pf\fakewin\dx Windows_NT's, which finds
		// PHP 8.1 and leaves where.ran in the working directory, either way.
		foreach ([[], self::NO_PROC_OPEN] as $options)
		{
			$response = $this->probe(
				'getPossiblePaths', '8.1', self::isolated(['useWhere' => true]), self::PATH_SYSTEM, [], null,
				self::WIN_DX_EXPANDED . '\\System32', $options + ['windowsDirectory' => self::WIN_DX_DELAYED_VAR]
			);

			$this->assertSame([], $response['result'], json_encode($options));
			$this->assertSame([], $response['ran'], 'Another `where` ran ' . json_encode($options));
			$this->assertClean($response);
		}
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
			$this->hideToolsAndCheck($hidden);

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
		// cmd.exe runs these: a program which stops at once, a php.exe which is not a program (never run), and a PHP
		// which prints a warning on STDERR (StderrTest).
		$this->assertAnalyzed(self::NO_DLL_EXE, null);
		$this->assertAnalyzed(self::BROKEN_EXE, null);
		$this->assertAnalyzed(self::STDERR_EXE, '8.4.');
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
