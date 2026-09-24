<?php

declare(strict_types=1);

namespace OCA\Territorio\Exception;

use RuntimeException;

/**
 * The server's own failure, said in the person's language (L1-08).
 *
 * Deliberately NOT a RefusedException: a full disk or a temp directory that
 * cannot be written is not the caller's fault, and answering it 422 files it
 * under the caller in every 4xx monitor. The controller that catches it answers
 * 500 — still with the Spanish sentence, which is the half of the trade worth
 * keeping: a bare RuntimeException reaching the framework also answers 500, but
 * the person is shown the framework's English, not the app's sentence.
 */
class ServerFaultException extends RuntimeException {
}
