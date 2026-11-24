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
 * validateVersion, validateCli and extendedBinaryNameSearch, against real binaries which lie about what
 * they are by their name.
 */
final class ValidationTest extends AbstractIntegrationTestCase
{
	public function testValidateVersionSkipsAMislabelledBinary(): void
	{
		// /opt/bin/php84 is really PHP 8.2, and comes before /usr/local/bin/php84-cli in candidate order.
		$this->assertFixture(self::MISLABELLED_84, '8.2');
		$this->assertFixture(self::EXTENDED_84, '8.4');

		$response = $this->probe('getBestPathMeta', '8.4', self::isolated(['useWhich' => true]));

		$this->assertNoDiagnostics($response);
		$this->assertSame(self::EXTENDED_84, $response['result']['path']);
		$this->assertStringStartsWith('8.4.', $response['result']['version']);
	}

	public function testWithoutValidateVersionTheFirstCliWinsWhateverItsVersion(): void
	{
		$response = $this->probe('getBestPathMeta', '8.4', self::isolated(['useWhich' => true, 'validateVersion' => false]));

		$this->assertNoDiagnostics($response);

		// The generic names come first: /usr/local/bin/php, the runner, which is not 8.4.
		$this->assertSame(self::RUNNER, $response['result']['path']);
		$this->assertStringStartsNotWith('8.4.', $response['result']['version']);
	}

	public function testValidateCliSkipsANonCliSapi(): void
	{
		// /usr/bin/php83 is PHP-FPM 8.3, and comes before /opt/bin/php83 (the 8.3 CLI) in candidate order.
		$this->assertFixture(self::FPM_83, '8.3', 'fpm-fcgi');

		$this->assertSame(self::UNIX_83, $this->bestPath('8.3', self::isolated(['useWhich' => true])));

		$response = $this->probe('getBestPathMeta', '8.3', self::isolated(['useWhich' => true, 'validateCli' => false]));

		$this->assertNoDiagnostics($response);
		$this->assertSame(self::FPM_83, $response['result']['path']);
		$this->assertFalse($response['result']['cli']);
		$this->assertStringStartsWith('8.3.', $response['result']['version']);
	}

	public function testExtendedBinaryNamesCanBeSwitchedOff(): void
	{
		$this->assertNull(
			$this->bestPath('8.4', self::isolated(['useWhich' => true, 'extendedBinaryNameSearch' => false]))
		);
		$this->assertNotContains(
			self::EXTENDED_84,
			$this->probe('getPossiblePaths', '8.4', self::isolated(['useWhich' => true, 'extendedBinaryNameSearch' => false]))['result']
		);
	}
}
