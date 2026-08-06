<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * What a Revision records having happened to a Feature.
 *
 * `Retired` and `Restored` are separate from `Updated` even though both are
 * ordinary column writes underneath: "quién lo retiró" is the question history
 * exists to answer, and burying it inside a diff of a boolean would make it
 * findable only by reading every entry.
 */
enum Action: string {
	case Created = 'created';
	case Updated = 'updated';
	case Retired = 'retired';
	case Restored = 'restored';
}
