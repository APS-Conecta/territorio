<?php

declare(strict_types=1);

/**
 * Boots a real Nextcloud so the mappers can be exercised against a real
 * database.
 *
 * The unit suite deliberately mocks the mappers — it is about judgement, and it
 * runs in a bare PHP container in milliseconds. That leaves exactly one thing
 * unproven: whether an Entity's camelCase properties actually land in the
 * snake_case columns the migration created, with the right types. Nothing but a
 * real insert answers that, and a wrong answer is invisible until data is lost.
 *
 * Run inside the dev instance: `make test-integration`.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once '/var/www/html/lib/base.php';

\OC_App::loadApp('territorio');

if (!\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForAnyone('territorio')) {
	throw new RuntimeException('territorio must be enabled on this instance to run integration tests');
}
