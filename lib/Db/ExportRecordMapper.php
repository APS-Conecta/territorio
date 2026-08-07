<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ExportRecord> */
class ExportRecordMapper extends QBMapper {
	/** How many entries the report shows. It is a report, not an archive read. */
	private const RECENT = 200;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'territorio_export', ExportRecord::class);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): ExportRecord {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * The log, newest first.
	 *
	 * @return list<ExportRecord>
	 */
	public function findRecent(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('exported_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(self::RECENT);

		return $this->findEntities($qb);
	}
}
