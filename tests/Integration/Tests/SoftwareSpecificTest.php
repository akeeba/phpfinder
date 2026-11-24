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
 * The software-specific paths (Plesk, cPanel, CloudLinux) on Linux, plus the Debian-style phpX.Y name
 * which, as things stand, only the Plesk candidates know about.
 */
final class SoftwareSpecificTest extends AbstractIntegrationTestCase
{
	public function testPleskCandidatesFindTheDottedName(): void
	{
		$this->assertFixture(self::DOTTED_82, '8.2');

		$this->assertSame(self::DOTTED_82, $this->bestPath('8.2', self::isolated(['softwareSpecific' => true])));
		$this->assertNull($this->bestPath('8.2', self::isolated(['softwareSpecific' => false])));
	}

	public function testTheDottedNameIsFoundWithoutSoftwareSpecificPaths(): void
	{
		$this->expectKnownBug(
			'getBinaryNamesForVersion() spells the alternative name php8:2 instead of php8.2, so `which` never '
			. 'looks for the /usr/bin/php8.2 Debian and Ubuntu install; only the Plesk candidate list happens to '
			. 'contain that path.',
			function () {
				$this->assertSame(self::DOTTED_82, $this->bestPath('8.2', self::isolated(['useWhich' => true])));
			}
		);
	}

	public function testCPanelPathIsFound(): void
	{
		$this->assertFixture(self::CPANEL_84, '8.4');

		$this->expectKnownBug(
			'pathsCPanel() returns [] when PHP_OS_FAMILY IS Linux (the in_array() check lacks its "!"), so the '
			. 'cPanel EasyApache path is never tried on the only OS cPanel runs on.',
			function () {
				$this->assertSame(self::CPANEL_84, $this->bestPath('8.4', self::isolated(['softwareSpecific' => true])));
			}
		);
	}

	public function testCloudLinuxPathIsFound(): void
	{
		$this->assertFixture(self::CLOUDLINUX_83, '8.3');

		$this->expectKnownBug(
			'pathsCloudLinux() returns [] when PHP_OS_FAMILY IS Linux (the in_array() check lacks its "!"), so '
			. 'the CloudLinux alt-php path is never tried on the only OS CloudLinux is.',
			function () {
				$this->assertSame(self::CLOUDLINUX_83, $this->bestPath('8.3', self::isolated(['softwareSpecific' => true])));
			}
		);
	}

	public function testSoftwareSpecificPathsAreGated(): void
	{
		$this->assertNull($this->bestPath('8.4', self::isolated(['softwareSpecific' => false])));
		$this->assertNull($this->bestPath('8.3', self::isolated(['softwareSpecific' => false])));
	}
}
