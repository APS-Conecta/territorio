<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * The one server class the OCP stub package does not ship.
 *
 * `OCP\Files\IRootFolder` extends it, so `createMock(IRootFolder::class)` dies
 * in the bare container with "Interface OC\Hooks\Emitter not found". Empty on
 * purpose, and it cannot drift: an app may only call OCP, so nothing here ever
 * calls a method of it.
 *
 * Canonical home: the aps-common repository (this file). Each app's
 * tests/stubs/OC/Hooks/Emitter.php copy is produced by `make aps-sync` and
 * checked by `make aps-drift` — do not edit the copy by hand. It is loaded by
 * require_once in the unit bootstrap ONLY: the integration suite boots a real
 * Nextcloud, where OC\Hooks\Emitter is the server's own and an app that
 * shadowed it would be replacing a server class from a test folder.
 */
interface Emitter {
}
