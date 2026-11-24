<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

/**
 * Bootstrap for phpunit-integration.xml. Loads no library code into PHPUnit's process for the tests to
 * use (they drive the library inside the container), and checks that a provisioned stack is running, so
 * that running PHPUnit by hand without one fails with one clear message instead of one per test.
 */

require __DIR__ . '/../bootstrap.php';

use Akeeba\PHPFinder\Tests\Integration\AbstractIntegrationTestCase;

$probe = AbstractIntegrationTestCase::dockerCompose(['exec', '-T', 'php', 'test', '-x', '/opt/pf-ua/php84']);

if ($probe['status'] !== 0)
{
	fwrite(
		STDERR,
		"\nThe PHPFinder integration stack is not running, or not provisioned.\n"
		. "Run tests/Integration/docker/run.sh (or `composer test:integration`); it provisions the stack, runs\n"
		. "this suite and tears the stack down. To iterate, keep it up with:\n\n"
		. "    tests/Integration/docker/run.sh --keep-containers\n"
		. "    phpunit -c phpunit-integration.xml --filter SomeTest\n\n"
		. trim($probe['output']) . "\n"
	);

	exit(1);
}
