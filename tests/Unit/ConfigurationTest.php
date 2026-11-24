<?php
/**
 * PHPFinder – Locate the PHP CLI binary on the server.
 *
 * @package   PHPFinder
 * @copyright (c) 2024-2026 Akeeba Ltd
 * @license   MIT
 */

namespace Akeeba\PHPFinder\Tests\Unit;

use Akeeba\PHPFinder\Configuration;
use Akeeba\PHPFinder\Tests\Support\KnownBug;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
	use KnownBug;

	/**
	 * Every documented property, with its documented default.
	 */
	private const DEFAULTS = [
		'useConstants'              => true,
		'osSpecific'                => true,
		'softwareSpecific'          => true,
		'extendedBinaryNameSearch'  => true,
		'useWhich'                  => true,
		'useUpdateAlternatives'     => true,
		'useWhereIs'                => false,
		'useWhere'                  => true,
		'usePHPCommonWinDirsSearch' => true,
		'validateVersion'           => true,
		'validateCli'               => true,
	];

	public function testDefaults(): void
	{
		foreach ([new Configuration(), Configuration::make(), Configuration::make([])] as $config)
		{
			foreach (self::DEFAULTS as $property => $default)
			{
				$this->assertSame($default, $config->{$property}, "Default of $property");
			}
		}
	}

	public function testMakeAppliesTheGivenArray(): void
	{
		$config = Configuration::make(['useWhich' => false, 'useWhereIs' => true, 'validateCli' => false]);

		$this->assertFalse($config->useWhich);
		$this->assertTrue($config->useWhereIs);
		$this->assertFalse($config->validateCli);

		// Everything not given keeps its default.
		foreach (array_diff_key(self::DEFAULTS, array_flip(['useWhich', 'useWhereIs', 'validateCli'])) as $property => $default)
		{
			$this->assertSame($default, $config->{$property}, "Untouched $property");
		}
	}

	public function testMakeReturnsANewInstanceEveryTime(): void
	{
		$a = Configuration::make(['useWhich' => false]);
		$b = Configuration::make();

		$this->assertNotSame($a, $b);
		$this->assertTrue($b->useWhich);
	}

	public function testMakeRejectsAnUnknownKey(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Undefined property: Akeeba\PHPFinder\Configuration::$noSuchThing');

		Configuration::make(['noSuchThing' => true]);
	}

	public function testMakeCoercesValuesToBool(): void
	{
		$config = Configuration::make(['useWhich' => 'yes', 'useWhereIs' => 1, 'validateCli' => 0]);

		$this->assertSame(true, $config->useWhich);
		$this->assertSame(true, $config->useWhereIs);
		$this->assertSame(false, $config->validateCli);
	}

	public function testFluentSettersSetEveryPropertyAndChain(): void
	{
		$config = new Configuration();

		foreach (self::DEFAULTS as $property => $default)
		{
			$setter = 'set' . ucfirst($property);

			$this->assertSame($config, $config->{$setter}(!$default), "$setter() returns \$this");
			$this->assertSame(!$default, $config->{$property}, "$setter() changed $property");

			$this->assertSame($config, $config->{$setter}($default));
			$this->assertSame($default, $config->{$property}, "$setter() changed $property back");
		}

		$chained = Configuration::make()->setUseWhich(false)->setUseWhereIs(true)->setValidateVersion(false);

		$this->assertFalse($chained->useWhich);
		$this->assertTrue($chained->useWhereIs);
		$this->assertFalse($chained->validateVersion);
	}

	public function testFluentSettersCoerceToBool(): void
	{
		$config = new Configuration();

		foreach ([['yes', true], [1, true], ['1', true], [[1], true], [0, false], ['0', false], ['', false], [null, false], [[], false]] as $case)
		{
			[$value, $expected] = $case;

			$config->setUseWhich($value);

			$this->assertSame($expected, $config->useWhich, 'setUseWhich(' . var_export($value, true) . ')');
		}
	}

	public function testFluentSetterForAnUnknownPropertyThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Undefined property: Akeeba\PHPFinder\Configuration::$noSuchThing');

		(new Configuration())->setNoSuchThing(true);
	}

	public function testUndefinedMethodThrows(): void
	{
		$this->expectException(\BadMethodCallException::class);
		$this->expectExceptionMessage('Call to undefined method Akeeba\PHPFinder\Configuration::doSomething()');

		(new Configuration())->doSomething(true);
	}

	public function testGetterOfAnUnknownPropertyThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Undefined property: Akeeba\PHPFinder\Configuration::$noSuchThing');

		$config = new Configuration();
		$config->noSuchThing;
	}

	/**
	 * Regression test for a8cb387: __set() assigned the value, then fell through to the "Undefined
	 * property" exception, so ANY direct assignment from outside the class threw.
	 *
	 * The assignments must happen out here, in the test's scope. Configuration::make() writes the private
	 * properties from inside the class, never reaches __set(), and so never had the bug.
	 */
	public function testRegressionDirectAssignmentSetsTheValueWithoutThrowing(): void
	{
		$config = new Configuration();

		foreach (self::DEFAULTS as $property => $default)
		{
			try
			{
				$config->{$property} = !$default;
			}
			catch (\Throwable $e)
			{
				$this->fail(sprintf(
					'Assigning $config->%s threw %s: %s', $property, get_class($e), $e->getMessage()
				));
			}

			$this->assertSame(!$default, $config->{$property}, "Direct assignment of $property");
		}

		$config->softwareSpecific = true;
		$this->assertTrue($config->softwareSpecific);
	}

	public function testDirectAssignmentCoercesToBool(): void
	{
		$config = new Configuration();

		$config->useWhereIs = 'yes';
		$this->assertSame(true, $config->useWhereIs);

		$config->useWhich = 0;
		$this->assertSame(false, $config->useWhich);

		$config->validateCli = '';
		$this->assertSame(false, $config->validateCli);
	}

	public function testDirectAssignmentOfAnUnknownPropertyThrows(): void
	{
		$config = new Configuration();

		try
		{
			$config->noSuchThing = true;

			$this->fail('Assigning an undefined property did not throw.');
		}
		catch (\InvalidArgumentException $e)
		{
			$this->assertSame('Undefined property: Akeeba\PHPFinder\Configuration::$noSuchThing', $e->getMessage());
		}

		// Nothing leaked onto the object either.
		$this->assertArrayNotHasKey('noSuchThing', get_object_vars($config));
	}

	public function testIssetReportsTheDocumentedProperties(): void
	{
		$config = new Configuration();

		$this->assertTrue(isset($config->useWhich), 'isset($config->useWhich)');
		$this->assertFalse(empty($config->useWhich), 'empty($config->useWhich) when it is TRUE');
		$this->assertFalse(isset($config->noSuchThing), 'isset() of an unknown property');
	}

	public function testTheClassIsFinal(): void
	{
		$this->assertTrue((new \ReflectionClass(Configuration::class))->isFinal());
	}
}
