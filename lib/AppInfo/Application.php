<?php

declare(strict_types=1);

namespace OCA\Territorio\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'territorio';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Everything in this app is autowired, plus one autoload rule: aps-common
		// ships as the committed vendor/aps/common/src subtree (D1 — release
		// tarballs are `git archive` of the tracked tree, and vendor/autoload.php
		// is not committed), so its namespace is mapped here until the day a real
		// composer runtime exists. In dev and tests the composer autoloader is
		// registered earlier and wins; this is the production path.
		$apsSrc = __DIR__ . '/../../vendor/aps/common/src';
		if (is_dir($apsSrc)) {
			spl_autoload_register(static function (string $class) use ($apsSrc): void {
				if (str_starts_with($class, 'APS\\Common\\')) {
					$file = $apsSrc . '/' . str_replace('\\', '/', substr($class, strlen('APS\\Common\\'))) . '.php';
					if (is_file($file)) {
						require_once $file;
					}
				}
			});
		}
	}

	public function boot(IBootContext $context): void {
	}
}
