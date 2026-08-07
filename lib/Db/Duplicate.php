<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One pair of records that might be the same place, and what was decided about
 * it — see `CONTEXT.md`.
 *
 * The decision is stored, not recomputed. "These two are not duplicates" is a
 * judgement about two real places somebody had to look up, and a queue that
 * asked again after the next import would be closed and never reopened.
 *
 * @method int getFirstId()
 * @method void setFirstId(int $firstId)
 * @method int getSecondId()
 * @method void setSecondId(int $secondId)
 * @method string getReason()
 * @method void setReason(string $reason)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime getFoundAt()
 * @method void setFoundAt(\DateTime $foundAt)
 * @method ?string getResolvedBy()
 * @method void setResolvedBy(?string $resolvedBy)
 */
class Duplicate extends Entity {
	/** Waiting for somebody to look at it. */
	public const PENDING = 'pending';

	/** One was merged into the other. Reversible: restoring the loser reopens it. */
	public const MERGED = 'merged';

	/** Looked at, and they are two different places. Never offered again. */
	public const DISMISSED = 'dismissed';

	protected $firstId = 0;
	protected $secondId = 0;
	protected $reason = '';
	protected $status = self::PENDING;
	protected $foundAt = null;
	protected $resolvedBy = null;

	public function __construct() {
		$this->addType('firstId', 'integer');
		$this->addType('secondId', 'integer');
		$this->addType('foundAt', 'datetime');
	}

	/** @return array<string, mixed> */
	public function toClient(): array {
		return [
			'id' => (int)$this->getId(),
			'firstId' => $this->getFirstId(),
			'secondId' => $this->getSecondId(),
			'reason' => $this->getReason(),
			'status' => $this->getStatus(),
			'foundAt' => $this->getFoundAt()?->format(\DateTimeInterface::ATOM),
		];
	}
}
