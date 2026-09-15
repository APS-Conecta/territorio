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
	/** A closed ring in La Florida, as geoman hands one over. */
	protected const BOUNDARY = [
		'type' => 'Polygon',
		'coordinates' => [[
			[-70.60, -33.53],
			[-70.57, -33.53],
			[-70.57, -33.51],
			[-70.60, -33.51],
			[-70.60, -33.53],
		]],
	];

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

	/**
	 * A Sector: the boundary Polygon of `self::BOUNDARY`, registered for cleanup.
	 *
	 * Four tests had a copy of this helper apiece, differing only in which field
	 * they hard-coded and which they took as an argument (issue #60). `$extra`
	 * is the same door `place()` opens: pass `geometry`, `colour` or `limit` to
	 * override a default. It goes through `FeatureService` like every other
	 * write, so the Places inside the new boundary are assigned as they would be
	 * in the app (ADR-0008).
	 */
	protected function sector(string $name, array $extra = []): int {
		$id = (int)Server::get(FeatureService::class)->create(array_merge([
			'name' => $name,
			'subcategoryId' => $this->subcategoryIdBySlug('sector'),
			'colour' => '#16a34a',
			'geometry' => self::BOUNDARY,
		], $extra))->getId();
		$this->created[] = $id;

		return $id;
	}
}
