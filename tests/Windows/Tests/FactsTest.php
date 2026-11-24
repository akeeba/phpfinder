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

	public function testWindowsToolsAreWherePHPFinderRunsThemFrom(): void
	{
		// PHPFinder runs them by these full paths, in the Windows directory (SystemRoot).
		$this->assertSame('C:\\Windows', $this->facts()['systemRoot']);
		$this->assertSame(
			['where' => true, 'wmic' => true, 'fsutil' => true, 'powershell' => true],
			$this->facts()['tools']
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

	public function testTheSystemDriveVariable(): void
	{
		$this->assertSame('C:', $this->facts()['systemDrive']);
	}
}
