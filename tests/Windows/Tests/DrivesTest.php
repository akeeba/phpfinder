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
 * The guest has C: (system), D: (a VHD provision.ps1 attaches) and E: (an empty CD drive). A missing wmic is a PATH
 * without C:\Windows\System32\Wbem, which is exactly what PHPFinder checks for. The misbehaving tools are .bat
 * stand-ins (provision.ps1) in the PATH before the system directories: PHPFinder sees that the real tool exists, and
 * cmd.exe runs the stand-in, which comes first.
 */
final class DrivesTest extends AbstractWindowsTestCase
{
	/**
	 * Assert the drive list first, then that nothing was printed on STDERR or raised as a PHP diagnostic.
	 */
	private function assertDrives(array $expected, string $path, array $env = []): void
	{
		$response = $this->probe('drives', null, [], $path, $env);

		$this->assertSame($expected, $response['result']);
		$this->assertClean($response);
	}

	public function testDrivesFromWmic(): void
	{
		$this->assertDrives(self::ALL_DRIVES, self::PATH_SYSTEM);
	}

	public function testDrivesFromFsutilWithoutWmic(): void
	{
		$this->assertDrives(self::ALL_DRIVES, self::PATH_NO_WMIC);
	}

	public function testDrivesFromFsutilWhenWmicListsNone(): void
	{
		$this->assertDrives(self::ALL_DRIVES, self::FAKE_WMIC_NONE . ';' . self::PATH_SYSTEM);
	}

	public function testWmicErrorLinesAreNotDrives(): void
	{
		$this->assertDrives(self::ALL_DRIVES, self::FAKE_WMIC_ERROR . ';' . self::PATH_SYSTEM);
	}

	public function testFsutilErrorLinesAreNotDrives(): void
	{
		// No wmic, and an fsutil which fails: PowerShell lists the drives.
		$this->assertDrives(self::ALL_DRIVES, self::FAKE_FSUTIL_ERROR . ';' . self::PATH_NO_WMIC);
	}

	public function testDrivesFromPowerShellWhenWmicAndFsutilFail(): void
	{
		$this->assertDrives(
			self::ALL_DRIVES, self::FAKE_WMIC_NONE . ';' . self::FAKE_FSUTIL_ERROR . ';' . self::PATH_SYSTEM
		);
	}

	public function testTheSystemDriveWhenNoToolWorks(): void
	{
		// Every tool exists, and fails.
		$this->assertDrives(
			['C:'],
			self::FAKE_WMIC_NONE . ';' . self::FAKE_FSUTIL_ERROR . ';' . self::FAKE_POWERSHELL_ERROR . ';' . self::PATH_SYSTEM
		);

		// No tool in the PATH at all.
		$this->assertDrives(['C:'], self::EMPTY_DIR);
	}

	public function testTheSystemDriveIsAlwaysIncluded(): void
	{
		// A SYSTEMDRIVE which no tool lists (as SUBST drives of another logon session are not listed): kept first.
		$this->assertDrives(['Q:', 'C:', 'D:', 'E:'], self::PATH_SYSTEM, ['SYSTEMDRIVE' => 'Q:']);
		$this->assertDrives(['Q:'], self::EMPTY_DIR, ['SYSTEMDRIVE' => 'Q:']);
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
		$this->assertSame(
			self::SECOND_DRIVE_85,
			$this->bestPath('8.5', self::isolated(['usePHPCommonWinDirsSearch' => true]), self::FAKE_WMIC_NONE . ';' . self::PATH_SYSTEM)
		);
	}
}
