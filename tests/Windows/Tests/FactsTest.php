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
 * What the Windows tools PHPFinder runs really print, byte for byte, as PHP's exec() sees it. The unit suite's
 * fakes (tests/Unit/StrategiesTest.php) imitate these formats; if Windows changes them, these tests say so first.
 */
final class FactsTest extends AbstractWindowsTestCase
{
	/** @var array|null */
	private static $facts;

	private function facts(): array
	{
		if (self::$facts === null)
		{
			$response    = $this->probe('facts', null, [], self::PATH_A . ';' . self::PATH_B . ';' . self::PATH_SYSTEM);
			self::$facts = $response['result'];
		}

		return self::$facts;
	}

	public function testTheRunnerIsTheOneAskedFor(): void
	{
		// IIS runs the probe through FastCGI (php-cgi.exe): 64-bit PHP 8.4 for "iis", 32-bit for "iis-x86" (the /pf-x86
		// application, in an application pool with 32-bit applications enabled).
		$response = $this->probe('tools', null);

		$this->assertSame(self::isIis() ? 'cgi-fcgi' : 'cli', $response['sapi']);
		$this->assertSame(self::isX86() ? 32 : 64, $response['bits']);

		if (self::isIis())
		{
			$this->assertSame('8.4', $response['runner']);
		}
	}

	public function testWindowsToolsAreWherePHPFinderRunsThemFrom(): void
	{
		// PHPFinder runs them by these full paths, in the Windows directory (SystemRoot).
		$this->assertSame('C:\\Windows', $this->facts()['systemRoot']);
		$this->assertSame(
			['where' => true, 'wmic' => true, 'fsutil' => true, 'powershell' => true],
			$this->facts()['tools']
		);
	}

	public function testA32BitRunnerRunsUnderWow64(): void
	{
		// A 32-bit PHP on 64-bit Windows runs under WoW64: PROCESSOR_ARCHITEW6432 names the real architecture, and only a
		// 32-bit process sees C:\\Windows\\Sysnative, the real System32, while it sees SysWOW64 at System32.
		$this->assertSame(self::isX86() ? 32 : 64, $this->facts()['bits']);
		$this->assertSame(self::isX86() ? 'AMD64' : false, $this->facts()['architew6432']);
		$this->assertSame(self::isX86(), $this->facts()['sysnative']);
	}

	public function testEveryToolHasA32BitCopy(): void
	{
		// What a 32-bit runner runs at C:\\Windows\\System32\\…: the guest (Server Core with WoW64-Support) has a 32-bit
		// copy of every tool PHPFinder runs, cmd.exe too (checked by hand).
		$this->assertSame(
			['where' => true, 'wmic' => true, 'fsutil' => true, 'powershell' => true],
			$this->facts()['toolsWow64']
		);
	}

	public function testWmicIsInTheSystemPath(): void
	{
		$this->assertSame('c:\\windows\\system32\\wbem\\wmic.exe', strtolower($this->facts()['wmicPath']));
	}

	public function testWmicPrintsOneTrimmedDriveLetterPerLine(): void
	{
		$wmic = $this->facts()['wmic'];

		// A header, one drive per line, and a blank line at the end. ASCII through a pipe, not UTF-16, and exec() has
		// already stripped the padding and the CRs.
		$this->assertSame(0, $wmic['status']);
		$this->assertSame(array_merge(['Name'], self::ALL_DRIVES, ['']), $wmic['lines']);
		$this->assertStringNotContainsString('00', implode(' ', $wmic['hex']), 'wmic printed NUL bytes (UTF-16)');
	}

	public function testWmicPadsItsColumnAndEndsLinesWithCrCrLf(): void
	{
		// Before exec() trims them: "Name  ", "C:    ", each followed by \r\r\n.
		$raw = hex2bin($this->facts()['wmicRaw']['stdout']);

		$this->assertSame("Name  \r\r\nC:    \r\r\nD:    \r\r\nE:    \r\r\n\r\r\n", $raw);
	}

	public function testWmicWithoutInstancesPrintsOnlyBlankLinesOnStdout(): void
	{
		$none = $this->facts()['wmicNone'];

		$this->assertSame('', trim(hex2bin($none['stdout'])), 'Only blank lines on STDOUT');
		$this->assertStringContainsString('No Instance(s) Available.', hex2bin($none['stderr']));
	}

