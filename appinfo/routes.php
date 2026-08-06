<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Every user who can open the app may edit the Registry (ADR-0005).
		['name' => 'feature#create', 'url' => '/features', 'verb' => 'POST'],
		['name' => 'feature#update', 'url' => '/features/{id}', 'verb' => 'PUT'],
		// Retires rather than destroys (ADR-0005). Declared before the {id}
		// routes so /features/retired is not read as a Feature called "retired".
		['name' => 'feature#retired', 'url' => '/features/retired', 'verb' => 'GET'],
		['name' => 'feature#destroy', 'url' => '/features/{id}', 'verb' => 'DELETE'],
		['name' => 'feature#restore', 'url' => '/features/{id}/restore', 'verb' => 'POST'],
		['name' => 'feature#history', 'url' => '/features/{id}/history', 'verb' => 'GET'],

		// Polled by every open map every few seconds (ADR-0002) — keep it cheap.
		['name' => 'changes#since', 'url' => '/changes', 'verb' => 'GET'],

		// Admin-only by default: it is #[NoAdminRequired] that opens a route up,
		// and this one deliberately carries no such attribute.
		['name' => 'adminSettings#save', 'url' => '/settings/basemap', 'verb' => 'PUT'],
	],
];
