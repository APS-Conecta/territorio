<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Service\FeatureService;
use OCA\Territorio\Service\RegistryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Server;

/**
 * The criterion a mocked test cannot reach: that another app can actually get
 * hold of this service.
 *
 * `Server::get()` is what an app's container does when it autowires a
 * constructor argument, so resolving the class this way — from outside
 * Territorio's own controllers, with nothing registered by hand — is the same
 * question as "can the gestion app inject it".
 */
final class RegistryServiceIntegrationTest extends IntegrationTestCase {
	public function testAnotherAppCanResolveItAndAskForCounts(): void {
		$counts = Server::get(RegistryService::class)->counts();

		self::assertIsInt($counts['total']);
		self::assertArrayHasKey('salud', $counts['categories']);
		self::assertArrayHasKey('cesfam', $counts['categories']['salud']['subcategories']);
	}

	/** ADR-0005, asked through the service rather than through a screen. */
	public function testARetiredRecordLeavesTheServiceToo(): void {
		$id = $this->place('Plaza que se retira');
		$registry = Server::get(RegistryService::class);

		$before = $registry->counts()['total'];
		self::assertNotNull($registry->find($id));

		Server::get(FeatureService::class)->retire($id);

		self::assertSame($before - 1, $registry->counts()['total']);
		self::assertNull($registry->find($id), 'a retired record is still shown to another app');
	}

	/**
	 * A comuna's streets are Features, and eleven thousand of them would drown
	 * any count another app asked for. They are absent here for the same reason
	 * they are absent from the map: the service reads the same set, rather than
	 * restating the rule in a query of its own.
	 */
	public function testBoundaryFeaturesAreInvisibleToTheService(): void {
		try {
			$dataset = Server::get(DatasetMapper::class)->findBySlug('limites-smoke');
		} catch (DoesNotExistException) {
			self::markTestSkipped('no boundary Dataset on this instance to check against');
		}

		$boundaries = Server::get(FeatureMapper::class)->findByDataset((int)$dataset->getId());
		self::assertNotEmpty($boundaries, 'the fixture Dataset should hold something');

		$registry = Server::get(RegistryService::class);
		foreach (array_slice($boundaries, 0, 5) as $boundary) {
			self::assertNull(
				$registry->find((int)$boundary->getId()),
				'a boundary feature is reachable through the service',
			);
		}
	}
}
