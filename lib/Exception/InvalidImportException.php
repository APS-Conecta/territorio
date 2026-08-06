<?php

declare(strict_types=1);

namespace OCA\Territorio\Exception;

use RuntimeException;

/** Thrown when a file offered for import is not one this app can read. */
class InvalidImportException extends RuntimeException {
}
