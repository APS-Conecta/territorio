<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads the basemap setting. All the judgement lives in {@see Basemap}; this is
 * only the part that needs Nextcloud, and there is deliberately nothing here
 * worth a unit test.
 */
class BasemapConfig {
	public const KEY_URL = 'tile_url';
	public const KEY_ATTRIBUTION = 'tile_attribution';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function get(): Basemap {
		return Basemap::resolve(
			$this->appConfig->getValueString(Application::APP_ID, self::KEY_URL),
			$this->appConfig->getValueString(Application::APP_ID, self::KEY_ATTRIBUTION),
		);
	}

	/**
	 * Stores what the administrator typed, verbatim.
	 *
	 * Rejection is not this method's job: storing the raw string means a typo can
	 * be seen and corrected in the field that produced it, while `get()` keeps
	 * serving OpenStreetMap in the meantime. Silently rewriting the value to the
	 * default would leave an administrator staring at a box that does not say
	 * what they entered.
	 */
	public function set(string $url, string $attribution): Basemap {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_URL, trim($url));
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_ATTRIBUTION, trim($attribution));

		return $this->get();
	}
}
