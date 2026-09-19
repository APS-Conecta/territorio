<?php

declare(strict_types=1);

namespace OCA\Territorio\Exception;

/** Thrown when a value offered as a geometry is not one this app will store. */
class InvalidGeometryException extends RefusedException {
}
