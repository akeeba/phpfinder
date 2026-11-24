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
 * Homebrew on Linux (Linuxbrew): through a fake `brew` whose prefix is /opt/pf-brew, and through the default
 * prefix, /home/linuxbrew/.linuxbrew, without `brew`. The fake only answers `brew --prefix [<formula>]`; the
 * PHP binaries under both prefixes are real (fixture/fixtures.txt, docker/provision.sh).
 *
 * The software-specific candidates which come before Homebrew's (cPanel, CloudLinux, Plesk) win getBestPath()
 * for every fixture version, so these tests look at getPossiblePaths().
 */
final class HomebrewTest extends AbstractIntegrationTestCase
{
	/** The fake brew's directory, in the PATH only when a test puts it there. */
	private const PATH_WITH_BREW = '/opt/pf-brew/bin:' . self::PATH_DEFAULT;

	/** The php@8.3 formula's binary under the fake brew's prefix, which only `brew --prefix` reveals. */
	private const BREW_83 = '/opt/pf-brew/opt/php@8.3/bin/php';

	/** The php@8.4 formula's binary under Linuxbrew's default prefix. */
	private const LINUXBREW_84 = '/home/linuxbrew/.linuxbrew/opt/php@8.4/bin/php';

	public function testBrewIsAskedForTheVersionedFormula(): void
	{
		$this->assertFixture(self::BREW_83, '8.3');

		// Used to ask only for the "php" formula, and to return the prefix DIRECTORY, not the binary in it.
		$response = $this->probe(
			'getPossiblePaths', '8.3', self::isolated(['softwareSpecific' => true]), self::PATH_WITH_BREW
		);

		$this->assertNoDiagnostics($response);
		$this->assertContains(self::BREW_83, $response['result']);
		$this->assertNotContains('/opt/pf-brew/opt/php@8.3', $response['result']);

		// Without `brew` in the PATH nothing can know about /opt/pf-brew.
		$this->assertNotContains(
			self::BREW_83,
			$this->probe('getPossiblePaths', '8.3', self::isolated(['softwareSpecific' => true]), self::PATH_DEFAULT)['result']
		);
	}

	public function testTheDefaultLinuxPrefixIsSearchedWithoutBrew(): void
	{
		$this->assertFixture(self::LINUXBREW_84, '8.4');

		$response = $this->probe(
			'getPossiblePaths', '8.4', self::isolated(['softwareSpecific' => true]), self::PATH_DEFAULT
		);

		$this->assertNoDiagnostics($response);
		$this->assertContains(self::LINUXBREW_84, $response['result']);
	}

	public function testHomebrewIsGatedBySoftwareSpecific(): void
	{
		self::dockerCompose(['exec', '-T', 'php', 'rm', '-f', '/tmp/pf-brew.log']);

		// Used to run on Linux and macOS even with softwareSpecific off: the guard used && instead of ||.
		foreach (['8.3' => self::BREW_83, '8.4' => self::LINUXBREW_84] as $version => $binary)
		{
			$this->assertNotContains(
				$binary,
				$this->probe(
					'getPossiblePaths', (string) $version, self::isolated(['softwareSpecific' => false]), self::PATH_WITH_BREW
				)['result'],
				"PHP $version"
			);
		}

		$this->assertNotSame(
			0,
			self::dockerCompose(['exec', '-T', 'php', 'test', '-e', '/tmp/pf-brew.log'])['status'],
			'brew was run with softwareSpecific off'
		);
	}
}
