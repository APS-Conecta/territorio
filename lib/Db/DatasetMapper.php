<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Dataset> */
class DatasetMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'territorio_dataset', Dataset::class);
	}

	/** @return array<int, Dataset> keyed by id */
	public function allById(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		$byId = [];
		foreach ($this->findEntities($qb) as $dataset) {
			$byId[(int)$dataset->getId()] = $dataset;
		}

		return $byId;
	}

	/** @throws DoesNotExistException */
	public function findBySlug(string $slug): Dataset {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		return $this->findEntity($qb);
	}

	/**
	 * The ids of every Dataset holding Boundary features.
	 *
	 * Read as a list rather than joined into each read: an install has a handful
	 * of Datasets and at most one or two of these, so this is a tiny indexed
	 * query, and a correlated subquery would have to name the table without the
	 * instance's configured prefix.
	 *
	 * @return int[]
	 */
	public function boundaryIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('boundary', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

		return array_map(
			static fn (Dataset $dataset): int => (int)$dataset->getId(),
			$this->findEntities($qb),
		);
	}

	/**
	 * The Dataset with this slug, created if it is new.
	 *
	 * Importing into a name that does not exist yet creates it rather than
	 * failing: the alternative is asking someone to declare a Dataset before
	 * they are allowed to put anything in it, which is a step that exists only
	 * to be forgotten.
	 *
	 * An import that declares a different `boundary` from the one on record wins,
	 * and deliberately: someone who imported the streets without saying so is
	 * looking at eleven thousand lines on the map, and re-running the import with
	 * the flag has to be the way out. It is the same authority re-declaring what
	 * its own collection is, and it moves no record.
	 */
	public function findOrCreate(string $slug, string $label, Origin $origin, bool $boundary = false): Dataset {
		try {
			$dataset = $this->findBySlug($slug);
			if ($dataset->getBoundary() !== $boundary) {
				$dataset->setBoundary($boundary);
				$dataset = $this->update($dataset);
			}

			return $dataset;
		} catch (DoesNotExistException) {
			$dataset = new Dataset();
			$dataset->setSlug($slug);
			$dataset->setLabel($label);
			$dataset->setOrigin($origin->value);
			$dataset->setBoundary($boundary);
			$dataset->setCreatedAt(new DateTime());

			return $this->insert($dataset);
		}
	}
}
