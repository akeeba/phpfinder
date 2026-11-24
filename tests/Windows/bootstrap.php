<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

/**
 * Bootstrap for phpunit-windows.xml. Loads no library code into PHPUnit's process for the tests to use (they drive
 * the library inside the Windows VM), and checks that a provisioned VM is running, so that running PHPUnit by hand
 * without one fails with one clear message instead of one per test.
 */

require __DIR__ . '/../bootstrap.php';

$host = getenv('PHPFINDER_WINDOWS_HOST');

$howTo = "Run tests/Windows/run.sh --host=HOST [--vm=VM] (or `composer test:windows`, with WINDOWS_KVM_HOST set); it\n"
	. "starts the VM, provisions it, runs this suite once per runner and stops the VM. To iterate, keep it up with:\n\n"
	. "    tests/Windows/run.sh --host=HOST --vm=VM --keep-vm\n"
	. "    PHPFINDER_WINDOWS_HOST=HOST PHPFINDER_WINDOWS_VM=VM PHPFINDER_WINDOWS_RUNNER=8.5 phpunit -c phpunit-windows.xml --filter SomeTest\n";

if (empty($host))
{
	fwrite(STDERR, "\nPHPFINDER_WINDOWS_HOST is not set.\n" . $howTo);

	exit(1);
}

$output = [];
$status = 0;
exec(
	\Akeeba\PHPFinder\Tests\Windows\AbstractWindowsTestCase::vmCommand($host) . ' ssh '
	. escapeshellarg('Test-Path C:\\pf\\run\\8.4\\php.exe') . ' 2>&1',
	$output,
	$status
);

if ($status !== 0 || trim(implode("\n", $output)) !== 'True')
{
	fwrite(
		STDERR,
		"\nThe " . (getenv('PHPFINDER_WINDOWS_VM') ?: 'iis') . " Windows VM on $host is not running, or not provisioned.\n" . $howTo . "\n" . trim(implode("\n", $output)) . "\n"
	);

	exit(1);
}
