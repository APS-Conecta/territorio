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
	 * Every ApiRoute attribute on ApiController, as [verb, url] — the surface
	 * that registers by attribute, which routes.php cannot see.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function apiRoutes(): array {
		$routes = [];
		$controller = new \ReflectionClass(\OCA\Territorio\Controller\ApiController::class);
		foreach ($controller->getMethods() as $method) {
			foreach ($method->getAttributes(\OCP\AppFramework\Http\Attribute\ApiRoute::class) as $attribute) {
				// getArguments(), not newInstance(): the promoted $verb/$url are
				// protected, and the raw named arguments carry the same two facts
				// this tripwire asserts.
				$args = $attribute->getArguments();
				$routes[] = [$args['verb'], $args['url']];
			}
		}
		sort($routes);

		return $routes;
	}

	/**
	 * ADR-0020: the OCS surface is exactly ApiController's three ApiRoute
	 * routes — counts, features/{id}, subcategories/{category}/{subcategory}/
	 * features — registered by attribute, never through routes.php. Issue #18
	 * said "no OCS route at all"; ADR-0020 amends that to "exactly these three,
	 * on purpose", and this tripwire keeps it that way: anything else appearing
	 * as an attribute, or an `ocs` section arriving in routes.php, fails here.
	 */
	public function testOcsSurfaceIsExactlyThePublishedThree(): void {
		self::assertArrayNotHasKey(
			'ocs',
			self::routes(),
			"an OCS section in routes.php bypasses ApiController's attribute registration; ADR-0020 says the surface is the three ApiRoute routes",
		);

		self::assertSame(
			[
				['GET', '/api/v1/counts'],
				['GET', '/api/v1/features/{id}'],
				['GET', '/api/v1/subcategories/{category}/{subcategory}/features'],
			],
			self::apiRoutes(),
			'the published OCS surface changed — ADR-0020 names exactly these three',
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
			'boundary#names',
			'duplicate#index',
			'duplicate#merge',
			'duplicate#dismiss',
			'export#plain',
			'export#withContacts',
			'export#history',
			'changes#since',
			'category#create',
			'category#update',
			'category#addSubcategory',
			'category#renameSubcategory',
			'category#destroy',
			'category#destroySubcategory',
			'adminSettings#save',
			'adminSettings#saveComuna',
		], $names);
	}
}
