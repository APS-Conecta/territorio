<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Category> */
class CategoryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'territorio_category', Category::class);
	}

	/** @return Category[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('label', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * By slug, because the slug is what survives a rename.
	 *
	 * The label moves and the id differs per install, so a client that wants to
	 * address one Category has exactly one stable name for it — the same one
	 * every Feature, every glyph lookup and every colour lookup already keys on
	 * (CONTEXT.md).
	 *
	 * @throws DoesNotExistException
	 */
	public function findBySlug(string $slug): Category {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));

		return $this->findEntity($qb);
	}
}
