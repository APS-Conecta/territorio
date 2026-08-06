<?php

declare(strict_types=1);

namespace OCA\Territorio\Db;

/**
 * Where a Dataset's records come from — see `CONTEXT.md`.
 *
 * The distinction is not decoration: it decides whether a record can be
 * overridden. A Curated Dataset is born inside the clinic and nothing outside
 * ever republishes it, so there is no incoming value for a local edit to outrank.
 */
enum Origin: string {
	case Cadastral = 'cadastral';
	case Curated = 'curated';
}
