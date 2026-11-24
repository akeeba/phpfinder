<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

/**
 * Bootstrap shared by the unit suite (phpunit.xml) and the integration suite (phpunit-integration.xml).
 *
 * It registers its own PSR-4 autoloader instead of relying on Composer's, and PREPENDS it, so that it
 * wins over vendor/autoload.php (which PHPUnit's own binary may already have loaded).
 *
 * PHPFINDER_SRC points the suite at a different copy of the library, e.g. an older revision exported
 * with `git show <rev>:src/…`, to show a regression test failing on the code before a fix:
 *
 *     PHPFINDER_SRC=/some/where/old-src phpunit --filter Regression
 */

$srcDir = getenv('PHPFINDER_SRC') ?: (__DIR__ . '/../src');

if (!is_file($srcDir . '/PHPFinder.php'))
{
	fwrite(STDERR, sprintf("PHPFinder sources not found in %s\n", $srcDir));

	exit(1);
}

$prefixes = [
	'Akeeba\\PHPFinder\\Tests\\' => __DIR__,
	'Akeeba\\PHPFinder\\'        => realpath($srcDir),
];

spl_autoload_register(
	function ($class) use ($prefixes) {
		foreach ($prefixes as $prefix => $dir)
		{
			if (strpos($class, $prefix) !== 0)
			{
				continue;
			}

			$file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

			if (is_file($file))
			{
				require_once $file;
			}

			return;
		}
	},
	true,
	true
);

if (getenv('PHPFINDER_SRC'))
{
	fwrite(STDERR, sprintf("NOTE: testing the PHPFinder sources in %s\n", realpath($srcDir)));
}
