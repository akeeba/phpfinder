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
	/** A real PHP 8.2 at cPanel's path for PHP 8.0, for which there is no image (fixture/fixtures.txt). */
	private const CPANEL_80_MISLABELLED = '/opt/cpanel/ea-php80/root/usr/bin/php';

	public function testCPanelPathForAZeroMinor(): void
	{
		$this->assertFixture(self::CPANEL_80_MISLABELLED, '8.2');

		// "8.0" used to be treated as the bare major "8", for which there are no cPanel paths at all.
		$response = $this->probe('getPossiblePaths', '8.0', self::isolated(['softwareSpecific' => true]));

		$this->assertNoDiagnostics($response);
		$this->assertContains(self::CPANEL_80_MISLABELLED, $response['result']);

		// Without version validation, it is the first (and only) software-specific candidate which exists.
		$this->assertSame(
			self::CPANEL_80_MISLABELLED,
			$this->bestPath('8.0', self::isolated(['softwareSpecific' => true, 'validateVersion' => false]))
		);

		// With it, a PHP 8.2 is never accepted as 8.0.
		$this->assertNull($this->bestPath('8.0', self::isolated(['softwareSpecific' => true])));
	}

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

		// Used to be skipped on Linux: the OS check was inverted.
		$this->assertSame(self::CPANEL_84, $this->bestPath('8.4', self::isolated(['softwareSpecific' => true])));
	}

	public function testCloudLinuxPathIsFound(): void
	{
		$this->assertFixture(self::CLOUDLINUX_83, '8.3');

		// Used to be skipped on Linux: the OS check was inverted.
		$this->assertSame(self::CLOUDLINUX_83, $this->bestPath('8.3', self::isolated(['softwareSpecific' => true])));
	}

	public function testSoftwareSpecificPathsAreGated(): void
	{
		$this->assertNull($this->bestPath('8.4', self::isolated(['softwareSpecific' => false])));
		$this->assertNull($this->bestPath('8.3', self::isolated(['softwareSpecific' => false])));
	}
}
