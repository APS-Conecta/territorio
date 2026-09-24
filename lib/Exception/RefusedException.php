<?php

declare(strict_types=1);

namespace OCA\Territorio\Exception;

use APS\Common\Refusal;
use RuntimeException;

/**
 * The marker for the app's refusal posture: understood, and not stored.
 *
 * Every Invalid*Exception extends this so the one mapping in
 * {@see \APS\Common\Refuses} can catch them all: a refusal is a
 * 422 whose message names what to fix, never a silent 200 and never a 500 a
 * person reading the screen cannot see (ADR-0016).
 *
 * Deliberately abstract: a refusal always says WHICH rule refused, through its
 * concrete type; there is no refusal that is only a refusal.
 */
abstract class RefusedException extends RuntimeException implements Refusal {
}
