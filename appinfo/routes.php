<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Every user who can open the app may edit the Registry (ADR-0005).
		['name' => 'feature#create', 'url' => '/features', 'verb' => 'POST'],
		['name' => 'feature#update', 'url' => '/features/{id}', 'verb' => 'PUT'],

		// Admin-only by default: it is #[NoAdminRequired] that opens a route up,
		// and this one deliberately carries no such attribute.
		['name' => 'adminSettings#save', 'url' => '/settings/basemap', 'verb' => 'PUT'],
	],
];
