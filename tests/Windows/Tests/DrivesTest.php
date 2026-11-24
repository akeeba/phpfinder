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
 * The Windows drive list (getWindowsDrives()), which the common-directory, MAMP, WAMPServer and XAMPP searches scan.
 *
 * The guest has C: (system), D: (a VHD provision.ps1 attaches) and E: (an empty CD drive). PHPFinder runs wmic, fsutil
 * and PowerShell from the Windows directory, by their full paths. A Windows without wmic (Windows 11 24H2, Windows
 * Server 2025), or without fsutil, is the real one with those renamed for the duration of a test (hideTools()). The
 * misbehaving tools are stand-ins (provision.ps1) in a Windows directory of their own, which the test gives to the
 * library instead of C:\Windows.
 */
final class DrivesTest extends AbstractWindowsTestCase
{
	protected function tearDown(): void
	{
		self::hideTools(['wmic' => false, 'fsutil' => false]);

		parent::tearDown();
	}

	/**
	 * Assert the drive list first, then that nothing was printed on STDERR or raised as a PHP diagnostic.
	 */
	private function assertDrives(array $expected, ?string $windowsDirectory, array $env = [], array $options = []): void
	{
		$response = $this->probe(
			'drives', null, [], self::PATH_SYSTEM, $env, null, null,
			array_merge(['windowsDirectory' => $windowsDirectory], $options)
		);

		$this->assertSame($expected, $response['result']);
		$this->assertClean($response);
	}

	public function testDrivesFromWmic(): void
	{
		$this->assertDrives(self::ALL_DRIVES, null);
	}

	public function testDrivesFromFsutilWithoutWmic(): void
	{
		// Windows' own fsutil, with WMIC.exe gone, as on Windows 11 24H2 and Windows Server 2025.
		self::hideTools(['wmic' => true]);

		$this->assertDrives(self::ALL_DRIVES, null);
	}

	public function testDrivesFromPowerShellWithoutWmicAndFsutil(): void
	{
		// Windows' own PowerShell.
		self::hideTools(['wmic' => true, 'fsutil' => true]);

		$this->assertDrives(self::ALL_DRIVES, null);
	}

	public function testDrivesFromFsutilWhenWmicListsNone(): void
	{
		$this->assertDrives(self::ALL_DRIVES, self::WIN_WMIC_NONE);
	}

	public function testWmicErrorLinesAreNotDrives(): void
	{
		$this->assertDrives(self::ALL_DRIVES, self::WIN_WMIC_ERROR);
	}

	public function testFsutilErrorLinesAreNotDrives(): void
	{
		// No wmic, and an fsutil which fails: PowerShell lists the drives.
		$this->assertDrives(self::ALL_DRIVES, self::WIN_FSUTIL_ERROR);
	}

	public function testTheSystemDriveWhenNoToolWorks(): void
	{
		// Every tool exists, and fails.
		$this->assertDrives(['C:'], self::WIN_ALL_FAIL);

		// No tool at all.
		$this->assertDrives(['C:'], self::WIN_EMPTY);
	}

	public function testTheToolsDoNotDependOnThePath(): void
	{
		// They run by their full paths: a PATH without Windows' own directories makes no difference.
		$response = $this->probe('drives', null, [], self::EMPTY_PATH);

		$this->assertSame(self::ALL_DRIVES, $response['result']);
		$this->assertClean($response);
	}

	public function testTheSystemDriveIsAlwaysIncluded(): void
	{
		// A SYSTEMDRIVE which no tool lists (as SUBST drives of another logon session are not listed): kept first.
		$this->assertDrives(['Q:', 'C:', 'D:', 'E:'], null, ['SYSTEMDRIVE' => 'Q:']);
		$this->assertDrives(['Q:'], self::WIN_EMPTY, ['SYSTEMDRIVE' => 'Q:']);
	}

	public function testDrivesWithTheProcessEnvironment(): void
	{
		// No PATH given: the one the probe inherited (the SSH session's, or the IIS site's).
		$response = $this->probe('drives', null, []);

		$this->assertSame(self::ALL_DRIVES, $response['result']);
		$this->assertClean($response);
	}

	public function testTheSecondDriveIsSearchedWhenWmicListsNone(): void
	{
		// End to end: the drive-based searches find nothing on D: when the drive list is wrong.
		$response = $this->probe(
			'getBestPath', '8.5', self::isolated(['usePHPCommonWinDirsSearch' => true]), self::PATH_SYSTEM, [], null, null,
			['windowsDirectory' => self::WIN_WMIC_NONE]
		);

		$this->assertSame(self::SECOND_DRIVE_85, $response['result']);
		$this->assertClean($response);
	}

	public function testTheSecondDriveIsSearchedWithoutWmic(): void
	{
		self::hideTools(['wmic' => true]);

		$this->assertSame(
			self::SECOND_DRIVE_85,
			$this->bestPath('8.5', self::isolated(['usePHPCommonWinDirsSearch' => true]), self::PATH_SYSTEM)
		);
	}
}
