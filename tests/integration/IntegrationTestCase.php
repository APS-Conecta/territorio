<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\FeatureService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for tests that write to the real database.
 *
 * The cleanup here is the one place in the repo that hard-deletes a Feature, and
 * it does so with raw SQL rather than through `FeatureMapper::delete()`, which
 * refuses. That is deliberate: ADR-0005 forbids destroying a Feature *in the
 * app*, and weakening the guard so tests could tidy up would defeat the point of
 * having it. Tidying up after a test run is not the app.
 */
abstract class IntegrationTestCase extends TestCase {
	/** @var int[] rows this test made, to be removed afterwards */
	protected array $created = [];

	protected function tearDown(): void {
		if ($this->created === []) {
			return;
		}

		$db = Server::get(IDBConnection::class);
		// Revisions first: they reference the Feature, and leaving them behind
		// would let a later test read history for a record that no longer exists.
		foreach (['territorio_revision' => 'feature_id', 'territorio_feature' => 'id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter(
					$this->created, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
		}

		$this->created = [];
	}

	/** Any Subcategory will do where the test is not about the taxonomy. */
	protected function anySubcategoryId(): int {
		$subcategories = Server::get(SubcategoryMapper::class)->findAll();
		self::assertNotEmpty($subcategories, 'the seed should have created the taxonomy');

		return (int)$subcategories[0]->getId();
	}

	/** The seeded Subcategory with this slug; fails rather than guesses. */
	protected function subcategoryIdBySlug(string $slug): int {
		foreach (Server::get(SubcategoryMapper::class)->findAll() as $subcategory) {
			if ($subcategory->getSlug() === $slug) {
				return (int)$subcategory->getId();
			}
		}

		self::fail("the seed should have created the \"$slug\" subcategory");
	}

	/** A Place at a real La Florida coordinate, registered for cleanup. */
	protected function place(string $name, array $extra = []): int {
		$id = (int)Server::get(FeatureService::class)->create(array_merge([
			'name' => $name,
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
		], $extra))->getId();
		$this->created[] = $id;

		return $id;
	}
}
