<?php

declare(strict_types=1);

/**
 * The unit suite's bootstrap: composer's autoloader, and one interface the OCP
 * stub package does not ship.
 *
 * Required here rather than autoloaded, and only by THIS suite: the integration
 * suite boots a real Nextcloud, where `OC\Hooks\Emitter` is the server's own and
 * an app that shadowed it would be replacing a server class from a test folder.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../stubs/OC/Hooks/Emitter.php';
