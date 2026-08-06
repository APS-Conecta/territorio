<?php

declare(strict_types=1);

namespace OCA\Territorio\Exception;

use RuntimeException;

/** Thrown when a value offered as a geometry is not one this app will store. */
class InvalidGeometryException extends RuntimeException {
}
