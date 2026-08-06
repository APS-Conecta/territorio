<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * Where a Feature came from, when it came from an import.
 *
 * A separate argument rather than more keys in the submitted input, and
 * deliberately so: `datasetId` and `externalId` decide which Dataset a record
 * belongs to and what a future re-import will match it against. If they were
 * read from the request body, anyone could post a record claiming to be part of
 * the national cadastre.
 */
final class Provenance {
	/** @param array<string, mixed> $official what the authority published */
	public function __construct(
		public readonly int $datasetId,
		public readonly string $externalId,
		public readonly array $official,
	) {
	}

	public function officialAsJson(): string {
		return json_encode($this->official, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}
}
