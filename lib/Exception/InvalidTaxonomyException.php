<?php

declare(strict_types=1);

namespace OCA\Territorio\Exception;

use RuntimeException;

/** Thrown when a change to the Category tree is one this app will not store. */
class InvalidTaxonomyException extends RuntimeException {
}
