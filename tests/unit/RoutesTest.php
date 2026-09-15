<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What this app is reachable as, from where.
 *
 * A routing policy, so it lives in a routing test — asserting it inside a
 * service's unit test meant an unrelated app adding an OCS route would fail with
 * a message about the Registry service.
 */
final class RoutesTest extends TestCase {
	/** @return array<string, mixed> */
	private static function routes(): array {
		return require dirname(__DIR__, 2) . '/appinfo/routes.php';
	}

	/**
	 * Issue #18: "No OCS route and no externally-reachable API route is
	 * registered."
	 *
	 * Territorio answers other apps on this instance by injection
	 * (`RegistryService`), which needs no route at all. An `ocs` section is the
	 * mechanism that would put those reads on the public internet, and adding one
	 * should be a decision somebody takes deliberately — not something that
	 * arrives by copying a route block.
	 */
	public function testNoOcsSectionIsRegistered(): void {
		self::assertArrayNotHasKey(
			'ocs',
			self::routes(),
			'an OCS section exposes routes outside the instance; #18 says there are none',
		);
	}

	/**
	 * Every route is a page or an app-internal endpoint the browser calls. The
	 * point of listing them is that adding one to this app is visible here, in a
	 * test somebody has to update on purpose.
	 */
	public function testEveryRouteIsOneTheAppItselfCalls(): void {
		$names = array_column(self::routes()['routes'], 'name');

		self::assertSame([
			'page#index',
			'feature#create',
			'feature#update',
			'feature#retired',
			'feature#destroy',
			'feature#restore',
			'feature#history',
			'import#upload',
			'import#batches',
			'import#revert',
			'boundary#inView',
			'duplicate#index',
			'duplicate#merge',
			'duplicate#dismiss',
			'export#plain',
			'export#withContacts',
			'export#history',
			'changes#since',
			'category#create',
			'category#rename',
			'category#addSubcategory',
			'category#renameSubcategory',
			'adminSettings#save',
			'adminSettings#saveComuna',
		], $names);
	}
}
