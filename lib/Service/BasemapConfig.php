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
		['url' => $url, 'attribution' => $attribution] = $this->stored();

		return Basemap::resolve($url, $attribution);
	}

	/**
	 * What is actually in the database, before any judgement is applied.
	 *
	 * The settings form fills its boxes from this, not from `get()`. Otherwise a
	 * rejected URL resolves to the default, the form renders empty, and the
	 * administrator's typo disappears with no explanation — which is exactly what
	 * storing it verbatim was supposed to prevent.
	 *
	 * @return array{url: string, attribution: string}
	 */
	public function stored(): array {
		return [
			'url' => $this->appConfig->getValueString(Application::APP_ID, self::KEY_URL),
			'attribution' => $this->appConfig->getValueString(Application::APP_ID, self::KEY_ATTRIBUTION),
		];
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
