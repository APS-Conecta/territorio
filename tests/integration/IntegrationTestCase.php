<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
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

	/** @var string[] Dataset slugs this test made, to be forgotten afterwards */
	protected array $datasets = [];

	protected function tearDown(): void {
		// Datasets first, and before the guard: imported rows are not tracked by
		// `$created`, so the sweep below must not be skipped for a test that
		// imported a Dataset without creating a single Feature of its own (#122).
		foreach ($this->datasets as $slug) {
			$this->forgetDataset($slug);
		}
		$this->datasets = [];

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

	/**
	 * Forget one imported Dataset and everything it brought in (#122).
	 *
	 * Five tests carried a private copy of this body, differing only in how
	 * each resolved its mappers; the statements were the same five every time.
	 * Imported rows are not tracked by `$created`, so a Dataset is cleaned by
	 * slug: the Revisions of its Features first — they reference the Feature —
	 * then the Features, the Import batches and the Dataset itself by id. Raw
	 * SQL for the reason the `$created` sweep gives: tidying up after a test
	 * run is not the app.
	 *
	 * Idempotent, and quiet about a slug that never landed: forgetting a
	 * Dataset that does not exist — a test that imported nothing, or one that
	 * already cleaned up after itself — is the usual case, not an error.
	 */
	protected function forgetDataset(string $slug): void {
		$db = Server::get(IDBConnection::class);

		try {
			$id = (int)Server::get(DatasetMapper::class)->findBySlug($slug)->getId();
		} catch (\Throwable) {
			return;
		}

		foreach (Server::get(FeatureMapper::class)->findByDataset($id) as $feature) {
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_revision')
				->where($qb->expr()->eq('feature_id', $qb->createNamedParameter(
					(int)$feature->getId(), IQueryBuilder::PARAM_INT,
				)))->executeStatement();
		}
		foreach (['territorio_feature' => 'dataset_id', 'territorio_import' => 'dataset_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('territorio_dataset')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
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
