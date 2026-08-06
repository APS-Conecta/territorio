<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		// Admin-only by default: it is #[NoAdminRequired] that opens a route up,
		// and this one deliberately carries no such attribute.
		['name' => 'adminSettings#save', 'url' => '/settings/basemap', 'verb' => 'PUT'],
	],
];
