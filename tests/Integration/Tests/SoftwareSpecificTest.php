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
 * The software-specific paths (Plesk, cPanel, CloudLinux) on Linux, plus the Debian-style phpX.Y name,
 * which the Plesk candidates and the generic binary names both cover.
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
		// Used to be searched for as php8:2, so only the Plesk candidate list found /usr/bin/php8.2.
		$this->assertSame(self::DOTTED_82, $this->bestPath('8.2', self::isolated(['useWhich' => true])));
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
