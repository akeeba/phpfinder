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
 * MAMP on macOS, at its real location, /Applications/MAMP/bin/php/php<X.Y.Z>/bin/php, which a unit test on
 * a Mac must not touch. The container holds a real PHP there; the probe makes PHPFinder behave as on macOS.
 */
final class MampTest extends AbstractIntegrationTestCase
{
	public function testMampIsFoundOnMacOs(): void
	{
		$this->assertFixture(self::MAMP_MAC_83, '8.3');

		// Without the OS-specific searches: on macOS the fixed UNIX paths would find /opt/bin/php83 first.
		$config = self::isolated(['softwareSpecific' => true, 'osSpecific' => false]);

		// Used to scan /Applications/MAMP/bin/php/ one level deep, which never reaches <version>/bin/php.
		$response = $this->probe('getPossiblePaths', '8.3', $config, null, 'Darwin');

		$this->assertNoDiagnostics($response);
		$this->assertContains(self::MAMP_MAC_83, $response['result']);

		$response = $this->probe('getBestPath', '8.3', $config, null, 'Darwin');

		$this->assertNoDiagnostics($response);
		$this->assertSame(self::MAMP_MAC_83, $response['result']);
	}

	public function testMampIsOnlySearchedOnMacOsWithSoftwareSpecific(): void
	{
		$this->assertNotContains(
			self::MAMP_MAC_83,
			$this->probe('getPossiblePaths', '8.3', self::isolated(['softwareSpecific' => true]))['result'],
			'Linux'
		);
		$this->assertNotContains(
			self::MAMP_MAC_83,
			$this->probe('getPossiblePaths', '8.3', self::isolated(['softwareSpecific' => false]), null, 'Darwin')['result'],
			'macOS, softwareSpecific off'
		);
	}
}
