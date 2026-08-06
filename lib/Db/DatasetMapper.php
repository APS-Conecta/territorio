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

	/** @return Dataset[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('label', 'ASC');

		return $this->findEntities($qb);
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
	 * The Dataset with this slug, created if it is new.
	 *
	 * Importing into a name that does not exist yet creates it rather than
	 * failing: the alternative is asking someone to declare a Dataset before
	 * they are allowed to put anything in it, which is a step that exists only
	 * to be forgotten.
	 */
	public function findOrCreate(string $slug, string $label, Origin $origin): Dataset {
		try {
			return $this->findBySlug($slug);
		} catch (DoesNotExistException) {
			$dataset = new Dataset();
			$dataset->setSlug($slug);
			$dataset->setLabel($label);
			$dataset->setOrigin($origin->value);
			$dataset->setCreatedAt(new DateTime());

			return $this->insert($dataset);
		}
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Dataset {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}
}
