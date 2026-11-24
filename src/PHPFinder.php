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
	 * The Windows drives, once getWindowsDrives() has looked them up for this object.
	 *
	 * @var    string[]|null
	 * @since  1.0.2
	 */
	private $windowsDrives = null;

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
				return !empty($filePath) && @is_file($filePath);
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

		if (!function_exists('exec') || !$this->executableExists('where'))
		{
			return [];
		}

		$possibleExecutables = $this->getBinaryNamesForVersion($version);
		$ret                 = [];

		foreach ($possibleExecutables as $executable)
		{
			// exec() returns only the last line, and appends to $output: start afresh for each name.
			$output = [];
			$where  = @exec('where ' . escapeshellarg($executable), $output, $resultCode);

			if ($where === false || $resultCode !== 0)
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
		// directories (depth 0) first.
		asort($possiblePaths);

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
	 * Available on Linux. Very fast.
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
				// Binary found in %plesk_dir%Additional\PHPXX\php.exe
				$pleskDir = @getenv('PLESK_DIR');

				if (empty($pleskDir))
				{
					return [];
				}

				return [
					rtrim($pleskDir, '\\') . '\\Additional\\PHP' . $suffix . '\\php.exe',
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

		if (function_exists('exec'))
		{
			$commands = [
				'wmic'       => 'wmic logicaldisk get name',
				'fsutil'     => 'fsutil fsinfo drives',
				'powershell' => 'powershell -NoProfile -NonInteractive -Command '
				                . '"[IO.DriveInfo]::GetDrives() | ForEach-Object { $_.Name }"',
			];

			foreach ($commands as $executable => $command)
			{
				if (!$this->executableExists($executable))
				{
					continue;
				}

				$output = [];

				// Their errors go to STDERR, which would otherwise end up in the console of a CLI application.
				@exec($command . ' 2>' . $this->getNullDevice(), $output);

				$drives = $this->parseWindowsDrives($output);

				if (!empty($drives))
				{
					break;
				}
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

		if (!function_exists('exec'))
		{
			return $ret;
		}

		$result = @file_exists($path)
		          && $this->isExecutable($path)
		          && @exec($this->quoteExecutablePath($path, $this->getOsFamily()) . ' -v', $output);

		if (!$result)
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
	 * Quote the path to an executable, so that it is run as one word whatever characters it contains (spaces in
	 * C:\Program Files\… or /Applications/MAMP PRO/…, quotes, $, &, …).
	 *
	 * On Windows, PHP runs commands through `cmd.exe /c "<command>"` (PHP 7) or `cmd.exe /s /c "<command>"`
	 * (PHP 8), both of which strip the outer pair of quotes and run the rest verbatim, so the executable only needs
	 * plain double quotes. escapeshellarg() is not used there because it turns the %, ! and " characters into
	 * spaces, and the first two are legal in a Windows path. A double quote is not, so it is turned into a space,
	 * as escapeshellarg() does, so that it can never end the quoted string early. Limitation: cmd.exe still
	 * expands %NAME% inside double quotes when NAME is a defined environment variable, and that cannot be escaped
	 * on a cmd.exe command line.
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

	private function isExecutable(string $fullPath): bool
	{
		if (@is_executable($fullPath))
		{
			return true;
		}

		if (
			($this->getOsFamily() === 'WINDOWS')
			&& (
				@is_executable($fullPath . '.exe')
				|| @is_executable($fullPath . '.com')
				|| @is_executable($fullPath . '.bat')
			)
		)
		{
			return true;
		}

		return false;
	}
}