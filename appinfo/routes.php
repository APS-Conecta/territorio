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

		// The Sector editor asks for the Boundary features on screen, and only
		// while it is open (ADR-0007). Everything else in the app is careful to
		// leave them out.
		['name' => 'boundary#inView', 'url' => '/boundaries', 'verb' => 'GET'],

		// The review queue (issue #16). Import never merges on similarity, so
		// deciding is a request of its own — and both decisions it offers are
		// undoable by restoring the record (ADR-0005).
		['name' => 'duplicate#index', 'url' => '/duplicates', 'verb' => 'GET'],
		['name' => 'duplicate#merge', 'url' => '/duplicates/{id}/merge', 'verb' => 'POST'],
		['name' => 'duplicate#dismiss', 'url' => '/duplicates/{id}/dismiss', 'verb' => 'POST'],

		// Exports (ADR-0006). Two routes for one operation because
		// #[PasswordConfirmationRequired] is an attribute on a method: the
		// confirmed one is the only one that can carry contact details, and the
		// plain one structurally cannot. The report is admin-only — it is the
		// list of who took personal data out of the building.
		['name' => 'export#plain', 'url' => '/export', 'verb' => 'POST'],
		['name' => 'export#withContacts', 'url' => '/export/contacts', 'verb' => 'POST'],
		['name' => 'export#history', 'url' => '/export/history', 'verb' => 'GET'],

		// Polled by every open map every few seconds (ADR-0002) — keep it cheap.
		['name' => 'changes#since', 'url' => '/changes', 'verb' => 'GET'],

		// The clinic owns its vocabulary (#36): a Category and a Subcategory are
		// editable data, renamed from the Capas panel. Addressed by slug, not by
		// id — the slug is what a rename leaves alone, and ids differ per
		// install. A Subcategory's slug is unique only within its Category
		// ("otro" is in nearly all of them), so its URL carries both.
		// Adding one (#45) posts to the collection itself, for the reason the
		// Subcategory add below gives: the slug is derived from the label, so
		// there is nothing to address the new row by until it exists.
		['name' => 'category#create', 'url' => '/categories', 'verb' => 'POST'],
		// One PUT edits a Category: its name (#41) or its pictogram (#46). The
		// body says which — the two are never typed and clicked at the same
		// moment, and a second route would be a second way to say "this Category".
		['name' => 'category#update', 'url' => '/categories/{slug}', 'verb' => 'PUT'],
		// Adding one (#44) posts to the collection under its parent: the slug is
		// derived from the label the clinic types, so there is nothing to put it
		// at a URL of its own.
		[
			'name' => 'category#addSubcategory',
			'url' => '/categories/{slug}/subcategories',
			'verb' => 'POST',
		],
		[
			'name' => 'category#renameSubcategory',
			'url' => '/categories/{slug}/subcategories/{subSlug}',
			'verb' => 'PUT',
		],
		// Removing one (#47). DELETE on the row itself, at the same URL its
		// rename addresses — there is nothing here like `feature#destroy`, which
		// is a DELETE that retires (ADR-0005). A Category is not a record: it
		// holds no history, nothing points back at it once nothing is filed under
		// it, and ADR-0016 lets it go only while that is true. The refusal when
		// it is not is a 422 with a sentence, like every other refusal in this
		// app — never a `?force` that would undo the ADR's own decision.
		['name' => 'category#destroy', 'url' => '/categories/{slug}', 'verb' => 'DELETE'],
		[
			'name' => 'category#destroySubcategory',
			'url' => '/categories/{slug}/subcategories/{subSlug}',
			'verb' => 'DELETE',
		],

		// Admin-only by default: it is #[NoAdminRequired] that opens a route up,
		// and this one deliberately carries no such attribute.
		['name' => 'adminSettings#save', 'url' => '/settings/basemap', 'verb' => 'PUT'],
		['name' => 'adminSettings#saveComuna', 'url' => '/settings/comuna', 'verb' => 'PUT'],
	],
];
