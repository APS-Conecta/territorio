<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every class under lib/ declares dependencies the container can resolve.
 *
 * The container hands a parameter its default when it cannot resolve the
 * parameter's type, so a class-typed dependency defaulted to null turns a
 * missing binding into a null that nothing reports. A dependency is declared
 * required instead, and an unresolvable one fails loudly at construction.
 * A scalar defaulted to null (`?string $x = null`) is not a dependency.
 *
 * `OCP\ILogger` declares only the log level constants since Nextcloud 31, and
 * the container binds nothing to it; logging goes through
 * `Psr\Log\LoggerInterface`, as RemoveRetiredTileSettings does.
 */
final class ContainerDependencyTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function libSources(): array {
		$root = dirname(__DIR__, 2);
		$sources = [];

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root . '/lib'),
		);

		foreach ($files as $file) {
			if ($file->getExtension() === 'php') {
				$path = substr($file->getPathname(), strlen($root) + 1);
				$sources[$path] = [$path, (string)file_get_contents($file->getPathname())];
			}
		}

		return $sources;
	}

	/**
	 * @dataProvider libSources
	 */
	public function testNoClassReferencesOcpILogger(string $path, string $source): void {
		self::assertDoesNotMatchRegularExpression('/OCP\\\\ILogger\b/', $source, $path);
	}

	/**
	 * @dataProvider libSources
	 */
	public function testNoConstructorDefaultsAClassTypedParameterToNull(string $path, string $source): void {
		preg_match('/function __construct\s*\((.*?)\)\s*\{/s', $source, $constructor);

		self::assertDoesNotMatchRegularExpression(
			'/\??\\\\?\b[A-Z][\w\\\\]*\s+\$\w+\s*=\s*null\b/',
			$constructor[1] ?? '',
			$path,
		);
	}
}
