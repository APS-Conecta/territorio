<?php

declare(strict_types=1);

namespace OCA\Territorio\Domain;

/**
 * The shape a Feature takes on the map — see `CONTEXT.md`.
 *
 * It follows from the geometry and nothing else: a Feature is a Place because
 * it is a single location, not because of what it is about. A colegio and a
 * plaza can be the same Kind.
 */
enum Kind: string {
	case Place = 'place';
	case Zone = 'zone';
	case Route = 'route';
}
