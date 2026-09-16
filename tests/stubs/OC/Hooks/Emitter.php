<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * The one server class the OCP stub package does not ship.
 *
 * `OCP\Files\IRootFolder` extends it, so `createMock(IRootFolder::class)` dies in
 * the bare container with "Interface OC\Hooks\Emitter not found" — which is what
 * stopped #104's refusal paths from being unit-testable at all. Empty on
 * purpose, and it cannot drift: an app may only call OCP, so nothing here ever
 * calls a method of it.
 */
interface Emitter {
}
