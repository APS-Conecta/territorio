<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Everything one run of an import created or changed — see `CONTEXT.md`.
 *
 * The Revision range is the point. ADR-0009 says there is no backup feature and
 * that reverting an import is the answer instead, and a batch can only be
 * reverted if it recorded, at the time, exactly which Revisions were its own.
 * Nothing can work that out afterwards.
 *
 * @method int getDatasetId()
 * @method void setDatasetId(int $datasetId)
 * @method ?string getActor()
 * @method void setActor(?string $actor)
 * @method \DateTime getStartedAt()
 * @method void setStartedAt(\DateTime $startedAt)
 * @method int getRevisionFrom()
 * @method void setRevisionFrom(int $revisionFrom)
 * @method int getRevisionTo()
 * @method void setRevisionTo(int $revisionTo)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUpdated()
 * @method void setUpdated(int $updated)
 * @method int getSkipped()
 * @method void setSkipped(int $skipped)
 * @method int getDuplicates()
 * @method void setDuplicates(int $duplicates)
 * @method int getUnsupported()
 * @method void setUnsupported(int $unsupported)
 * @method int getUnchanged()
 * @method void setUnchanged(int $unchanged)
 * @method ?int getRevertsId()
 * @method void setRevertsId(?int $revertsId)
 */
class ImportBatch extends Entity {
	protected $datasetId = 0;
	protected $actor = null;
	protected $startedAt = null;
	// Not 0: on a fresh instance the cursor IS 0, and Entity::setter returns early
	// when the new value === the current one. See tests/unit/Db/ImportBatchTest.php.
	protected $revisionFrom = null;
	protected $revisionTo = null;
	protected $created = 0;
	protected $updated = 0;
	protected $skipped = 0;
	protected $duplicates = 0;
	protected $unsupported = 0;
	protected $unchanged = 0;
	protected $revertsId = null;

	public function __construct() {
		$this->addType('datasetId', 'integer');
		$this->addType('startedAt', 'datetime');
		$this->addType('revisionFrom', 'integer');
		$this->addType('revisionTo', 'integer');
		$this->addType('created', 'integer');
		$this->addType('updated', 'integer');
		$this->addType('skipped', 'integer');
		$this->addType('duplicates', 'integer');
		$this->addType('unsupported', 'integer');
		$this->addType('unchanged', 'integer');
		$this->addType('revertsId', 'integer');
	}

	/** @return array<string, mixed> */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'datasetId' => $this->getDatasetId(),
			'actor' => $this->getActor(),
			'startedAt' => $this->getStartedAt()?->format('Y-m-d H:i:s'),
			'revisionFrom' => $this->getRevisionFrom(),
			'revisionTo' => $this->getRevisionTo(),
			'created' => $this->getCreated(),
			'updated' => $this->getUpdated(),
			'skipped' => $this->getSkipped(),
			'duplicates' => $this->getDuplicates(),
			'unsupported' => $this->getUnsupported(),
			'unchanged' => $this->getUnchanged(),
			'revertsId' => $this->getRevertsId(),
			'isRevert' => $this->getRevertsId() !== null,
		];
	}
}
