<?php

declare(strict_types=1);

namespace OCA\Territorio\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

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
		// The Live Layers are fetched live from public geodata services by the
		// user's browser (ADR-0025): the page itself must be allowed to connect
		// to them, which Nextcloud's default policy (`connect-src 'self'`)
		// refuses. CORS was verified open on both families during design; this
		// is the browser's second, page-side gate. The hosts are the two
		// adapters' roots — keep in step with the registry in src/layers.js,
		// whose entries are the only other place a service host is named.
		$context->getServerContainer()->get(IEventDispatcher::class)->addListener(
			AddContentSecurityPolicyEvent::class,
			static function (AddContentSecurityPolicyEvent $event): void {
				$csp = new EmptyContentSecurityPolicy();
				$csp->addAllowedConnectDomain('https://geoportal.cl');
				$csp->addAllowedConnectDomain('https://services3.arcgis.com');
				$event->addPolicy($csp);
			},
		);
	}
}
