<?php

declare(strict_types=1);

namespace OCA\Territorio\Exception;

use RuntimeException;

/** Thrown when an import is refused with a sentence a person reads, rather than a stack trace. */
class InvalidImportException extends RuntimeException {
}
