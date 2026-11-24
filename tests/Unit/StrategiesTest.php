<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Unit;

use Akeeba\PHPFinder\PHPFinder;
use Akeeba\PHPFinder\Tests\Support\FakeSystem;
use Akeeba\PHPFinder\Tests\Support\KnownBug;
use PHPUnit\Framework\TestCase;

/**
 * The search strategies which shell out to a system tool, against fake `whereis`, `update-alternatives` and
 * `brew` scripts placed first in the PATH. The integration suite runs the real tools in a Linux container.
 */
final class StrategiesTest extends TestCase
{
	use FakeSystem;
	use KnownBug;

	protected function setUp(): void
	{
		$this->setUpFakeSystem();
	}

	protected function tearDown(): void
	{
		$this->tearDownFakeSystem();
	}

	public function testWhereIsFindsBinaries(): void
	{
		$php = $this->fakeWhereIs();

		[$result, $diagnostics] = $this->withDiagnostics(function () {
			return $this->finder(['useWhereIs' => true])->getBestPath('5.9');
		});

		$this->assertSame($php, $result);
		$this->assertSame([], $diagnostics);
	}

	public function testWhereIsWithoutTheUsualOutput(): void
	{
		// The PHP 5.9 binary is in a directory whose name has a colon, which is legal on macOS and Linux.
		$php = $this->fakePhp('wi:x/php59', '5.9.1');

		// A `whereis` which prints nothing at all for `php`, and a line without the "name:" prefix for php5.9.
		$this->fakeExecutable(
			'bin/whereis',
			"[ \"\$1\" = '-b' ] || exit 2\n"
			. "case \"\$2\" in\n"
			. "  php) ;;\n"
			. "  php5.9) echo 'whereis -b is not supported' ;;\n"
			. "  php59) echo \"\$2: " . $php . "\" ;;\n"
			. "  *) echo \"\$2:\" ;;\n"
			. "esac\n"
		);

		// Used to split each line with `[, $rawList] = explode(':', $result)`: "Undefined array key 1" (an
		// "Undefined offset: 1" notice on PHP 7) without a colon, and a path cut at its first colon.
		[$result, $diagnostics] = $this->withDiagnostics(function () {
			return $this->finder(['useWhereIs' => true])->getBestPath('5.9');
		});

		$this->assertSame([], $diagnostics);
		$this->assertSame($php, $result);
	}

	public function testWhereIsIsGated(): void
	{
		$php = $this->fakeWhereIs();

		$this->assertNotContains($php, $this->finder(['useWhereIs' => false])->getPossiblePaths('5.9'));
		$this->assertNotContains($php, $this->finder(['useWhereIs' => true, 'osSpecific' => false])->getPossiblePaths('5.9'));
		$this->assertNull($this->finder(['useWhereIs' => false])->getBestPath('5.9'));
	}

	public function testUpdateAlternativesFindsBinaries(): void
	{
		if (!in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD'], true))
		{
			$this->markTestSkipped('update-alternatives is only used on Linux and BSD (Debian GNU/kFreeBSD).');
		}

		$php = $this->fakePhp('ua/php59', '5.9.1');
		$fpm = $this->fakePhp('ua/php-fpm59', '5.9.1', 'fpm-fcgi');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"[ \"\$1\" = '--list' ] && [ \"\$2\" = 'php' ] || exit 2\n"
			. "echo '" . $php . "'\n"
			. "echo '" . $fpm . "'\n"
		);

		// Used to return [] on Linux and BSD: the OS check was inverted.
		$finder = $this->finder(['useUpdateAlternatives' => true]);
		$paths  = $finder->getPossiblePaths('5.9');

		$this->assertContains($php, $paths);
		$this->assertNotContains($fpm, $paths, 'Only names for the requested version are kept');
		$this->assertSame($php, $finder->getBestPath('5.9'));
	}

	public function testUpdateAlternativesOnlyRunsOnLinuxAndBsd(): void
	{
		if (in_array(strtoupper(PHP_OS_FAMILY), ['LINUX', 'BSD'], true))
		{
			$this->markTestSkipped('Not applicable on Linux and BSD.');
		}

		$php = $this->fakePhp('ua/php59', '5.9.1');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"echo \"\$*\" >> '" . $this->root() . "/update-alternatives.log'\n"
			. "echo '" . $php . "'\n"
		);

