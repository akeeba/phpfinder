<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder;

/**
 * A class to locate the PHP binary path, optionally for a specific version.
 *
 * @license GPL-3.0-or-later
 * @author  Nicholas K. Dionysopoulos
 */
final class PHPFinder
{
	/**
	 * The Configuration object
	 *
	 * @var    Configuration
	 * @since  1.0.0
	 */
	protected $configuration;

	/**
	 * The OS family to behave as, instead of PHP_OS_FAMILY. NULL (always, outside the tests) uses PHP_OS_FAMILY.
	 *
	 * Private, and never set by the library itself: the tests set it through a bound closure, to exercise the
	 * search strategies of the operating systems they are not running on.
	 *
	 * @var    string|null
	 * @since  1.0.2
	 */
	private $osFamily = null;

	/**
	 * The Windows directory to run Windows' own tools from, instead of the one the environment names. NULL (always,
	 * outside the tests) uses getWindowsDirectory()'s lookup.
	 *
	 * Private, and never set by the library itself: the tests set it through a bound closure, to run stand-ins for
	 * `where`, `wmic`, `fsutil` and PowerShell without changing the SystemRoot the programs they run need.
	 *
	 * @var    string|null
	 * @since  1.0.2
	 */
	private $windowsDirectory = null;

	/**
	 * The Windows drives, once getWindowsDrives() has looked them up for this object.
	 *
	 * @var    string[]|null
	 * @since  1.0.2
	 */
	private $windowsDrives = null;

	/**
	 * What decides, instead of isPeImage(), whether a file is a Windows program. NULL (always, outside the tests) uses
	 * isPeImage().
	 *
	 * Private, and never set by the library itself: the unit tests set it through a bound closure when they simulate
	 * Windows on another OS, where the PHP binaries they run are shell scripts, not PE images.
	 *
	 * @var    callable|null
	 * @since  1.0.2
	 */
	private $peImageCheck = null;

	/**
	 * Returns a new object instance.
	 *
	 * @param   Configuration|null  $configuration  The configuration to use.
	 *
	 * @return  self
	 */
	final public static function make(?Configuration $configuration = null): self
	{
		return new self($configuration);
	}

	/**
	 * Public constructor.
	 *
	 * @param   Configuration|null  $configuration  The optional configuration object.
	 *
	 * @since   1.0.0
	 */
	final public function __construct(?Configuration $configuration = null)
	{
		$this->configuration = $configuration ?? new Configuration();
	}

	/**
	 * Get the best guess path for the requested PHP version's CLI executable.
	 *
	 * If the version is not found, it will return NULL.
	 *
	 * If no version is specified, any PHP CLI binary will be returned; not necessarily the one with the latest PHP
	 * version! A NULL or empty version means "any version": there is no version to validate against, so
	 * Configuration::$validateVersion has no effect. The binary must still run and report a PHP version, and
	 * Configuration::$validateCli still applies.
	 *
	 * If no suitable executable is found (there is no PHP CLI executable installed, or it uses an uncommon path) NULL
	 * will be returned as well.
	 *
	 * @param   string|null  $version  The version to look for, e.g. 8.3 or 8.3.1. With version validation, the
	 *                                 binary's version must match it on whole components: 8.3 matches 8.3.12,
	 *                                 but not 8.30.0 or 7.8.3.
	 *
	 * @return  string|null
	 * @since   1.0.0
	 */
	final public function getBestPath(?string $version = null): ?string
	{
		$info = $this->getBestPathMeta($version);

		return $info === null ? null : $info->path;
	}

	/**
	 * Get the best guess path for the requested PHP version's CLI executable along with its metadata.
	 *
	 * See notes on getBestPath.
	 *
	 * @param   string|null  $version  The version to look for.
	 *
	 * @return  null|object{version: string, cli: bool, path: string}
	 * @since   1.0.0
	 * @see     self::getBestPath
	 */
	final public function getBestPathMeta(?string $version = null): ?object
	{
		$possiblePaths = $this->getPossiblePaths($version);

		if (empty($possiblePaths))
		{
			return null;
		}

		foreach ($possiblePaths as $path)
		{
			$info = $this->analyzePHPVersion($path);

			if ($info->version === null)
			{
				continue;
			}

			// Without a version there is nothing to validate the binary's version against: any PHP will do.
			if (
				$this->configuration->validateVersion
				&& !empty($version)
				&& !$this->versionMatches($info->version, $version)
			)
			{
				continue;
			}

			if ($this->configuration->validateCli && !$info->cli)
			{
				continue;
			}

			$info->path = $path;

			return $info;
		}

		return null;
	}

	/**
	 * Gets all possible PHP CLI paths available on the server.
	 *
	 * The returned paths might NOT correspond to the correct version. Some paths are generic. Use getBestPath() with
	 * default configuration options to guarantee the accuracy of the result.
	 *
	 * On Windows, only .exe files which are Windows programs are returned: PHP's CLI binary is php.exe (see
	 * isCandidateFile()).
	 *
	 * @param   string|null  $version  The version to look for.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	public function getPossiblePaths(?string $version = null): array
	{
		$ret = [];

		// PHP constants
		$ret = array_merge($ret, $this->pathsFromConstants());

		// OS-specific search methods
		$ret = array_merge($ret, $this->pathsUNIX($version));
		$ret = array_merge($ret, $this->pathsUsingWhich($version));
		$ret = array_merge($ret, $this->pathsUsingUpdateAlternatives($version));
		$ret = array_merge($ret, $this->pathsUsingWhereIs($version));
		$ret = array_merge($ret, $this->pathsUsingWhere($version));
		$ret = array_merge($ret, $this->pathsFromCommonWindowsInstallPaths($version));

		// Software-specific
		$ret = array_merge($ret, $this->pathsCPanel($version));
		$ret = array_merge($ret, $this->pathsCloudLinux($version));
		$ret = array_merge($ret, $this->pathsPlesk($version));
		$ret = array_merge($ret, $this->pathsXAMPP($version));
		$ret = array_merge($ret, $this->pathsMAMP($version));
		$ret = array_merge($ret, $this->pathsHomeBrew($version));
		$ret = array_merge($ret, $this->pathsWAMPServer($version));

		return array_filter(
			array_unique($ret),
			function ($filePath) {
				return $this->isCandidateFile((string) $filePath);
			}
		);
	}

	/**
	 * Get possible paths from PHP constants.
	 *
	 * Available on all OS. Fast.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsFromConstants(): array
	{
		if (!$this->configuration->useConstants)
		{
			return [];
		}

		$ret = [];

		defined('PHP_BINARY') && $ret[] = PHP_BINARY;
		/** @noinspection PhpUndefinedConstantInspection */
		defined('PHP_PATH') && $ret[] = PHP_PATH;
		/** @noinspection PhpUndefinedConstantInspection */
		defined('PHP_PEAR_PHP_BIN') && $ret[] = PHP_PEAR_PHP_BIN;

