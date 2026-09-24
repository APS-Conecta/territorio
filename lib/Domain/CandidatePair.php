<?php

declare(strict_types=1);

namespace OCA\Territorio\Domain;

/**
 * Two records that might be one place, and why they were put together.
 *
 * The reason travels with the pair because it is what the person reviewing it
 * needs: "coordenada" and "nombre" are different questions. The first asks
 * whether one building was catalogued twice; the second asks whether two sources
 * spelled one institution differently. Answering the second wrongly is how a
 * clinic loses a record of a real, separate place.
 */
final class CandidatePair {
	public function __construct(
		public readonly int $firstId,
		public readonly int $secondId,
		public readonly string $reason,
	) {
	}
}
