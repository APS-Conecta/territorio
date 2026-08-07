<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Controllers hold no query logic (issue #18).
 *
 * The rule made checkable: a controller may inject Services, never `Db\`
 * classes. A mapper in a constructor is how query logic gets into a controller,
 * and the reason it matters is stated in `FeatureController`'s own docblock —
 * "all the judgement is in FeatureService, which is what makes an OCS controller
 * later a new file rather than a rewrite". A controller that reads the database
 * itself is one an OCS version would have to copy.
 *
 * Two controllers were doing exactly that when this was written: ImportController
 * joined batches to Datasets inline, and PageController injected RevisionLog.
 */
final class ControllerBoundaryTest extends TestCase {
	/** @return list<array{string, string}> */
	public static function controllers(): array {
		$dir = dirname(__DIR__, 3) . '/lib/Controller';

		return array_map(
			static fn (string $path): array => [basename($path), (string)file_get_contents($path)],
			glob($dir . '/*.php') ?: [],
		);
	}

	/**
	 * @dataProvider controllers
	 */
	public function testAControllerInjectsNoMapperOrDbClass(string $name, string $source): void {
		preg_match('/public function __construct\((.*?)\)\s*\{/s', $source, $constructor);
		$arguments = $constructor[1] ?? '';

		self::assertDoesNotMatchRegularExpression(
			'/\bDb\\\\\w+\s+\$/',
			$arguments,
			sprintf('%s injects a Db class; the query belongs in a Service', $name),
		);

		foreach (['Mapper ', 'RevisionLog '] as $suspect) {
			self::assertStringNotContainsString(
				$suspect . '$',
				$arguments,
				sprintf('%s injects %s; the query belongs in a Service', $name, trim($suspect)),
			);
		}
	}

	/** And no controller builds a query of its own, either. */
	public function testNoControllerTouchesTheDatabaseDirectly(): void {
		foreach (self::controllers() as [$name, $source]) {
			self::assertStringNotContainsString('getQueryBuilder', $source, $name);
			self::assertStringNotContainsString('IDBConnection', $source, $name);
		}
	}
}