		return $ret;
	}

	/**
	 * Return the default PHP paths for UNIX compatible systems.
	 *
	 * Available on Linux, BSD, and macOS. Very fast.
	 *
	 * @param   string|null  $version  The optional version to look for
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsUNIX(?string $version = null): array
	{
		if (
			!$this->configuration->osSpecific
			|| !$this->configuration->useFixedPaths
			|| !in_array($this->getOsFamily(), ['LINUX', 'BSD', 'DARWIN'])
		)
		{
			return [];
		}

		$possibleNames = $this->getBinaryNamesForVersion($version);
		$ret           = [];

		foreach ($possibleNames as $binName)
		{
			$ret[] = '/usr/bin/' . $binName;
			$ret[] = '/usr/local/bin/' . $binName;
			$ret[] = '/opt/bin/' . $binName;
		}

		return $ret;
	}

	/**
	 * Get possible PHP paths using `which`.
	 *
	 * Available on Linux and macOS. Moderately slow (about 100 msec).
	 *
	 * @param   string|null  $version  Optional PHP version to look for
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsUsingWhich(?string $version = null): array
	{
		if (
			!$this->configuration->osSpecific
			|| !$this->configuration->useWhich
			|| !in_array($this->getOsFamily(), ['LINUX', 'BSD', 'DARWIN'])
		)
		{
			return [];
		}

		if (!function_exists('exec') || !$this->executableExists('which'))
		{
			return [];
		}

		$possibleExecutables = $this->getBinaryNamesForVersion($version);
		$ret                 = [];

		foreach ($possibleExecutables as $executable)
		{
			$which = @exec('which ' . escapeshellarg($executable) . ' 2>/dev/null', $output, $resultCode);

			if ($which === false || $resultCode !== 0)
			{
				continue;
			}

			$paths = explode("\n", $which);
			$paths = array_filter($paths);

			$ret = array_merge($ret, $paths);
		}

		return $ret;
	}

	/**
	 * Find possible PHP paths using `update-alternatives`.
	 *
	 * Available on Debian and derivatives (Linux, and Debian GNU/kFreeBSD). Fast.
	 *
	 * @param   string|null  $version  Optional PHP version to look for
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsUsingUpdateAlternatives(?string $version): array
	{
		if (
			!$this->configuration->osSpecific
			|| !$this->configuration->useUpdateAlternatives
			|| !in_array($this->getOsFamily(), ['LINUX', 'BSD'])
		)
		{
			return [];
		}

		if (!function_exists('exec') || !$this->executableExists('update-alternatives'))
		{
			return [];
		}

		$possibleExecutables = $this->getBinaryNamesForVersion($version);
		$ret                 = [];

		$result = @exec('update-alternatives --list php 2>/dev/null', $output);

		if ($result === false)
		{
			return $ret;
		}

		// One absolute path per line. Only the file name counts; a backslash is an ordinary character in it.
		foreach ($output as $line)
		{
			$parts    = explode(DIRECTORY_SEPARATOR, $line);
			$lastPart = end($parts);

			if (in_array($lastPart, $possibleExecutables, true))
			{
				$ret[] = $line;
			}
		}

		return $ret;
	}

	/**
	 * Find possible PHP paths using `whereis`.
	 *
	 * Available on Linux, macOS. Slow (over 1 second).
	 *
	 * @param   string|null  $version  Optional PHP version to look for
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsUsingWhereIs(?string $version): array
	{
		if (
			!$this->configuration->osSpecific
			|| !$this->configuration->useWhereIs
			|| !in_array($this->getOsFamily(), ['LINUX', 'BSD', 'DARWIN'])
		)
		{
			return [];
		}

		if (!function_exists('exec') || !$this->executableExists('whereis'))
		{
			return [];
		}

		$possibleExecutables = $this->getBinaryNamesForVersion($version);
		$ret                 = [];

		foreach ($possibleExecutables as $executable)
		{
			$result = @exec('whereis -b ' . escapeshellarg($executable) . ' 2>/dev/null');

			// "name: /path/one /path/two", or "name:" when nothing is found. exec() returns '' for no output.
			if ($result === false || strpos($result, ':') === false)
			{
				continue;
			}

			// Only the first colon ends the name: the paths may have colons, too.
			[, $rawList] = explode(':', $result, 2);
			$list = explode(' ', $rawList);
			$list = array_filter($list);

			if (empty($list))
			{
				continue;
			}

			foreach ($list as $path)
			{
				if (!@is_file($path))
				{
					continue;
				}

				$ret[] = $path;
			}
		}

		return $ret;
	}

	/**
	 * Get possible PHP paths using `where`.
	 *
	 * Available on Windows. Fast.
	 *
	 * @param   string|null  $version  Optional PHP version to look for
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsUsingWhere(?string $version = null): array
	{
		if (
			!$this->configuration->osSpecific
			|| !$this->configuration->useWhere
			|| $this->getOsFamily() !== 'WINDOWS'
		)
		{
			return [];
		}

		// Windows' own where.exe, by its full path: a bare `where` would be looked for in the current directory first.
		$where = $this->getWindowsToolPath('System32\\where.exe');

		if ($where === null)
		{
			return [];
		}

		$possibleExecutables = $this->getBinaryNamesForVersion($version);
		$ret                 = [];

		foreach ($possibleExecutables as $executable)
		{
			// `$PATH:` restricts the search to the PATH: for a bare name, `where` looks in the current directory first,
			// so any php.exe in the application's working directory would be found, and run. `where` prints "INFO:
			// Could not find files for the given pattern(s)." on STDERR, and exits with 1, for every name it does
			// not find.
			$output = $this->runProgram($where, escapeshellarg('$PATH:' . $executable), $resultCode, true);

			if ($output === null || $resultCode !== 0)
			{
				continue;
			}

			// One path per line, for every match in the PATH.
			$ret = array_merge($ret, array_filter(array_map('trim', $output)));
		}

		return $ret;
	}

	/**
	 * Get possible paths from common windows PHP installation paths
	 *
	 * @param   string|null  $version  The version to look for
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsFromCommonWindowsInstallPaths(?string $version = null): array
	{
		if (
			!$this->configuration->osSpecific
			|| !$this->configuration->usePHPCommonWinDirsSearch
			|| $this->getOsFamily() !== 'WINDOWS'
		)
		{
			return [];
		}

		$possiblePaths = [];

		foreach ($this->getWindowsDrives() as $drive)
		{
			$possiblePaths = array_merge(
				$possiblePaths,
				$this->getWindowsProgramFilesPHPPaths($drive, $version),
				$this->getWindowsProgramFilesPHPPaths($drive . '\\Program Files', $version),
				$this->getWindowsProgramFilesPHPPaths($drive . '\\Program Files (x86)', $version)
			);
		}

		$programFiles    = @getenv('PROGRAMFILES');
		$programFilesx86 = @getenv('PROGRAMFILES(X86)');

		$possiblePaths = array_merge($possiblePaths, $this->getWindowsProgramFilesPHPPaths($programFiles, $version));
		$possiblePaths = array_merge($possiblePaths, $this->getWindowsProgramFilesPHPPaths($programFilesx86, $version));

		// A directory => scan depth map: array_merge() already keeps one entry per directory. Scan the versioned
		// directories (depth 0) first, and the directories of the same depth in the order they were listed. Not with
		// asort(): it is not stable before PHP 8.0, so PHP 7 would scan the directories of the same depth in another
		// order, e.g. C:\Program Files\PHP before C:\PHP.
		$byDepth = [];

		foreach ($possiblePaths as $path => $maxNesting)
		{
			$byDepth[$maxNesting][$path] = $maxNesting;
		}

		ksort($byDepth);

		$possiblePaths = [];

		foreach ($byDepth as $paths)
		{
			$possiblePaths = array_merge($possiblePaths, $paths);
		}

		$ret = [];

		$binaryNames = $this->getBinaryNamesForVersion($version);

		foreach ($possiblePaths as $path => $maxNesting)
		{
			$ret = array_merge(
				$ret,
				$this->recursivePathScanner($path, 0, $maxNesting, $binaryNames)
			);
		}

		return array_unique($ret);
	}

	/**
	 * Return cPanel-specific PHP paths. Only works given a version.
	 *
	 * Available on Linux. Very fast.
	 *
	 * @param   string|null  $version  The version to get paths for.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsCPanel(?string $version): array
	{
		/** @noinspection DuplicatedCode */
		if (
			empty($version)
			|| !$this->configuration->softwareSpecific
			|| $this->getOsFamily() !== 'LINUX'
		)
		{
			return [];
		}

		[$major, $minor] = $this->getMajorMinor($version);

		if ($major === null || $minor === null)
		{
			return [];
		}

		$suffix = $major . $minor;

		return [
			'/opt/cpanel/ea-php' . $suffix . '/root/usr/bin/php',
			'/usr/bin/ea-php' . $suffix,
			'/usr/local/bin/ea-php' . $suffix,
		];
	}

	/**
	 * Return CloudLinux-specific PHP paths. Only works given a version.
	 *
	 * Available on Linux. Very fast.
	 *
	 * @param   string|null  $version  The version to get paths for.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsCloudLinux(?string $version): array
	{
		/** @noinspection DuplicatedCode */
		if (
			empty($version)
			|| !$this->configuration->softwareSpecific
			|| $this->getOsFamily() !== 'LINUX'
		)
		{
			return [];
		}

		[$major, $minor] = $this->getMajorMinor($version);

		if ($major === null || $minor === null)
		{
			return [];
		}

		$suffix = $major . $minor;

		return [
			'/opt/cloudlinux/alt-php' . $suffix . '/root/usr/bin/php',
			'/usr/bin/alt-php' . $suffix,
			'/usr/local/bin/alt-php' . $suffix,
		];
	}

	/**
	 * Return Plesk-specific PHP paths. Only works given a version.
	 *
	 * On Linux and BSD, Plesk installs each PHP version in /opt/plesk/php/X.Y/bin/php. On Windows, under the Plesk
	 * directory (the plesk_dir environment variable, usually C:\Program Files (x86)\Plesk\): Plesk Obsidian, and Plesk
	 * since at least 12, keep each PHP version in Additional\PleskPHP<major><minor>\php.exe (e.g. PleskPHP83);
	 * Plesk's documentation also lists Additional\PHP<major><minor>\php.exe (PHP 5.3 to 7.0, older releases).
	 *
	 * Available on Linux, BSD and Windows. Very fast.
	 *
	 * @param   string|null  $version  The version to get paths for.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsPlesk(?string $version): array
	{
		if (
			empty($version)
			|| !$this->configuration->softwareSpecific
		)
		{
			return [];
		}

		[$major, $minor] = $this->getMajorMinor($version);

		if ($major === null || $minor === null)
		{
			return [];
		}

		$suffix = $major . '.' . $minor;

		switch ($this->getOsFamily())
		{
			case 'LINUX':
			case 'BSD':
				return [
					'/opt/plesk/php/' . $suffix . '/bin/php',
					'/usr/bin/php' . $suffix,
					'/usr/local/bin/php' . $suffix,
				];

			case 'WINDOWS':
				// %plesk_dir%Additional\PleskPHP83\php.exe, or %plesk_dir%Additional\PHP83\php.exe. Plesk sets plesk_dir
				// with a trailing backslash; the environment is case-insensitive on Windows.
				$pleskDir = @getenv('PLESK_DIR');

				if (empty($pleskDir))
				{
					return [];
				}

				$additional = rtrim($pleskDir, '\\') . '\\Additional\\';

				return [
					$additional . 'PleskPHP' . $major . $minor . '\\php.exe',
					$additional . 'PHP' . $major . $minor . '\\php.exe',
				];

			default:
				return [];
		}
	}

	/**
	 * Returns the PHP path for XAMPP.
	 *
	 * This assumes that you are using the default installation path which is one of the following.
	 *
	 * - Windows: C:\xampp on Windows (where C: could be any known drive letter)
	 * - Linux: /opt/lampp
	 * - macOS: /Applications/XAMPP
	 *
	 * @param   string|null  $version  Ignored; XAMPP only has one PHP version installed at a time.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsXAMPP(?string $version): array
	{
		if (!$this->configuration->softwareSpecific)
		{
			return [];
		}

		switch ($this->getOsFamily())
		{
			case 'WINDOWS':
				return array_map(
					function ($drive) {
						return rtrim($drive, '\\') . '\\xampp\\php\\php.exe';
					},
					$this->getWindowsDrives() ?: ['C:']
				);

			case 'LINUX':
			case 'BSD':
				return [
					'/opt/lampp/php/php',
				];

			case 'DARWIN':
				return [
					'/Applications/XAMPP/php/php',
				];

			default:
				return [];
		}
	}

	/**
	 * Get the MAMP / MAMP Pro paths for PHP CLI.
	 *
	 * Available on macOS, and Windows. Fast.
	 *
	 * @param   string|null  $version  The version to get paths for.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsMAMP(?string $version)
	{
		if (!$this->configuration->softwareSpecific)
		{
			return [];
		}

		switch ($this->getOsFamily())
		{
			case 'DARWIN':
				return $this->getMAMPMacPaths('/Applications/MAMP/bin/php');

			case 'WINDOWS':
				// Each PHP version in its own directory: <drive>\MAMP\bin\php\php8.3.1\php.exe
				$ret = [];

				foreach ($this->getWindowsDrives() as $drive)
				{
					$ret = array_merge(
						$ret,
						$this->recursivePathScanner(rtrim($drive, '\\') . '\\MAMP\\bin\\php', 0, 1, ['php'])
					);
				}

				return $ret;

			default:
				return [];
		}
	}

	/**
	 * Get the PHP CLI binaries of a MAMP / MAMP Pro installation on macOS.
	 *
	 * MAMP keeps each PHP version in its own directory, with the binary two levels down, e.g.
	 * /Applications/MAMP/bin/php/php8.3.1/bin/php.
	 *
	 * @param   string  $phpRoot  MAMP's PHP directory, e.g. /Applications/MAMP/bin/php
	 *
	 * @return  string[]
	 * @since   1.0.2
	 */
	private function getMAMPMacPaths(string $phpRoot): array
	{
		return @glob(rtrim($phpRoot, '/') . '/php*/bin/php') ?: [];
	}

	/**
	 * Get the HomeBrew paths for PHP CLI.
	 *
	 * Homebrew runs on macOS, and on Linux (formerly "Linuxbrew"). When `brew` is in the PATH it is asked for the
	 * prefix of the php@X.Y formula (only given a major and minor version), of the php formula, and of Homebrew
	 * itself (whose bin/php is the linked PHP). The formulae are also looked for under Homebrew's default prefixes,
	 * for when `brew` is not in the PATH, as is common under a web server: /opt/homebrew (Apple Silicon) and
	 * /usr/local (Intel) on macOS, /home/linuxbrew/.linuxbrew on Linux.
	 *
	 * Available on macOS, and Linux. Moderately fast.
	 *
	 * @param   string|null  $version  The version to get paths for.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsHomeBrew(?string $version): array
	{
		if (
			!$this->configuration->softwareSpecific
			|| !in_array($this->getOsFamily(), ['LINUX', 'DARWIN'])
		)
		{
			return [];
		}

		[$major, $minor] = $this->getMajorMinor($version);

		// Most specific first. There is no php@X formula for a bare major version.
		$formulae = [];

		if ($major !== null && $minor !== null)
		{
			$formulae[] = 'php@' . $major . '.' . $minor;
		}

		$formulae[] = 'php';

		$ret = [];

		if (function_exists('exec') && $this->executableExists('brew'))
		{
			// `brew --prefix <formula>` prints <prefix>/opt/<formula> whether that formula is installed or not, so
			// every answer is a candidate; getPossiblePaths() drops those which are not files. The bare
			// `brew --prefix` (the empty formula) prints Homebrew's own prefix.
			foreach (array_merge($formulae, ['']) as $formula)
			{
				$output  = [];
				$command = 'brew --prefix' . ($formula === '' ? '' : (' ' . escapeshellarg($formula))) . ' 2>/dev/null';
				$result  = @exec($command, $output, $resultCode);

				if ($result === false || $resultCode !== 0 || trim($result) === '')
				{
					continue;
				}

				$ret[] = rtrim(trim($result), DIRECTORY_SEPARATOR)
				         . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php';
			}
		}

		$defaultPrefixes = $this->getOsFamily() === 'DARWIN'
			? ['/opt/homebrew', '/usr/local']
			: ['/home/linuxbrew/.linuxbrew'];

		foreach ($defaultPrefixes as $prefix)
		{
			foreach ($formulae as $formula)
			{
				$ret[] = $prefix . '/opt/' . $formula . '/bin/php';
			}
		}

		return array_values(array_unique($ret));
	}

	/**
	 * Get the WAMPServer paths for PHP CLI.
	 *
	 * IMPORTANT! This will return the paths to PHP CLI for all installed PHP versions. Unlike most other prepackaged
	 * xAMP servers, WAMPServer allows you to have multiple patch versions of the same minor PHP version installed at
	 * the same time. This makes it impossible to find a specific PHP version if you give only a minor version. This can
	 * be addressed by checking for the exact version during the verification stage.
	 *
	 * Available on Windows. Moderately fast.
	 *
	 * @param   string|null  $version  The version to get paths for.
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function pathsWAMPServer(?string $version): array
	{
		if (!$this->configuration->softwareSpecific || $this->getOsFamily() !== 'WINDOWS')
		{
			return [];
		}

		$possiblePaths = [];

		foreach ($this->getWindowsDrives() as $drive)
		{
			$possiblePaths[$drive . '\\wamp\\bin\\php']                            = 1;
			$possiblePaths[$drive . '\\wamp64\\bin\\php']                          = 1;
			$possiblePaths[$drive . '\\Program Files (x86)\\WampServer\\bin\\php'] = 1;
			$possiblePaths[$drive . '\\Program Files\\WampServer\\bin\\php']       = 1;
		}

		// The paths are the keys; the values are the scan depths.
		$possiblePaths = array_filter(
			$possiblePaths,
			function ($path) {
				return @is_dir($path);
			},
			ARRAY_FILTER_USE_KEY
		);

		if (empty($possiblePaths))
		{
			return [];
		}

		$ret = [];

		foreach ($possiblePaths as $path => $maxDepth)
		{
			$ret = array_merge($ret, $this->recursivePathScanner($path, 0, $maxDepth, ['php']));
		}

		return $ret;
	}

	/**
	 * Extract the major and minor version integers from a version string.
	 *
	 * @param   string|null  $version  The version to parse.
	 *
	 * A minor version of 0 is kept: "8.0" is [8, 0], not [8, null]. The minor version is NULL when there is no
	 * second component, or when it does not start with a digit ("8.", "8.x").
	 *
	 * @return  array  The major and minor versions. NULL elements if invalid version.
	 * @since   1.0.0
	 */
	private function getMajorMinor(?string $version = null): array
	{
		$major = null;
		$minor = null;

		if (empty ($version))
		{
			return [$major, $minor];
		}

		$parts = explode('.', $version);
		$major = @intval($parts[0]) ?: null;

		// Not `?: null`: that would turn the 0 of "8.0" into "no minor version".
		if ($major !== null && isset($parts[1]) && preg_match('/^\d+/', $parts[1]))
		{
			$minor = (int) $parts[1];
		}

		return [$major, $minor];
	}

	/**
	 * Does the version a binary reports match the version we are looking for?
	 *
	 * The requested version must match whole version components, from the start: "8.3" matches 8.3, 8.3.1 and
	 * 8.3.12, but not 8.30.0 or 7.8.3; "8.3.1" matches 8.3.1 and 8.3.1-dev, but not 8.3.10.
	 *
	 * @param   string  $actual     The version the binary reports, e.g. 8.3.12
	 * @param   string  $requested  The version we are looking for, e.g. 8.3
	 *
	 * @return  bool
	 * @since   1.0.2
	 */
	private function versionMatches(string $actual, string $requested): bool
	{
		// A prefix which is not followed by another digit, i.e. which ends at a component boundary.
		return preg_match('/^' . preg_quote($requested, '/') . '(?![0-9])/', $actual) === 1;
	}

	/**
	 * Get a list of possible PHP binary names for a given PHP version.
	 *
	 * Note: this does NOT add the `.exe` suffix on Windows!
	 *
	 * @param   string|null  $version  The optional PHP version
	 *
	 * @return  string[]
	 * @since   1.0.0
	 */
	private function getBinaryNamesForVersion(?string $version = null): array
	{
		$possibleExecutables = ['php'];

		[$major, $minor] = $this->getMajorMinor($version);

		if ($major === null)
		{
			return $possibleExecutables;
		}

		// phpXY (e.g. php84) and phpX.Y (e.g. php8.4, as installed by Debian and Ubuntu).
		$suffix    = $major . ($minor ?? '');
		$altSuffix = $major . ($minor === null ? '' : '.') . ($minor ?? '');

		$possibleExecutables[] = 'php' . $suffix;
		$possibleExecutables[] = 'php' . $altSuffix;
		$possibleExecutables[] = 'ea-php' . $suffix;
		$possibleExecutables[] = 'alt-php' . $suffix;

		if ($this->configuration->extendedBinaryNameSearch)
		{
			$possibleExecutables[] = 'php-cli';
			$possibleExecutables[] = 'php' . $suffix . '-cli';
			$possibleExecutables[] = 'php' . $altSuffix . '-cli';
			$possibleExecutables[] = 'php-' . $suffix;
			$possibleExecutables[] = 'php-' . $altSuffix;
			$possibleExecutables[] = 'php-' . $suffix . 'cli';
			$possibleExecutables[] = 'php-cli-' . $suffix;
			$possibleExecutables[] = 'php-cli' . $suffix;
		}

		// Without a minor version, $suffix and $altSuffix are the same; don't search for each name twice.
		return array_values(array_unique($possibleExecutables));
	}

	/**
	 * Gets the current list of known Windows drives, as drive letters with a colon, e.g. ['C:', 'D:'].
	 *
	 * The first of these tools which lists any drive is used:
	 *
	 * - `wmic logicaldisk get name`: a "Name" header, then one drive per line. wmic is gone from Windows 11 24H2 and
	 *   Windows Server 2025.
	 * - `fsutil fsinfo drives`: one line, "Drives: C:\ D:\" (the label is localised).
	 * - PowerShell's [IO.DriveInfo]::GetDrives(): one drive per line, "C:\". The slowest, about two seconds.
	 *
	 * A tool which runs but lists no drive (wmic prints "No Instance(s) Available." when WMI has none, a tool may fail
	 * with "Access is denied.") counts as failed, and the next one is tried; only drive letters count, never an error
	 * message. The system drive (%SYSTEMDRIVE%, or C:) is always in the list, first when no tool listed it.
	 *
	 * Each tool runs from the Windows directory, by its full path (see getWindowsToolPath()), and only if it is there.
	 *
	 * @return  string[]
	 * @since   1.0.0
	 */
	private function getWindowsDrives(): array
	{
		if ($this->windowsDrives !== null)
		{
			return $this->windowsDrives;
		}

		$drives = [];

		// Windows' own tools, by their full paths: a bare name would be looked for in the current directory first.
		$commands = [
			'System32\\wbem\\WMIC.exe'                          => 'logicaldisk get name',
			'System32\\fsutil.exe'                              => 'fsinfo drives',
			'System32\\WindowsPowerShell\\v1.0\\powershell.exe' => '-NoProfile -NonInteractive -Command '
			                                                       . escapeshellarg('[IO.DriveInfo]::GetDrives() | ForEach-Object { $_.Name }'),
		];

		foreach ($commands as $tool => $arguments)
		{
			$executable = $this->getWindowsToolPath($tool);

			if ($executable === null)
			{
				continue;
			}

			$drives = $this->parseWindowsDrives($this->runProgram($executable, $arguments, $exitCode, true) ?? []);

			if (!empty($drives))
			{
				break;
			}
		}

		$systemDrive = $this->parseWindowsDrives([(string) @getenv('SYSTEMDRIVE')]) ?: ['C:'];

		if (!in_array($systemDrive[0], $drives, true))
		{
			array_unshift($drives, $systemDrive[0]);
		}

		return $this->windowsDrives = $drives;
	}

	/**
	 * Extract the Windows drive letters from the output of a command which lists drives.
	 *
	 * A drive is a letter, a colon, then a backslash, whitespace or the end of the line, and is not part of a word: "C:"
	 * and "C:\" are drives, but neither "Drives:" nor "ERROR:" are.
	 *
	 * @param   string[]  $lines  The command's output, one line per element
	 *
	 * @return  string[]  The drives, upper case, e.g. ['C:', 'D:'], without duplicates, in the order they were listed
	 * @since   1.0.2
	 */
	private function parseWindowsDrives(array $lines): array
	{
		$drives = [];

		foreach ($lines as $line)
		{
			if (!preg_match_all('/(?<![A-Za-z0-9])([A-Za-z]):(?=\\\\|\s|$)/', trim($line), $matches))
			{
				continue;
			}

			foreach ($matches[1] as $letter)
			{
				$drives[] = strtoupper($letter) . ':';
			}
		}

		return array_values(array_unique($drives));
	}

	/**
	 * The null device of the shell which exec() runs commands in, for redirecting what a command prints on STDERR.
	 *
	 * This depends on the OS PHP really runs on, not on the OS family PHPFinder behaves as (see $osFamily): the tests
	 * simulate Windows on other systems, where the commands run in /bin/sh.
	 *
	 * @return  string
	 * @since   1.0.2
	 */
	private function getNullDevice(): string
	{
		return strtoupper(PHP_OS_FAMILY) === 'WINDOWS' ? 'NUL' : '/dev/null';
	}

	/**
	 * The Windows directory, e.g. C:\Windows, without a trailing backslash.
	 *
	 * The SystemRoot environment variable (or windir), when it is an absolute path; otherwise C:\Windows. Never a
	 * relative path: Windows' tools run from here by their full paths, so that no program of the same name in the
	 * current directory runs instead.
	 *
	 * @return  string
	 * @since   1.0.2
	 */
	private function getWindowsDirectory(): string
	{
		if ($this->windowsDirectory !== null)
		{
			return $this->windowsDirectory;
		}

		foreach (['SYSTEMROOT', 'WINDIR'] as $variable)
		{
			$directory = (string) @getenv($variable);

			if (preg_match('/^[A-Za-z]:[\\\\\/]./', $directory))
			{
				return rtrim($directory, '\\/');
			}
		}

		return 'C:\\Windows';
	}

	/**
	 * The full path to one of Windows' own programs, if it exists.
	 *
	 * @param   string  $relativePath  Its path in the Windows directory, e.g. System32\where.exe
	 *
	 * @return  string|null  NULL when there is no such file
	 * @since   1.0.2
	 */
	private function getWindowsToolPath(string $relativePath): ?string
	{
		$path = rtrim($this->getWindowsDirectory(), '\\/') . DIRECTORY_SEPARATOR
		        . str_replace('\\', DIRECTORY_SEPARATOR, $relativePath);

		return @is_file($path) ? $path : null;
	}

	/**
	 * Recursively scan paths on Windows for PHP binaries
	 *
	 * @param   string  $path         The path to scan
	 * @param   int     $depth        Current depth
	 * @param   int     $maxNesting   Maximum depth to scan
	 * @param   array   $binaryNames  List of allowed binary names (without the .exe suffix)
	 *
	 * @return  array  The full paths of the binaries found
	 * @since   1.0.0
	 */
	private function recursivePathScanner(string $path, int $depth, int $maxNesting, array $binaryNames): array
	{
		if (!@is_dir($path))
		{
			return [];
		}

		$ret       = [];
		$isWindows = $this->getOsFamily() === 'WINDOWS';

		/** @var \DirectoryIterator $file */
		foreach (new \DirectoryIterator($path) as $file)
		{
			if ($file->isDot())
			{
				continue;
			}

			if ($file->isDir())
			{
				if ($depth >= $maxNesting)
				{
					continue;
				}

				$ret = array_merge(
					$ret,
					$this->recursivePathScanner($file->getPathName(), $depth + 1, $maxNesting, $binaryNames)
				);

				continue;
			}

			if (!$file->isFile())
			{
				continue;
			}

			// The full path: a bare file name would later be looked for relative to the current directory.
			if ($isWindows)
			{
				if ($file->getExtension() === 'exe' && in_array($file->getBasename('.exe'), $binaryNames))
				{
					$ret[] = $file->getPathname();
				}

			}
			elseif (in_array($file->getBasename(), $binaryNames))
			{
				$ret[] = $file->getPathname();
			}
		}

		return $ret;
	}

	/**
	 * Analyzes the version information output of the suspected PHP CLI binary.
	 *
	 * @param   string  $path
	 *
	 * @return  object{version: string, cli: bool}
	 * @since   1.0.0
	 */
	private function analyzePHPVersion(string $path): object
	{
		$ret = (object) [
			'version' => null,
			'cli'     => false,
		];

		// The only place a PHP binary is run: whatever the path came from, it is the same check getPossiblePaths() makes.
		$output = $this->isCandidateFile($path) && $this->isExecutable($path) ? $this->runVersionCommand($path) : null;

		if ($output === null)
		{
			return $ret;
		}

		foreach ($output as $line)
		{
			if (substr($line, 0, 4) !== 'PHP ')
			{
				continue;
			}

			// PHP x.y.x (cli) (built: blah blah)
			$parts = explode(' ', $line);

			if (count($parts) < 3)
			{
				return $ret;
			}

			$ret->version = $parts[1];
			$ret->cli     = $parts[2] === '(cli)';

			return $ret;
		}

		return $ret;
	}

	/**
	 * Run `<binary> -v` and return the lines of its output, without their trailing whitespace, as exec() returns them.
	 *
	 * @param   string  $path  The path to the binary.
	 *
	 * @return  string[]|null  NULL when it could not be run.
	 * @since   1.0.2
	 */
	private function runVersionCommand(string $path): ?array
	{
		return $this->runProgram($path, '-v');
	}

	/**
	 * Run a program, given by its full path, and return the lines of its output, without their trailing whitespace,
	 * as exec() returns them. What it prints on STDERR is discarded: it would end up in the console of a CLI
	 * application.
	 *
	 * On Windows, proc_open() runs the program directly (bypass_shell), not through PHP's own choice of cmd.exe:
	 *
	 * - cmd.exe expands %NAME% in a command line even between double quotes when NAME is a defined environment
	 *   variable, so C:\odd %OS%\php.exe would run C:\odd Windows_NT\php.exe instead, and that cannot be escaped on a
	 *   cmd.exe command line;
	 * - PHP starts cmd.exe by its bare name, which Windows looks for in the current directory before the system
	 *   directory, so a cmd.exe in the application's working directory would run instead.
	 *
	 * With $throughCmd, the program runs through Windows' own cmd.exe, by its full path. This is for Windows' own tools,
	 * whose paths have no %. Under IIS with fastcgi.impersonate=1 (php.ini's advice for IIS; the default is 0), a
	 * program which proc_open() starts directly fails: PowerShell with "Loading managed Windows PowerShell failed with
	 * error 80070005", whoami with "Access is denied" (where, wmic, fsutil and php.exe -v work). Started by cmd.exe,
	 * both work. With fastcgi.impersonate=0 both work either way, so the impersonation is what triggers the failure
	 * (Windows Server 2022, IIS 10, PHP 8.4). Why going through cmd.exe avoids it was not investigated.
	 *
	 * Only where proc_open() is disabled does exec() run it, through PHP's cmd.exe. Then a program whose path has a % in
	 * it is not run at all, and a cmd.exe in the current directory runs instead of Windows' own (nothing PHPFinder can
	 * do about that). Spaces, &, (, ), ^ and ! are fine between double quotes (unless the registry turns cmd.exe's
	 * delayed expansion on, which makes !NAME! expand like %NAME%). This depends on the OS PHP really runs on, like
	 * getNullDevice().
	 *
	 * @param   string    $executable  The full path to the program.
	 * @param   string    $arguments   Its arguments, quoted for its command line.
	 * @param   int|null  $exitCode    Its exit code, when it ran.
	 * @param   bool      $throughCmd  On Windows, run it through Windows' own cmd.exe.
	 *
	 * @return  string[]|null  NULL when it could not be run.
	 * @since   1.0.2
	 */
	private function runProgram(
		string $executable, string $arguments, ?int &$exitCode = null, bool $throughCmd = false
	): ?array
	{
		$exitCode  = null;
		$isWindows = strtoupper(PHP_OS_FAMILY) === 'WINDOWS';
		$procOpen  = $isWindows && function_exists('proc_open');
		$command   = $this->quoteExecutablePath($executable, PHP_OS_FAMILY) . ($arguments === '' ? '' : (' ' . $arguments));

		// cmd.exe would expand a %NAME% in the path, even between double quotes, when NAME is a defined environment
		// variable, and run another program, if there is one at the path that makes. Whether a name is defined is
		// not known in advance (cmd.exe inherits the environment, which putenv() changes), so no such path runs.
		if ($isWindows && ($throughCmd || !$procOpen) && strpos($executable, '%') !== false)
		{
			return null;
		}

		if ($procOpen)
		{
			if ($throughCmd)
			{
				$cmd = $this->getWindowsToolPath('System32\\cmd.exe');

				if ($cmd === null)
				{
					return null;
				}

				// /d: without the AutoRun commands of the registry. /s /c "…": the rest, without its outer quotes.
				$command = $this->quoteExecutablePath($cmd, PHP_OS_FAMILY) . ' /d /s /c "' . $command . '"';
			}

			$pipes   = [];
			$process = @proc_open(
				$command,
				[0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']],
				$pipes,
				null,
				null,
				['bypass_shell' => true]
			);

			if (!is_resource($process))
			{
				return null;
			}

			$stdout = (string) @stream_get_contents($pipes[1]);

			@fclose($pipes[1]);
			$exitCode = @proc_close($process);

			return array_map('rtrim', preg_split('/\r?\n/', rtrim($stdout)));
		}

		if (!function_exists('exec'))
		{
			return null;
		}

		$output = [];

		if (@exec($command . ' 2>' . $this->getNullDevice(), $output, $resultCode) === false)
		{
			return null;
		}

		$exitCode = $resultCode;

		return $output;
	}

	/**
	 * Quote the path to an executable, so that it is run as one word whatever characters it contains (spaces in
	 * C:\Program Files\… or /Applications/MAMP PRO/…, quotes, $, &, …).
	 *
	 * On Windows the binary is normally run without cmd.exe (see runVersionCommand()), and the command line is split
	 * by the C runtime's rules, for which plain double quotes are enough. Through exec(), PHP runs commands with
	 * `cmd.exe /c "<command>"` (PHP 7) or `cmd.exe /s /c "<command>"` (PHP 8), both of which strip the outer pair of
	 * quotes and run the rest verbatim, so plain double quotes work there too, save for a %NAME% of a defined
	 * environment variable, which cmd.exe expands (runProgram() never runs a path with a % through exec()).
	 * escapeshellarg() is not used because it turns the %, ! and "
	 * characters into spaces, and the first two are legal in a Windows path. A double quote is not, so it is turned
	 * into a space, as escapeshellarg() does, so that it can never end the quoted string early.
	 *
	 * Everywhere else, escapeshellarg() single-quotes the path for /bin/sh.
	 *
	 * @param   string  $path      The path to the executable.
	 * @param   string  $osFamily  The OS family, as in PHP_OS_FAMILY.
	 *
	 * @return  string
	 * @since   1.0.2
	 */
	private function quoteExecutablePath(string $path, string $osFamily): string
	{
		if (strtoupper($osFamily) === 'WINDOWS')
		{
			return '"' . str_replace('"', ' ', $path) . '"';
		}

		return escapeshellarg($path);
	}

	/**
	 * Get Windows PHP paths for a specific Program Files directory
	 *
	 * @param   string       $pathPrefix
	 * @param   string|null  $version
	 *
	 * @return  array
	 * @since   1.0.0
	 */
	private function getWindowsProgramFilesPHPPaths(string $pathPrefix, ?string $version): array
	{
		$possiblePaths = [];

		if (!$pathPrefix)
		{
			return $possiblePaths;
		}

		[$major, $minor] = $this->getMajorMinor($version);

		$possiblePaths[rtrim($pathPrefix, '\\') . '\\PHP'] = 1;

		if ($minor !== null)
		{
			$possiblePaths[rtrim($pathPrefix, '\\') . '\\PHP' . $major . $minor]       = 0;
			$possiblePaths[rtrim($pathPrefix, '\\') . '\\PHP' . $major . '.' . $minor] = 0;
			$possiblePaths[rtrim($pathPrefix, '\\') . '\\PHP' . $version]              = 0;
		}

		return $possiblePaths;
	}

	/**
	 * Checks whether a command exists in the user's PATH, and points to an executable file.
	 *
	 * @param   string  $command  The command to check for.
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	private function executableExists(string $command): bool
	{
		$path = getenv('PATH');

		if (empty($path))
		{
			return false;
		}

		$directories = explode(PATH_SEPARATOR, $path);

		foreach ($directories as $directory)
		{
			$fullPath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $command;

			if ($this->isExecutable($fullPath))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The OS family PHPFinder runs on, in upper case: WINDOWS, BSD, DARWIN, SOLARIS, LINUX or UNKNOWN.
	 *
	 * @return  string
	 * @since   1.0.2
	 */
	private function getOsFamily(): string
	{
		return strtoupper($this->osFamily ?? PHP_OS_FAMILY);
	}

	/**
	 * Whether this very file is executable.
	 *
	 * Not the same file with .exe, .com or .bat appended on Windows: the path given is what would run. (On Windows,
	 * PHP's is_executable() is TRUE only for a program file, whatever its extension, never for a .bat or .cmd file.)
	 *
	 * @param   string  $fullPath  The path to the file
	 *
	 * @return  bool
	 * @since   1.0.2
	 */
	private function isExecutable(string $fullPath): bool
	{
		return @is_executable($fullPath);
	}

	/**
	 * Whether a path is a file which could be a PHP CLI binary: the one check every candidate passes, both before
	 * getPossiblePaths() returns it and before analyzePHPVersion() runs it, whichever search strategy (or caller) it
	 * came from.
	 *
	 * Everywhere, it must be a file. On Windows, it must also be a Windows program file named .exe (see
	 * isWindowsProgramFile()).
	 *
	 * @param   string  $path  The path
	 *
	 * @return  bool
	 * @since   1.0.2
	 */
	private function isCandidateFile(string $path): bool
	{
		if ($path === '' || !@is_file($path))
		{
			return false;
		}

		return $this->getOsFamily() !== 'WINDOWS' || $this->isWindowsProgramFile($path);
	}

	/**
	 * Whether a path, on Windows, names a program file PHP's CLI binary could be: its name ends in .exe, in any case,
	 * AND the file is a Windows program (a PE image, see isPeImage()). Both must hold.
	 *
	 * `where php` lists every file named php with an extension in PATHEXT, and php itself: php.bat, php.cmd, php.com,
	 * an extensionless php (e.g. a shell script for Cygwin or Git Bash). None of them is PHP's CLI binary, and an
	 * application which runs the path it is given would run them. Windows runs a .bat or .cmd file through cmd.exe (the
	 * one %ComSpec% names), whose quoting rules differ from a program's and which expands %NAME% in its command line.
	 * A program under another name (php.com, php.bat, php) is refused too: what runs a path decides by its extension
	 * how to run it, and only .exe is what PHP's CLI binary is called.
	 *
	 * A php.exe which is not a program (a text file, an empty file, a bare MS-DOS header) is refused before anything
	 * tries to run it. PHP's is_executable() is not enough: on Windows it asks GetBinaryTypeW(), which is TRUE for a
	 * text file named php.exe, and for a file with nothing but an MZ header (Windows Server 2022, PHP 7.2 to 8.6).
	 *
	 * @param   string  $path  The path
	 *
	 * @return  bool
	 * @since   1.0.2
	 */
	private function isWindowsProgramFile(string $path): bool
	{
		if (strtolower(substr($path, -4)) !== '.exe')
		{
			return false;
		}

		return $this->peImageCheck === null ? $this->isPeImage($path) : (bool) call_user_func($this->peImageCheck, $path);
	}

	/**
	 * Whether a file is a Windows program (a PE image): the MZ signature at its start, and the PE signature (PE, two
	 * NUL bytes) at the offset the 32-bit little-endian value at 0x3C gives, which is past the 64-byte MS-DOS header.
	 *
	 * Only its first bytes are read. A file which cannot be opened or read, or is too short, is not a program, without
	 * any warning.
	 *
	 * @param   string  $path  The path to the file
	 *
	 * @return  bool
	 * @since   1.0.2
	 */
	private function isPeImage(string $path): bool
	{
		$handle = @fopen($path, 'rb');

		if ($handle === false)
		{
			return false;
		}

		$isPe   = false;
		$header = @fread($handle, 64);

		if (is_string($header) && strlen($header) === 64 && substr($header, 0, 2) === 'MZ')
		{
			// e_lfanew. A value of 2 GiB or more is negative on 32-bit PHP, and is refused with the rest.
			$offset = unpack('V', substr($header, 0x3C, 4))[1];

			if ($offset >= 64 && @fseek($handle, $offset) === 0)
			{
				$isPe = @fread($handle, 4) === "PE\0\0";
			}
		}

		@fclose($handle);

		return $isPe;
	}
}
