<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * How well a Feature's coordinate is known — see `CONTEXT.md`.
 *
 * `Absent` is derived, never chosen: a record with no geometry has no location
 * to describe. The other two are a judgement only the person entering the
 * record can make, and the catastro this app inherits flagged 121 records as
 * approximate, so it is not a rare case.
 */
enum LocationQuality: string {
	case Exact = 'exacta';
	case Approximate = 'aproximada';
	case Absent = 'sin_coordenada';
}
