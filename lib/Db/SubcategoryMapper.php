<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Subcategory> */
class SubcategoryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'territorio_subcategory', Subcategory::class);
	}

	/** @return Subcategory[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('label', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * By slug within its Category — never by slug alone.
	 *
	 * "otro" is in nearly every Category and means something different in each,
	 * which is why the unique index is on `(category_id, slug)` and why a lookup
	 * that forgot the Category would answer with whichever row came first.
	 *
	 * @throws DoesNotExistException
	 */
	public function findBySlug(int $categoryId, string $slug): Subcategory {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
			->andWhere($qb->expr()->eq(
				'category_id',
				$qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
			));

		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Subcategory {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity($qb);
	}
}
