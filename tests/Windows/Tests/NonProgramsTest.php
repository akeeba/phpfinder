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
 * PHP's CLI binary on Windows is php.exe, a Windows program (a PE image). Neither a php.exe which is not a program,
 * wherever a search strategy finds it, nor a program under another name, may ever be returned, or run.
 */
final class NonProgramsTest extends AbstractWindowsTestCase
{
	/** Where a php.exe for PHP 8.9 (no fixture is 8.9) would be found, by each Windows search strategy. */
	private const LOCATIONS = [
		'where'                          => 'C:\\pf\\not-a-program\\php.exe',
		'common directory, versioned'    => 'C:\\PHP89\\php.exe',
		'common directory, one level'    => 'C:\\PHP\\not-a-program\\php.exe',
		'Program Files'                  => 'C:\\Program Files\\PHP\\not-a-program\\php.exe',
		'Program Files (x86), versioned' => 'C:\\Program Files (x86)\\PHP8.9\\php.exe',
		'second drive'                   => 'D:\\PHP89\\php.exe',
		'WAMPServer'                     => 'C:\\wamp64\\bin\\php\\php8.9.0\\php.exe',
		'MAMP'                           => 'C:\\MAMP\\bin\\php\\php8.9.0\\php.exe',
		'XAMPP'                          => 'D:\\xampp\\php\\php.exe',
		'Plesk'                          => 'C:\\Program Files (x86)\\Plesk\\Additional\\PleskPHP89\\php.exe',
		'Plesk, older releases'          => 'C:\\Program Files (x86)\\Plesk\\Additional\\PHP89\\php.exe',
	];

	/**
	 * What each php.exe holds. PHP's is_executable() is TRUE for the text file and for the bare MS-DOS header (it asks
	 * GetBinaryTypeW()), FALSE for the empty file.
	 */
	private static function contents(): array
	{
		return [
			'a text file'      => "This is not a program.\r\n",
			'an empty file'    => '',
			'an MS-DOS header' => 'MZ' . str_repeat("\0", 126),
		];
	}

	/** Real programs (PHP 8.1) named php.bat, php.cmd and php.com, and no php.exe (provision.ps1). */
	private const PE_NAMES = 'C:\\pf\\pe-names';

	protected function tearDown(): void
	{
		self::unplant();

		parent::tearDown();
	}

	public function testNoStrategyReturnsOrRunsAPhpExeWhichIsNotAProgram(): void
	{
		$config = ['softwareSpecific' => true];
		$path   = 'C:\\pf\\not-a-program;' . self::PATH_SYSTEM;
		$env    = ['plesk_dir' => self::PLESK_DIR];

		foreach (self::contents() as $what => $bytes)
		{
			self::plant(array_fill_keys(self::LOCATIONS, $bytes));

			$possible = $this->possiblePaths('8.9', $config, $path, $env);

			foreach (self::LOCATIONS as $strategy => $file)
			{
				$this->assertNotContainsPath($file, $possible, sprintf('%s, %s: returned', $strategy, $what));
			}

			// The controls: the same scans still find the real binaries next to those files.
			$this->assertContainsPath(self::WAMP_80, $possible, $what);
			$this->assertContainsPath(self::MAMP_86, $possible, $what);
			$this->assertContainsPath(self::XAMPP_72, $possible, $what);

			$this->assertNull($this->bestPath('8.9', $config, $path, $env), $what);

			// Whatever hands the path to what runs a binary.
			foreach (self::LOCATIONS as $strategy => $file)
			{
				$this->assertAnalyzedAsNothing($file, sprintf('%s, %s', $strategy, $what));
			}
		}
	}

	public function testProgramsUnderOtherNamesAreNeverReturnedOrRun(): void
	{
		// `where $PATH:php` lists php.bat, php.cmd and php.com: PHP 8.1's php.exe under those names.
		$config = self::isolated(['useWhere' => true]);
		$path   = self::PE_NAMES . ';' . self::PATH_SYSTEM;

		$this->assertSame([], $this->possiblePaths(null, $config, $path));
		$this->assertNull($this->bestPath(null, $config, $path));

		// Used to run any of them given directly, as is_executable() is TRUE for a program whatever its name: php.com
		// next to PHP 8.2's php.exe reported 8.2.
		foreach (['php.bat', 'php.cmd', 'php.com'] as $name)
		{
			$this->assertAnalyzedAsNothing(self::PE_NAMES . '\\' . $name, $name);
		}

		$this->assertAnalyzedAsNothing(self::PATH_EXT_COM, self::PATH_EXT_COM);

		// The control: php.com is PHP 8.1, which runs.
		$response = $this->probe('exec', null, [], self::PATH_SYSTEM, [], '"' . self::PE_NAMES . '\\php.com" -n -v');

		$this->assertStringStartsWith('PHP 8.1.', (string) ($response['result']['lines'][0] ?? ''));
	}

	private function assertAnalyzedAsNothing(string $file, string $message): void
	{
		$response = $this->probe('analyze', null, [], self::PATH_SYSTEM, [], $file);

		$this->assertClean($response);
		$this->assertSame(['version' => null, 'cli' => false], $response['result'], $message);
	}
}
