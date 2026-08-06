<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Every user who can open the app may edit the Registry (ADR-0005).
		['name' => 'feature#create', 'url' => '/features', 'verb' => 'POST'],
		['name' => 'feature#update', 'url' => '/features/{id}', 'verb' => 'PUT'],
		// Retires rather than destroys (ADR-0005). /features/retired cannot be
		// confused with /features/{id} because no GET route takes an {id}.
		['name' => 'feature#retired', 'url' => '/features/retired', 'verb' => 'GET'],
		['name' => 'feature#destroy', 'url' => '/features/{id}', 'verb' => 'DELETE'],
		['name' => 'feature#restore', 'url' => '/features/{id}/restore', 'verb' => 'POST'],
		['name' => 'feature#history', 'url' => '/features/{id}/history', 'verb' => 'GET'],

		// Importing is open to every user, like every other change (ADR-0005):
		// the safety net is that a batch is recorded and revertible, not that
		// fewer people are allowed to make one.
		['name' => 'import#upload', 'url' => '/import', 'verb' => 'POST'],
		['name' => 'import#batches', 'url' => '/import/batches', 'verb' => 'GET'],
		// Undo one run (ADR-0009). There is no backup feature; this is it.
		['name' => 'import#revert', 'url' => '/import/{id}/revert', 'verb' => 'POST'],

		// Polled by every open map every few seconds (ADR-0002) — keep it cheap.
		['name' => 'changes#since', 'url' => '/changes', 'verb' => 'GET'],

		// Admin-only by default: it is #[NoAdminRequired] that opens a route up,
		// and this one deliberately carries no such attribute.
		['name' => 'adminSettings#save', 'url' => '/settings/basemap', 'verb' => 'PUT'],
	],
];