		// Used to run everywhere BUT Linux and BSD (the check was inverted).
		$paths = $this->finder(['useUpdateAlternatives' => true])->getPossiblePaths('5.9');

		$this->assertNotContains($php, $paths);
		$this->assertFalse(is_file($this->root() . '/update-alternatives.log'), 'update-alternatives was run');
	}

	public function testUpdateAlternativesMatchesTheWholeFileName(): void
	{
		// Simulated, so that it also runs on macOS: update-alternatives itself is a fake anyway.
		$php   = $this->fakePhp('ua/php59', '5.9.1');
		$other = $this->fakePhp('ua/x\\php59', '5.9.1');

		$this->fakeExecutable(
			'bin/update-alternatives',
			"[ \"\$1\" = '--list' ] && [ \"\$2\" = 'php' ] || exit 2\n"
			. "printf '%s\\n' '" . $php . "' '" . $other . "'\n"
		);

		// A backslash is an ordinary file name character on Linux: x\php59 is not named php59. It used to be
		// accepted, by a second check which turned backslashes into slashes and looked at the last part again.
		$finder = $this->simulateOs($this->finder(['useUpdateAlternatives' => true]), 'Linux');

		$this->assertSame([$php], $this->callPrivate($finder, 'pathsUsingUpdateAlternatives', ['5.9']));
	}

	public function testUpdateAlternativesIsGated(): void
	{
		$php = $this->fakePhp('ua/php59', '5.9.1');

		$this->fakeExecutable('bin/update-alternatives', "echo '" . $php . "'\n");

		$this->assertNotContains($php, $this->finder(['useUpdateAlternatives' => false])->getPossiblePaths('5.9'));
		$this->assertNotContains(
			$php, $this->finder(['useUpdateAlternatives' => true, 'osSpecific' => false])->getPossiblePaths('5.9')
		);
	}

	public function testWhereReturnsEveryBinaryItLists(): void
	{
		$first  = $this->fakePhp('w/one/php.exe', '5.9.1');
		$second = $this->fakePhp('w/two/php.exe', '5.9.2');
		$third  = $this->fakePhp('w/three/php59.exe', '5.9.3');

		// Like Windows' `where`: every match on its own line, CRLF line endings; for no match an INFO line on
		// STDERR and exit code 1. Only answers `where $PATH:<name>`: a bare name also searches the current directory.
		$this->fakeExecutable(
			self::windowsTool('where'),
			"echo \"\$*\" >> '" . $this->root() . "/where.log'\n"
			. "case \"\$1\" in\n"
			. "  '\$PATH:php') printf '%s\\r\\n' '" . $first . "' '" . $second . "' ;;\n"
			. "  '\$PATH:php59') printf '%s\\r\\n' '" . $third . "' ;;\n"
			. "  *) echo 'INFO: Could not find files for the given pattern(s).' >&2; exit 1 ;;\n"
			. "esac\n"
		);

		$finder = $this->simulateOs($this->finder(['useWhere' => true]), 'Windows');

		// exec() returns only the LAST line of the output, and that was all that was used: when `where` listed
		// several binaries, only the last one was kept.
		[$paths, $diagnostics] = $this->withDiagnostics(function () use ($finder) {
			return $this->callPrivate($finder, 'pathsUsingWhere', ['5.9']);
		});

		$this->assertSame([$first, $second, $third], $paths);
		$this->assertSame([], $diagnostics);
		$this->assertContains('$PATH:php', $this->whereLog());
		$this->assertContains('$PATH:php59', $this->whereLog());

		$this->assertSame([], $this->callPrivate($this->simulateOs($this->finder(['useWhere' => false]), 'Windows'), 'pathsUsingWhere', ['5.9']));
	}

	public function testWhereKeepsOnlyExeFiles(): void
	{
		// Windows' `where $PATH:php` lists every file named php with an extension in PATHEXT, and php itself: php.bat,
		// php.cmd, an extensionless php (as on Windows Server 2022, see FactsTest in tests/Windows). Only php.exe, in
		// any case, is a PHP binary. Here every one is a fake PHP which the host can run, so a kept one would be found.
		$bat  = $this->fakePhp('w/ext/php.bat', '5.9.1');
		$cmd  = $this->fakePhp('w/ext/php.cmd', '5.9.2');
		$bare = $this->fakePhp('w/ext/php', '5.9.3');
		$exe  = $this->fakePhp('w/ext/PHP.EXE', '5.9.4');

		$this->fakeExecutable(
			self::windowsTool('where'),
			"case \"\$1\" in\n"
			. "  '\$PATH:php') printf '%s\\r\\n' '" . $bare . "' '" . $bat . "' '" . $cmd . "' '" . $exe . "' ;;\n"
			. "  *) echo 'INFO: Could not find files for the given pattern(s).' >&2; exit 1 ;;\n"
			. "esac\n"
		);

		$finder = $this->simulateOs($this->finder(['useWhere' => true]), 'Windows');

		$this->assertSame([$exe], array_values($finder->getPossiblePaths('5.9')));
		$this->assertSame($exe, $finder->getBestPath('5.9'));
		$this->assertSame($exe, $finder->getBestPath(null));
	}

	public function testPeImageHeaderCheck(): void
	{
		// The smallest file which passes: MZ, e_lfanew (at 0x3C) = 0x40, PE\0\0 at 0x40.
		$pe = 'MZ' . str_repeat("\0", 0x3A) . pack('V', 0x40) . "PE\0\0" . str_repeat("\0", 16);

		$files = [
			'pe.exe'           => [$pe, true],
			'pe-elsewhere.exe' => [substr_replace($pe, pack('V', 0x80), 0x3C, 4) . str_repeat("\0", 0x80 - strlen($pe)) . "PE\0\0", true],
			'text.exe'         => ["This is not a program.\r\n", false],
			'empty.exe'        => ['', false],
			'mz-only.exe'      => ['MZ', false],
			'short-header.exe' => [substr($pe, 0, 63), false],
			'no-pe.exe'        => [substr($pe, 0, 0x40) . 'NE' . str_repeat("\0", 16), false],
			'pe-past-end.exe'  => [substr_replace($pe, pack('V', 0x1000), 0x3C, 4), false],
			'pe-in-dos.exe'    => [substr_replace(substr_replace($pe, pack('V', 0x10), 0x3C, 4), "PE\0\0", 0x10, 4), false],
			'pe-negative.exe'  => [substr_replace($pe, pack('V', 0xFFFFFFF0), 0x3C, 4), false],
			'php.bat'          => ["@echo PHP 8.1.99 (cli)\r\n", false],
		];

		$finder = new PHPFinder();

		[$results, $diagnostics] = $this->withDiagnostics(function () use ($files, $finder) {
			$results = [];

			foreach ($files as $name => [$bytes, $expected])
			{
				$path = $this->root() . '/' . $name;
				file_put_contents($path, $bytes);
				$results[$name] = $this->callPrivate($finder, 'isPeImage', [$path]);
			}

			// Neither a file which does not exist, nor a directory, nor a binary of another kind (PHP_BINARY here,
			// unless the tests run on Windows) is one.
			$results['missing']   = $this->callPrivate($finder, 'isPeImage', [$this->root() . '/missing.exe']);
			$results['directory'] = $this->callPrivate($finder, 'isPeImage', [$this->root()]);

			return $results;
		});

		$expected = array_map(
			function ($file) {
				return $file[1];
			},
			$files
		) + ['missing' => false, 'directory' => false];

		$this->assertSame($expected, $results);
		$this->assertSame([], $diagnostics);

		if (strtoupper(PHP_OS_FAMILY) !== 'WINDOWS')
		{
			$this->assertFalse($this->callPrivate($finder, 'isPeImage', [PHP_BINARY]), 'PHP_BINARY is not a PE image here');
		}
	}

	public function testOnlyWindowsProgramsAreReturnedOrRunOnWindows(): void
	{
		// A php.exe which is not a program (a text file, an empty file), wherever a Windows search strategy looks. Here
		// each is a shell script which the host could run, and which would report PHP 5.9.9 and leave a line in a log
		// (fakeNonProgramPhp()): a finder simulating Windows must neither return nor run it.
		$notPrograms = [
			'where'         => $this->fakeNonProgramPhp('w/path/php.exe', '5.9.9'),
			'common'        => $this->fakeNonProgramPhp('C:\\PHP59/php.exe', '5.9.9'),
			'common depth'  => $this->fakeNonProgramPhp('C:\\PHP/sub/php.exe', '5.9.9'),
			'program files' => $this->fakeNonProgramPhp('C:\\Program Files\\PHP59/php.exe', '5.9.9'),
			'wamp'          => $this->fakeNonProgramPhp('C:\\wamp64\\bin\\php/php5.9.9/php.exe', '5.9.9'),
			'mamp'          => $this->fakeNonProgramPhp('C:\\MAMP\\bin\\php/php5.9.9/php.exe', '5.9.9'),
			'xampp'         => $this->fakeNonProgramPhp('C:\\xampp\\php\\php.exe', '5.9.9'),
			'plesk'         => $this->fakeNonProgramPhp('plesk\\Additional\\PleskPHP59\\php.exe', '5.9.9'),
			'plesk legacy'  => $this->fakeNonProgramPhp('plesk\\Additional\\PHP59\\php.exe', '5.9.9'),
		];
		// A real one, found after all of the above.
		$program = $this->fakePhp('C:\\wamp64\\bin\\php/php5.9.1/php.exe', '5.9.1');
		// Programs, named otherwise than .exe.
		$otherNames = [$this->fakePhp('w/path/php.com', '5.9.8'), $this->fakePhp('w/path/php.bat', '5.9.7')];

		$this->fakeExecutable(
			self::windowsTool('where'),
			"case \"\$1\" in\n"
			. "  '\$PATH:php') printf '%s\\r\\n' '" . $notPrograms['where'] . "' ;;\n"
			. "  *) echo 'INFO: Could not find files for the given pattern(s).' >&2; exit 1 ;;\n"
			. "esac\n"
		);

		$savedPleskDir = getenv('PLESK_DIR');
		putenv('PLESK_DIR=' . $this->root() . '/plesk\\');

		try
		{
			$finder = $this->simulateOs(
				$this->finder(['useWhere' => true, 'usePHPCommonWinDirsSearch' => true, 'softwareSpecific' => true]),
				'Windows'
			);
			$this->setPrivate($finder, 'windowsDrives', [$this->fakeWindowsDrive('C')]);

			[$results, $diagnostics] = $this->withDiagnostics(function () use ($finder, $notPrograms, $otherNames) {
				$analyzed = [];

				// What runs a binary: given any path at all, not only those getPossiblePaths() returns.
				foreach (array_merge($notPrograms, $otherNames) as $path)
				{
					$analyzed[$path] = $this->callPrivate($finder, 'analyzePHPVersion', [$path])->version;
				}

				return [
					'possible' => array_values($finder->getPossiblePaths('5.9')),
					'best'     => $finder->getBestPathMeta('5.9'),
					'analyzed' => $analyzed,
				];
			});
		}
		finally
		{
			putenv($savedPleskDir === false ? 'PLESK_DIR' : ('PLESK_DIR=' . $savedPleskDir));
		}

		$this->assertSame([], $diagnostics);

		foreach ($notPrograms as $strategy => $path)
		{
			$this->assertNotContains($path, $results['possible'], 'Not a program, returned (' . $strategy . ')');
		}

		$this->assertContains($program, $results['possible']);
		$this->assertSame($program, $results['best']->path ?? null);
		$this->assertSame(
			array_fill_keys(array_merge(array_values($notPrograms), $otherNames), null),
			$results['analyzed']
		);
		$this->assertSame([], $this->notProgramRuns(), 'Files which are not Windows programs ran');

		// The control: on a simulated Linux, the same scripts are programs, and they run.
		$this->assertSame(
			'5.9.9',
			$this->callPrivate($this->simulateOs($this->finder(), 'Linux'), 'analyzePHPVersion', [$notPrograms['where']])->version
		);
		$this->assertSame([$notPrograms['where']], $this->notProgramRuns());
	}

	public function testWindowsDrivesFromWmic(): void
	{
		// `wmic logicaldisk get name`, as it prints through a pipe: a header, each name padded to the column
		// width with spaces, \r\r\n line endings, and a blank line at the end. exec() strips the trailing
		// whitespace, \r included, from every line it puts in its output array, so no drive is padded.
		$this->fakeExecutable(
			self::windowsTool('wmic'),
			"[ \"\$*\" = 'logicaldisk get name' ] || exit 2\n"
			. "printf 'Name  \\r\\r\\nC:    \\r\\r\\nD:    \\r\\r\\n\\r\\r\\n'\n"
		);
		$this->fakeExecutable(self::windowsTool('fsutil'), "echo \"\$*\" >> '" . $this->root() . "/fsutil.log'\nexit 1\n");

		[$drives, $diagnostics] = $this->withDiagnostics(function () {
			return $this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives');
		});

		$this->assertSame(['C:', 'D:'], array_values($drives));
		$this->assertSame([], $diagnostics);
		$this->assertFalse(is_file($this->root() . '/fsutil.log'), 'fsutil was run although wmic answered');
	}

	public function testWindowsDrivesFromFsutil(): void
	{
		// No wmic (removed from recent Windows 11 releases): `fsutil fsinfo drives` prints a blank line, then
		// "Drives: C:\ D:\ " on one line.
		$this->fakeExecutable(
			self::windowsTool('fsutil'),
			"[ \"\$*\" = 'fsinfo drives' ] || exit 2\n"
			. "printf '\\r\\nDrives: C:\\\\ D:\\\\ E:\\\\ \\r\\n'\n"
		);

		$this->assertSame(
			['C:', 'D:', 'E:'],
			array_values($this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives'))
		);
	}

	public function testWindowsDrivesFallBackWhenWmicListsNone(): void
	{
		// A wmic which runs, but lists no drive (or fails, e.g. with WMI broken).
		$this->fakeExecutable(self::windowsTool('wmic'), "printf 'No Instance(s) Available.\\r\\r\\n'\n");
		$this->fakeExecutable(self::windowsTool('fsutil'), "printf '\\r\\nDrives: C:\\\\ D:\\\\ \\r\\n'\n");

		$finder = $this->simulateOs($this->finder(), 'Windows');

		// Used to return []: exec() did not return false, so neither fsutil nor %SYSTEMDRIVE% was tried.
		$this->assertSame(['C:', 'D:'], $this->callPrivate($finder, 'getWindowsDrives'));
	}

	public function testWindowsDriveListsIgnoreErrorMessages(): void
	{
		// wmic, fsutil and PowerShell all exist; the first two print an error on STDOUT. Error lines used to be taken
		// for drives: "ERROR:" (any wmic line with a colon), "Access", "is", "denied." (fsutil's words after "Error:").
		$this->fakeExecutable(self::windowsTool('wmic'), "printf 'ERROR:\\r\\nDescription = Access denied.\\r\\n'\nexit 1\n");
		$this->fakeExecutable(self::windowsTool('fsutil'), "printf 'Error:  Access is denied.\\r\\n'\nexit 1\n");
		$this->fakeExecutable(self::windowsTool('powershell'), "printf 'C:\\\\\\r\\nE:\\\\\\r\\n'\n");

		$this->assertSame(
			['C:', 'E:'],
			$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
		);
	}

	public function testWindowsDrivesFromPowerShell(): void
	{
		// Neither wmic nor fsutil: PowerShell's [IO.DriveInfo]::GetDrives(), one "X:\" per line.
		$this->fakeExecutable(
			self::windowsTool('powershell'),
			"case \"\$*\" in *GetDrives*) ;; *) exit 2 ;; esac\n"
			. "printf 'C:\\\\\\r\\nD:\\\\\\r\\n'\n"
		);

		$this->assertSame(
			['C:', 'D:'],
			$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
		);
	}

	public function testWindowsDrivesAlwaysIncludeTheSystemDrive(): void
	{
		$savedSystemDrive = getenv('SYSTEMDRIVE');
		putenv('SYSTEMDRIVE=Q:');

		try
		{
			// No tool lists any drive: only the system drive.
			$this->fakeExecutable(self::windowsTool('wmic'), "printf '\\r\\r\\n\\r\\r\\n'\n");
			$this->assertSame(['Q:'], $this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives'));

			// The tools do not list the system drive: it comes first.
			$this->fakeExecutable(self::windowsTool('wmic'), "printf 'Name  \\r\\r\\nC:    \\r\\r\\nD:    \\r\\r\\n'\n");
			$this->assertSame(
				['Q:', 'C:', 'D:'],
				$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
			);
		}
		finally
		{
			putenv($savedSystemDrive === false ? 'SYSTEMDRIVE' : ('SYSTEMDRIVE=' . $savedSystemDrive));
		}

		// Without SYSTEMDRIVE, C:.
		putenv('SYSTEMDRIVE');

		try
		{
			$this->fakeExecutable(self::windowsTool('wmic'), "printf 'Name  \\r\\r\\nD:    \\r\\r\\n'\n");
			$this->assertSame(
				['C:', 'D:'],
				$this->callPrivate($this->simulateOs($this->finder(), 'Windows'), 'getWindowsDrives')
			);
		}
		finally
		{
			putenv($savedSystemDrive === false ? 'SYSTEMDRIVE' : ('SYSTEMDRIVE=' . $savedSystemDrive));
		}
	}

	public function testWindowsToolsRunFromTheWindowsDirectoryOnly(): void
	{
		// Stand-ins for where, wmic, fsutil and PowerShell in the PATH, each logging that it ran. The library used to
		// run the tools by their bare names, through the shell: on Windows, cmd.exe looks for those in the current
		// directory first, then in the PATH, so a where.exe in the application's working directory ran.
		foreach (['where', 'wmic', 'fsutil', 'powershell'] as $tool)
		{
			$this->fakeExecutable('bin/' . $tool, "echo \"\$*\" >> '" . $this->root() . "/path-tools.log'\nprintf 'Z:\\r\\n'\n");
		}

		$php = $this->fakePhp('w/php.exe', '5.9.1');

		// The real ones, in the Windows directory.
		$this->fakeExecutable(self::windowsTool('where'), "case \"\$1\" in '\$PATH:php') printf '%s\\r\\n' '" . $php . "' ;; *) exit 1 ;; esac\n");
		$this->fakeExecutable(self::windowsTool('fsutil'), "printf '\\r\\nDrives: C:\\\\ D:\\\\ \\r\\n'\n");

		$finder = $this->simulateOs($this->finder(['useWhere' => true]), 'Windows');

		$this->assertSame([$php], $this->callPrivate($finder, 'pathsUsingWhere', ['5.9']));
		$this->assertSame(['C:', 'D:'], $this->callPrivate($finder, 'getWindowsDrives'));
		$this->assertFalse(
			is_file($this->root() . '/path-tools.log'),
			'A tool in the PATH ran: ' . @file_get_contents($this->root() . '/path-tools.log')
		);
	}

	public function testWindowsToolsAreNotRunWhenTheyAreMissing(): void
	{
		// No tool in the Windows directory: nothing runs, not even what the PATH has under the same names.
		foreach (['where', 'wmic', 'fsutil', 'powershell'] as $tool)
		{
			$this->fakeExecutable('bin/' . $tool, "echo \"\$*\" >> '" . $this->root() . "/path-tools.log'\nprintf 'Z:\\r\\n'\n");
		}

		$finder = $this->simulateOs($this->finder(['useWhere' => true]), 'Windows');

		$this->assertSame([], $this->callPrivate($finder, 'pathsUsingWhere', ['5.9']));
		$this->assertSame([(string) (getenv('SYSTEMDRIVE') ?: 'C:')], $this->callPrivate($finder, 'getWindowsDrives'));
		$this->assertFalse(is_file($this->root() . '/path-tools.log'), 'A tool in the PATH ran');
	}

	public function testTheWindowsDirectory(): void
	{
		$saved = [];

		foreach (['SYSTEMROOT', 'WINDIR'] as $variable)
		{
			$saved[$variable] = getenv($variable);
		}

		$directory = function (?string $systemRoot, ?string $windir): string {
			putenv($systemRoot === null ? 'SYSTEMROOT' : ('SYSTEMROOT=' . $systemRoot));
			putenv($windir === null ? 'WINDIR' : ('WINDIR=' . $windir));

			return $this->callPrivate(new PHPFinder(), 'getWindowsDirectory');
		};

		try
		{
			$this->assertSame('D:\\WINNT', $directory('D:\\WINNT', 'C:\\Windows'));
			$this->assertSame('C:\\Windows', $directory('C:\\Windows\\', null));
			$this->assertSame('E:\\Windows', $directory(null, 'E:\\Windows'));
			// Never a relative path, nor a bare drive: those would be looked for in the current directory.
			$this->assertSame('E:\\Windows', $directory('Windows', 'E:\\Windows'));
			$this->assertSame('C:\\Windows', $directory('Windows', 'C:'));
			$this->assertSame('C:\\Windows', $directory('\\Windows', null));
			$this->assertSame('C:\\Windows', $directory(null, null));
		}
		finally
		{
			foreach ($saved as $variable => $value)
			{
				putenv($value === false ? $variable : ($variable . '=' . $value));
			}
		}
	}

	public function testParseWindowsDrives(): void
	{
		$finder = $this->finder();

		$this->assertSame(['C:', 'D:'], $this->callPrivate($finder, 'parseWindowsDrives', [['Name', 'C:', 'd:', '', 'C:']]));
		$this->assertSame(
			['C:', 'D:', 'E:'], $this->callPrivate($finder, 'parseWindowsDrives', [['', 'Drives: C:\\ D:\\ E:\\']])
		);
		// Another language's fsutil label.
		$this->assertSame(['C:'], $this->callPrivate($finder, 'parseWindowsDrives', [['Laufwerke: C:\\']]));
		$this->assertSame(
			[],
			$this->callPrivate(
				$finder, 'parseWindowsDrives',
				[['ERROR:', 'Description = Access denied.', 'Error:  Access is denied.', 'No Instance(s) Available.']]
			)
		);
	}

	public function testHomebrewFindsTheVersionedFormula(): void
	{
		$expected = $this->fakeBrew();

		// Used to ask only for the unversioned "php" formula (the php@X.Y check could never be true), and to
		// return `brew --prefix`, a DIRECTORY, which getPossiblePaths() then dropped because it is not a file.
		$finder = $this->finder(['softwareSpecific' => true]);
		$paths  = $finder->getPossiblePaths('5.9');

		$this->assertSame($expected, $finder->getBestPath('5.9'));
		$this->assertContains($expected, $paths);
		$this->assertNotContains($this->root() . '/brew/opt/php@5.9', $paths, 'The prefix directory is no candidate');
		$this->assertContains('--prefix php@5.9', $this->brewLog());
	}

	public function testHomebrewFallsBackToThePhpFormula(): void
	{
		$this->fakeBrew();

		// There is no php@5.8 formula installed, but the unversioned "php" formula is PHP 5.8.
		$this->assertSame(
			$this->root() . '/brew/opt/php/bin/php',
			$this->finder(['softwareSpecific' => true])->getBestPath('5.8')
		);
		$this->assertContains('--prefix php@5.8', $this->brewLog());
		$this->assertContains('--prefix php', $this->brewLog());
	}

	public function testHomebrewFindsTheLinkedPhp(): void
	{
		$this->fakeBrew();

		// <prefix>/bin/php, the PHP `brew link` put in Homebrew's own prefix (here a PHP 5.7, from no formula
		// the fake knows about).
		$linked = $this->fakePhp('brew/bin/php', '5.7.3');

		$this->assertSame($linked, $this->finder(['softwareSpecific' => true])->getBestPath('5.7'));
		$this->assertContains('--prefix', $this->brewLog());
	}

	public function testHomebrewWithoutAMinorVersionOnlyAsksForThePhpFormula(): void
	{
		$this->fakeBrew();

		$finder = $this->finder(['softwareSpecific' => true]);

		$this->assertContains($this->root() . '/brew/opt/php/bin/php', $finder->getPossiblePaths());
		$this->assertContains($this->root() . '/brew/opt/php/bin/php', $finder->getPossiblePaths('5'));

		foreach ($this->brewLog() as $line)
		{
			$this->assertStringNotContainsString('php@', $line, 'There is no php@X formula for a bare major version');
		}
	}

	public function testHomebrewIsGatedBySoftwareSpecific(): void
	{
		$this->fakeBrew();

		// Used to be guarded by `!softwareSpecific && !isUnix`, so on Linux and macOS it ran `brew` even with
		// softwareSpecific off.
		$this->assertSame([], $this->callPrivate($this->finder(['softwareSpecific' => false]), 'pathsHomeBrew', ['5.9']));
		$this->finder(['softwareSpecific' => false])->getPossiblePaths('5.9');

		// Not assertFileDoesNotExist(): it does not exist in PHPUnit 8.5.
		$this->assertFalse(is_file($this->root() . '/brew.log'), 'brew was run');
	}

	public function testHomebrewDefaultPrefixesWithoutBrew(): void
	{
		// No `brew` in the PATH, as is common under a web server: only the default prefixes are searched.
		$paths = $this->callPrivate($this->finder(['softwareSpecific' => true]), 'pathsHomeBrew', ['8.4']);

		switch (strtoupper(PHP_OS_FAMILY))
		{
			case 'DARWIN':
				$this->assertSame(
					[
						'/opt/homebrew/opt/php@8.4/bin/php',
						'/opt/homebrew/opt/php/bin/php',
						'/usr/local/opt/php@8.4/bin/php',
						'/usr/local/opt/php/bin/php',
					],
					$paths
				);
				break;

			case 'LINUX':
				$this->assertSame(
					['/home/linuxbrew/.linuxbrew/opt/php@8.4/bin/php', '/home/linuxbrew/.linuxbrew/opt/php/bin/php'],
					$paths
				);
				break;

			default:
				// Homebrew supports neither BSD nor Windows.
				$this->assertSame([], $paths);
		}
	}

	/**
	 * A `whereis` which knows about ROOT/wi/php59, printing the util-linux / macOS format:
	 * `name: /path/one /path/two` — and `name:` alone when it finds nothing.
	 */
	private function fakeWhereIs(): string
	{
		$php = $this->fakePhp('wi/php59', '5.9.1');
		$dir = $this->root() . '/wi';

		$this->fakeExecutable(
			'bin/whereis',
			"[ \"\$1\" = '-b' ] || exit 2\n"
			. "if [ -x '" . $dir . "/'\"\$2\" ]; then echo \"\$2: " . $dir . "/\$2 " . $dir . "\"; else echo \"\$2:\"; fi\n"
		);

		return $php;
	}

	/**
	 * A `brew` which logs its arguments and prints a prefix like the real one: ROOT/brew for a bare
	 * `brew --prefix`, and ROOT/brew/opt/<formula> for `brew --prefix <formula>`, whether that formula is
	 * installed or not. The formulae `php` (a PHP 5.8) and `php@5.9` are installed.
	 *
	 * @return  string  The PHP 5.9 binary a correct lookup returns
	 */
	private function fakeBrew(): string
	{
		$this->fakePhp('brew/opt/php/bin/php', '5.8.0');
		$php59 = $this->fakePhp('brew/opt/php@5.9/bin/php', '5.9.1');

		$this->fakeExecutable(
			'bin/brew',
			"echo \"\$*\" >> '" . $this->root() . "/brew.log'\n"
			. "[ \"\$1\" = '--prefix' ] || exit 1\n"
			. "if [ -z \"\$2\" ]; then echo '" . $this->root() . "/brew'; exit 0; fi\n"
			. "echo '" . $this->root() . "/brew/opt/'\"\$2\"\n"
		);

		return $php59;
	}

	/**
	 * The fake where's invocations, one array element per call.
	 *
	 * @return  string[]
	 */
	private function whereLog(): array
	{
		return array_map('trim', @file($this->root() . '/where.log') ?: []);
	}

	/**
	 * The fake brew's invocations, one array element per call.
	 *
	 * @return  string[]
	 */
	private function brewLog(): array
	{
		return array_map('trim', @file($this->root() . '/brew.log') ?: []);
	}
}
