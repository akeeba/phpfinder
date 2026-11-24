<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Integration;

use Akeeba\PHPFinder\Tests\Support\KnownBug;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the integration tests.
 *
 * PHPUnit runs on the host. Each test asks fixture/probe.php, inside the `php` container, to run
 * PHPFinder with a given configuration, version and PATH, under the container's own PHP (the "runner"),
 * against the real PHP binaries provision.sh installed (fixture/fixtures.txt). Nothing here loads the
 * library into PHPUnit's process.
 */
abstract class AbstractIntegrationTestCase extends TestCase
{
	use KnownBug;

	/** The PATH of the akeeba-e2e/php images. */
	protected const PATH_DEFAULT = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

	/** The same, plus the directory only `which` can find fixtures in. */
	protected const PATH_WITH_WHICH_DIR = '/opt/pf-which/bin:' . self::PATH_DEFAULT;

	/** The runner: the container's own PHP CLI, also PHP_BINARY inside the probe. */
	protected const RUNNER = '/usr/local/bin/php';

	// The fixtures, see fixture/fixtures.txt.
	protected const WHICH_82          = '/opt/pf-which/bin/php82';
	protected const WHEREIS_82        = '/usr/local/games/php82';
	protected const DOTTED_82         = '/usr/bin/php8.2';
	protected const UNIX_83           = '/opt/bin/php83';
	protected const FPM_83            = '/usr/bin/php83';
	protected const CLOUDLINUX_83     = '/opt/cloudlinux/alt-php83/root/usr/bin/php';
	protected const MISLABELLED_84    = '/opt/bin/php84';
	protected const EXTENDED_84       = '/usr/local/bin/php84-cli';
	protected const CPANEL_84         = '/opt/cpanel/ea-php84/root/usr/bin/php';
	protected const ALTERNATIVES_84   = '/opt/pf-ua/php84';

	/** @var array|null */
	private static $runnerInfo;

	protected function setUp(): void
	{
		// run.sh refuses such a runner too; this covers PHPUnit being run by hand against a kept-up stack.
		if (in_array($this->runner(), ['8.2', '8.3', '8.4'], true))
		{
			$this->fail(sprintf(
				'The runner is PHP %s, one of the fixture versions: its /usr/local/bin/php would shadow the fixtures.',
				$this->runner()
			));
		}
	}

	/**
	 * Run `docker compose` against this suite's compose file.
	 *
	 * @param   string[]  $args
	 *
	 * @return  array{status: int, output: string}
	 */
	public static function dockerCompose(array $args): array
	{
		$composeFile = getenv('PHPFINDER_E2E_COMPOSE_FILE') ?: (__DIR__ . '/docker/docker-compose.yml');
		$command     = 'docker compose -f ' . escapeshellarg($composeFile);

		foreach ($args as $arg)
		{
			$command .= ' ' . escapeshellarg($arg);
		}

		$output = [];
		$status = 0;

		exec($command . ' 2>&1', $output, $status);

		return ['status' => $status, 'output' => implode("\n", $output)];
	}

	/**
	 * A configuration with every search strategy OFF, so that a test can switch on exactly the one it is
	 * about. Validation stays on.
	 */
	protected static function isolated(array $overrides = []): array
	{
		return array_merge(
			[
				'useConstants'              => false,
				'osSpecific'                => true,
				'softwareSpecific'          => false,
				'extendedBinaryNameSearch'  => true,
				'useWhich'                  => false,
				'useUpdateAlternatives'     => false,
				'useWhereIs'                => false,
				'useWhere'                  => false,
				'usePHPCommonWinDirsSearch' => false,
				'validateVersion'           => true,
				'validateCli'               => true,
			],
			$overrides
		);
	}

	/**
	 * Run PHPFinder inside the container.
	 *
	 * @param   string       $call     getBestPath, getBestPathMeta or getPossiblePaths
	 * @param   string|null  $version  The version to look for
	 * @param   array        $config   Configuration overrides; [] is the library's defaults
	 * @param   string|null  $path     The PATH to run with; NULL keeps the container's
	 *
	 * @return  array  The probe's response: result, diagnostics, runner, runnerVersion, phpBinary
	 */
	protected function probe(string $call, ?string $version, array $config = [], ?string $path = null): array
	{
		$request = base64_encode(json_encode([
			'call'    => $call,
			'version' => $version,
			'config'  => (object) $config,
			'path'    => $path,
		]));

		$run      = self::dockerCompose(['exec', '-T', 'php', 'php', '/app/fixture/probe.php', $request]);
		$response = json_decode($run['output'], true);

		$this->assertSame(0, $run['status'], "probe.php failed:\n" . $run['output']);
		$this->assertIsArray($response, "probe.php did not answer with JSON:\n" . $run['output']);

		return $response;
	}

	/**
	 * getBestPath(), asserting that the library raised no PHP diagnostics on this runner.
	 */
	protected function bestPath(?string $version, array $config = [], ?string $path = null): ?string
	{
		$response = $this->probe('getBestPath', $version, $config, $path);

		$this->assertNoDiagnostics($response);

		return $response['result'];
	}

	protected function assertNoDiagnostics(array $response): void
	{
		$this->assertSame(
			[],
			$response['diagnostics'],
			sprintf(
				"PHPFinder raised PHP diagnostics on PHP %s:\n%s",
				$response['runnerVersion'],
				implode("\n", $response['diagnostics'])
			)
		);
	}

	/**
	 * The runner's major.minor version, e.g. 8.6.
	 */
	protected function runner(): string
	{
		return $this->runnerInfo()['runner'];
	}

	protected function runnerInfo(): array
	{
		if (self::$runnerInfo === null)
		{
			self::$runnerInfo = $this->probe('getPossiblePaths', null, self::isolated());
		}

		return self::$runnerInfo;
	}

	/**
	 * Run a binary in the container with -v and return the first line of its output.
	 */
	protected function banner(string $binary): string
	{
		$run = self::dockerCompose(['exec', '-T', 'php', $binary, '-v']);

		$this->assertSame(0, $run['status'], "$binary -v failed:\n" . $run['output']);

		return strtok($run['output'], "\n");
	}

	/**
	 * Assert that a fixture really is a PHP of the given version and SAPI, so that a test which expects
	 * a known bug cannot be passing because of a broken fixture instead.
	 */
	protected function assertFixture(string $binary, string $version, string $sapi = 'cli'): void
	{
		$this->assertStringStartsWith(
			'PHP ' . $version . '.',
			$this->banner($binary),
			"Fixture $binary is not PHP $version"
		);
		$this->assertStringContainsString('(' . $sapi . ')', $this->banner($binary), "Fixture $binary is not $sapi");
	}
}