	public function testFsutilListsTheDrivesWithBackslashes(): void
	{
		$fsutil = $this->facts()['fsutil'];
		$lines  = array_values(array_filter($fsutil['lines'], 'strlen'));

		// In an SSH session a blank line comes first; under IIS it does not. Never more than the one "Drives:" line.
		$this->assertSame(0, $fsutil['status']);
		$this->assertSame(['Drives: C:\\ D:\\ E:\\'], $lines);
		$this->assertSame('Drives: C:\\ D:\\ E:\\', $fsutil['return']);
	}

	public function testWhereListsEveryMatchInPathOrder(): void
	{
		$where = $this->facts()['where'];

		$this->assertSame(0, $where['status']);
		$this->assertSamePaths([self::PATH_A_81, self::PATH_B_82], $where['lines']);
		$this->assertStringEndsWith("\r\n", hex2bin($this->facts()['whereRaw']['stdout']), 'CRLF line endings');
	}

	public function testWhereWithoutAMatchPrintsOnStderrOnly(): void
	{
		$none = $this->facts()['whereNone'];

		$this->assertSame('', hex2bin($none['stdout']));
		$this->assertSame(
			"INFO: Could not find files for the given pattern(s).\r\n",
			hex2bin($none['stderr'])
		);
	}

	public function testWhereListsEveryPathextMatchAndTheBareName(): void
	{
		// C:\pf\path-ext holds php.exe, php.bat, php.cmd, php.com and an extensionless php. `where` lists them all, in the
		// directory's (alphabetical) order, not PATHEXT's (.COM;.EXE;.BAT;.CMD;…).
		$where = $this->facts()['whereExt'];

		$this->assertSame(0, $where['status']);
		$this->assertSamePaths(
			[
				self::PATH_EXT . '\\php', self::PATH_EXT . '\\php.bat', self::PATH_EXT . '\\php.cmd',
				self::PATH_EXT . '\\php.com', self::PATH_EXT_82,
			],
			$where['lines']
		);
	}

	public function testIsExecutableIsNotOnlyTrueForAProgram(): void
	{
		// PHP's is_executable() on Windows asks GetBinaryTypeW(): TRUE for a program file, whatever its name (a copy of
		// php.exe named php.com, php.bat, php.cmd or php), FALSE for a .bat or .cmd script, an extensionless script and an
		// empty php.exe, but TRUE for a text file named php.exe too. Hence the PE header check (isWindowsProgramFile()).
		$this->assertSame(
			['php.exe' => true, 'php.bat' => false, 'php.cmd' => false, 'php.com' => true, 'php' => false],
			$this->facts()['isExecutable']
		);
		$this->assertSame(
			['text php.exe' => true, 'empty php.exe' => false, 'PE php.bat' => true, 'PE php.cmd' => true],
			$this->facts()['isExecutableOther']
		);
	}

	public function testHowAProgramNamedLikeAScriptRuns(): void
	{
		// PHP 8.1's php.exe copied as php.bat, php.cmd and php.com. Started by Windows itself (CreateProcessW(), what
		// proc_open() with bypass_shell calls), each runs as the program it is, whatever its name: %ComSpec% plays no part
		// (checked by hand: pointed at a stand-in, it did not run; it does for a real .bat). Started by cmd.exe, which goes
		// by the extension, php.com runs, while php.bat and php.cmd are read as batch scripts: cmd.exe echoes the first
		// "line" of the binary and fails to run it as a command.
		foreach ($this->facts()['peNames'] as $name => $ran)
		{
			$this->assertSame(0, $ran['direct']['status'], $name);
			$this->assertStringStartsWith('PHP 8.1.', $ran['direct']['stdout'], $name);

			if ($name === 'php.com')
			{
				$this->assertSame(0, $ran['cmd']['status'], $name);
				$this->assertStringStartsWith('PHP 8.1.', $ran['cmd']['stdout'], $name);

				continue;
			}

			$this->assertSame(1, $ran['cmd']['status'], $name);
			$this->assertStringStartsNotWith('PHP ', $ran['cmd']['stdout'], $name);

			if (self::isIis())
			{
				// Under IIS (fastcgi.impersonate=1), a cmd.exe which proc_open() started runs no batch script at all, not even a
				// real one, and prints nothing (see README.md).
				$this->assertSame('', $ran['cmd']['stderr'], $name);

				continue;
			}

			$this->assertStringStartsWith("'MZ", $ran['cmd']['stderr'], $name);
			$this->assertStringContainsString('is not recognized as an internal or external command', $ran['cmd']['stderr'], $name);
		}
	}

	public function testTheSystemDriveVariable(): void
	{
		$this->assertSame('C:', $this->facts()['systemDrive']);
	}
}
