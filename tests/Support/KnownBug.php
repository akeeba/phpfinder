<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

/**
 * An "expected failure" for bugs in src/ which are documented, but deliberately not fixed yet.
 *
 * The assertions describe the CORRECT behaviour. While the bug is present they fail, and the test is
 * reported as incomplete with the diagnosis, so the suite stays green without asserting the buggy
 * behaviour as correct. Once the bug is fixed the assertions pass, and the test FAILS, telling you to
 * remove the marker so the test guards the fix from then on.
 */
trait KnownBug
{
	/**
	 * @param   string    $diagnosis   What is wrong in src/, and where.
	 * @param   callable  $assertions  Assertions describing the correct behaviour.
	 * @param   bool      $applies     FALSE when the bug cannot manifest in this environment (e.g. it
	 *                                 only exists on some PHP versions); the assertions then run as a
	 *                                 normal test.
	 *
	 * @return  void
	 */
	protected function expectKnownBug(string $diagnosis, callable $assertions, bool $applies = true): void
	{
		if (!$applies)
		{
			$assertions();

			return;
		}

		try
		{
			$assertions();
		}
		catch (AssertionFailedError $e)
		{
			$this->markTestIncomplete(
				'KNOWN BUG in src/: ' . $diagnosis . "\n" . 'Failed as expected: ' . $e->getMessage()
			);
		}

		$this->fail(
			'This known bug appears to be fixed: ' . $diagnosis
			. "\nRemove the expectKnownBug() wrapper so this test guards the fix."
		);
	}
}
