<?php

declare(strict_types=1);

namespace OCA\Territorio\Migration;

use OCA\Territorio\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Deletes the two app settings ADR-0021 retired, `tile_url` and
 * `tile_attribution`, after writing down what they held.
 *
 * Until 0.79.0 an administrator could point the basemap at a PMTiles archive or
 * a tile server of their own. The basemap is now OpenStreetMap's tile service as
 * one constant and nothing reads these keys, so a stored value would sit in the
 * database as a setting that looks live and does nothing.
 *
 * Destroying a value is irreversible, and Nextcloud cannot downgrade an app, so
 * each value goes to the log (one line) BEFORE its key is deleted. That line is
 * the only record of the address a clinic had configured.
 *
 * At WARNING, deliberately: a default Nextcloud runs at loglevel 2 (warning) and
 * drops everything below it, so an info line would be written nowhere on exactly
 * the installs that need it. The unit test accepts no other level.
 *
 * Idempotent: a key already gone is skipped without a word, so the second run,
 * and every upgrade after it, logs nothing. A key stored as the empty string is
 * still stored, so it is still removed.
 *
 * Registered for both `install` and `post-migration` in `appinfo/info.xml`,
 * beside EnsureSeedData, which explains why a repair step and not a migration.
 */
class RemoveRetiredTileSettings implements IRepairStep {
	/** The keys the retired `BasemapConfig` wrote. */
	private const RETIRED_KEYS = ['tile_url', 'tile_attribution'];

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'Territorio: remove the retired tile_url and tile_attribution settings';
	}

	public function run(IOutput $output): void {
		foreach (self::RETIRED_KEYS as $key) {
			if (!$this->appConfig->hasKey(Application::APP_ID, $key)) {
				continue;
			}

			$old = $this->appConfig->getValueString(Application::APP_ID, $key);

			// json_encode, because the value was typed by an administrator: a newline
			// in it must not end this entry and start one of its own.
			$this->logger->warning(
				sprintf(
					'%s: removing retired app setting %s, old value %s',
					Application::APP_ID,
					$key,
					json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				),
				['app' => Application::APP_ID],
			);
			$this->appConfig->deleteKey(Application::APP_ID, $key);
		}
	}
}
