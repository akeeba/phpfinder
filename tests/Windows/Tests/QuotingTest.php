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
 * Running a binary whose path has spaces and the characters cmd.exe treats specially (quoteExecutablePath()'s Windows
 * branch). PHP 7 runs exec()'s command as `cmd.exe /c "…"`, PHP 8 as `cmd.exe /s /c "…"`, so every runner matters.
 */
final class QuotingTest extends AbstractWindowsTestCase
{
	public function testPathWithSpacesAndParentheses(): void
	{
		$this->assertRuns(self::PROGRAM_FILES_82, '8.2.');
		$this->assertRuns(self::PROGRAM_FILES_X86_81, '8.1.');
	}

	public function testPathWithCmdMetacharacters(): void
	{
		// % (of an undefined variable), &, (, ), ! and ^.
		$this->assertRuns(self::ODD_85, '8.5.');
		// !OS!, which cmd.exe would expand with delayed expansion on (the -dx runners): no cmd.exe here.
		$this->assertRuns(self::ODD_DELAYED_VAR_85, '8.5.');
	}

	public function testPathWithCmdMetacharactersFoundThroughWhere(): void
	{
		$this->assertSame(
			self::ODD_85,
			$this->bestPath('8.5', self::isolated(['useWhere' => true]), self::ODD_DIR . ';' . self::PATH_SYSTEM)
		);
	}

	public function testPathWithTheNameOfADefinedVariable(): void
	{
		// C:\pf\odd %OS%\php.exe. Used to run through cmd.exe, which expands %OS% even between double quotes, so it
		// tried to run C:\pf\odd Windows_NT\php.exe instead.
		$this->assertRuns(self::ODD_DEFINED_VAR_85, '8.5.');
	}

	private function assertRuns(string $binary, string $versionPrefix): void
	{
		$response = $this->probe('analyze', null, [], self::PATH_SYSTEM, [], $binary);

		$this->assertClean($response);
		$this->assertStringStartsWith($versionPrefix, (string) $response['result']['version'], $binary);
		$this->assertTrue($response['result']['cli'], $binary);
	}
}
