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
 * Each OS search strategy, alone, with the real system tools of a Debian container: which, the fixed UNIX
 * paths, whereis and update-alternatives. Each fixture is placed where only the strategy under test can
 * find it, so switching that strategy off must make the result NULL.
 */
final class StrategiesTest extends AbstractIntegrationTestCase
{
	public function testWhichFindsABinaryInThePath(): void
	{
		$this->assertFixture(self::WHICH_82, '8.2');

		$response = $this->probe('getBestPathMeta', '8.2', self::isolated(['useWhich' => true]), self::PATH_WITH_WHICH_DIR);

		$this->assertNoDiagnostics($response);
		$this->assertSame(self::WHICH_82, $response['result']['path']);
		$this->assertStringStartsWith('8.2.', $response['result']['version']);
		$this->assertTrue($response['result']['cli']);
	}

	public function testWhichOnlySearchesThePath(): void
	{
		$this->assertNull($this->bestPath('8.2', self::isolated(['useWhich' => true]), self::PATH_DEFAULT));
	}

	public function testWhichIsGated(): void
	{
		$this->assertNull($this->bestPath('8.2', self::isolated(['useWhich' => false]), self::PATH_WITH_WHICH_DIR));
		$this->assertNull(
			$this->bestPath('8.2', self::isolated(['useWhich' => true, 'osSpecific' => false]), self::PATH_WITH_WHICH_DIR)
		);
	}

	public function testFixedUnixPathsFindABinaryOutsideThePath(): void
	{
		$this->assertFixture(self::UNIX_83, '8.3');

		$this->assertSame(self::UNIX_83, $this->bestPath('8.3', self::isolated(['useWhich' => true])));
		$this->assertContains(
			self::UNIX_83,
			$this->probe('getPossiblePaths', '8.3', self::isolated(['useWhich' => true]))['result']
		);

		// The fixed paths are switched off together with `which`.
		$this->assertNull($this->bestPath('8.3', self::isolated(['useWhich' => false])));
	}

	public function testWhereIsFindsABinaryOnlyItSearches(): void
	{
		$this->assertFixture(self::WHEREIS_82, '8.2');

		$config = self::isolated(['useWhereIs' => true]);

		// /usr/local/games is searched by whereis alone.
		$this->assertContains(self::WHEREIS_82, $this->probe('getPossiblePaths', '8.2', $config)['result']);

		$response = $this->probe('getBestPathMeta', '8.2', $config);

		$this->assertNoDiagnostics($response);
		$this->assertStringStartsWith('8.2.', $response['result']['version']);
		$this->assertTrue($response['result']['cli']);

		// Which 8.2 wins depends on the util-linux version. util-linux 2.36 (Debian 11: the PHP 7.4 image)
		// lists /usr/bin/php8.2 for `whereis -b php`, the first name tried. Newer ones do not, and only
		// find /usr/local/games/php82, for `whereis -b php82`.
		$this->assertContains($response['result']['path'], [self::WHEREIS_82, self::DOTTED_82]);
	}

	public function testWhereIsIsGated(): void
	{
		$this->assertNull($this->bestPath('8.2', self::isolated(['useWhereIs' => false])));
		$this->assertNull($this->bestPath('8.2', self::isolated(['useWhereIs' => true, 'osSpecific' => false])));
	}

	public function testUpdateAlternativesFindsARegisteredBinary(): void
	{
		$this->assertFixture(self::ALTERNATIVES_84, '8.4');

		$listed = self::dockerCompose(['exec', '-T', 'php', 'update-alternatives', '--list', 'php']);
		$this->assertSame('/opt/pf-ua/php84', trim($listed['output']), 'The update-alternatives fixture is in place');

		$this->expectKnownBug(
			'pathsUsingUpdateAlternatives() returns [] when PHP_OS_FAMILY IS Linux or BSD (the in_array() check '
			. 'lacks its "!"), so it never runs on Debian, the only place update-alternatives exists.',
			function () {
				$config = self::isolated(['useUpdateAlternatives' => true]);

				$this->assertContains(self::ALTERNATIVES_84, $this->probe('getPossiblePaths', '8.4', $config)['result']);
				$this->assertSame(self::ALTERNATIVES_84, $this->bestPath('8.4', $config));
			}
		);
	}

	public function testUpdateAlternativesIsGated(): void
	{
		$this->assertNull($this->bestPath('8.4', self::isolated(['useUpdateAlternatives' => false])));
	}
}
