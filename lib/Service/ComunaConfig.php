<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use OCA\Territorio\AppInfo\Application;
use OCA\Territorio\Domain\Comuna;
use OCA\Territorio\Exception\InvalidComunaException;
use OCP\IAppConfig;

/**
 * Reads and writes which Comuna this install serves. All the judgement lives in
 * {@see Comuna}; this is only the part that needs Nextcloud.
 */
class ComunaConfig {
	public const KEY_CUT = 'comuna_cut';
	public const KEY_NAME = 'comuna_name';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function get(): Comuna {
		$cut = $this->appConfig->getValueString(Application::APP_ID, self::KEY_CUT);
		if ($cut === '') {
			return Comuna::none();
		}

		try {
			return Comuna::of($cut, $this->appConfig->getValueString(Application::APP_ID, self::KEY_NAME));
		} catch (InvalidComunaException) {
			// Something unusable was stored, before this validated or by hand. Say
			// "not chosen" rather than throwing on every page load — setup is
			// reachable from there and a broken value is fixed by choosing again.
			return Comuna::none();
		}
	}

	public function set(Comuna $comuna): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CUT, $comuna->cut);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_NAME, $comuna->name);
	}
}
