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
 * validateVersion and validateCli, against real Windows PHP builds: C:\PHP82 holds PHP 8.3, and C:\pf\path-cgi\php.exe
 * is PHP 8.3's php-cgi.exe (it reports `(cgi-fcgi)`).
 */
final class ValidationTest extends AbstractWindowsTestCase
{
	private const PATH_CGI_FIRST = self::PATH_CGI . ';' . self::PATH_B . ';' . self::PATH_SYSTEM;

	public function testTheFixturesAreWhatTheyClaim(): void
	{
		$this->assertAnalysis(self::MISLABELLED_82, '8.3.', true);
		$this->assertAnalysis(self::PATH_CGI_83, '8.3.', false);
		$this->assertAnalysis(self::PROGRAM_FILES_82, '8.2.', true);
	}

	public function testValidateCliSkipsPhpCgi(): void
	{
		$config = self::isolated(['useWhere' => true]);

		$this->assertSame(self::PATH_B_82, $this->bestPath(null, $config, self::PATH_CGI_FIRST));
		$this->assertNull($this->bestPath('8.3', $config, self::PATH_CGI_FIRST));
	}

	public function testWithoutValidateCliPhpCgiIsAccepted(): void
	{
		$response = $this->probe(
			'getBestPathMeta', '8.3', self::isolated(['useWhere' => true, 'validateCli' => false]), self::PATH_CGI_FIRST
		);

		$this->assertClean($response);
		$this->assertSame(self::PATH_CGI_83, $response['result']['path']);
		$this->assertFalse($response['result']['cli']);
	}

	public function testValidateVersionSkipsAMislabelledBinary(): void
	{
		$config = self::isolated(['usePHPCommonWinDirsSearch' => true]);

		// C:\PHP82 (depth 0) is tried first, but it is PHP 8.3; so is C:\PHP (8.4); then Program Files\PHP\v8.2.
		$this->assertSame(self::PROGRAM_FILES_82, $this->bestPath('8.2', $config, self::PATH_SYSTEM));
		$this->assertSame(
			self::MISLABELLED_82,
			$this->bestPath('8.2', self::isolated(['usePHPCommonWinDirsSearch' => true, 'validateVersion' => false]), self::PATH_SYSTEM)
		);
	}

	public function testVersionsMatchOnWholeComponents(): void
	{
		$config = self::isolated(['usePHPCommonWinDirsSearch' => true]);

		// C:\PHP83 is PHP 8.3.37.
		$this->assertSame(self::COMMON_VERSIONED_83, $this->bestPath('8.3.37', $config, self::PATH_SYSTEM));
		$this->assertNull($this->bestPath('8.3.3', $config, self::PATH_SYSTEM));
		$this->assertNull($this->bestPath('8.33', $config, self::PATH_SYSTEM));
	}

	private function assertAnalysis(string $binary, string $versionPrefix, bool $cli): void
	{
		$response = $this->probe('analyze', null, [], self::PATH_SYSTEM, [], $binary);

		$this->assertClean($response);
		$this->assertStringStartsWith($versionPrefix, (string) $response['result']['version'], $binary);
		$this->assertSame($cli, $response['result']['cli'], $binary);
	}
}
