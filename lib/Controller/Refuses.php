<?php

declare(strict_types=1);

namespace OCA\Territorio\Controller;

use OCA\Territorio\Exception\RefusedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The app's refusal posture, in one place (L1-01): a submission understood and
 * refused is a 422 whose message names what to fix, and a record nobody has is
 * a 404 — never a silent 200 and never a 500 a person reading the screen cannot
 * see.
 *
 * Adopters, named because "every controller answers through this trait" was
 * never quite true (L1-01): Feature, Boundary, Duplicate, Category, Import,
 * Export, AdminSettings — and Changes, for its `since` guard alone. Page is
 * the one outside, because a page render has nothing to refuse. Per-method
 * catches stay where a controller deliberately narrows: ExportController keeps
 * its InvalidFeatureException for the response type and catches
 * ServerFaultException for the 500 split (L1-08).
 */
trait Refuses {
	/**
	 * One place to turn the service's refusals into status codes, so neither
	 * action repeats the mapping and neither can quietly forget a case.
	 *
	 * @param callable(): array<string, mixed> $action
	 */
	private function attempt(callable $action, string $missing = 'no existe'): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (RefusedException $e) {
			return $this->refuse($e->getMessage());
		} catch (DoesNotExistException) {
			return $this->missing($missing);
		}
	}

	/** A refusal sentence as the body of a 422 — what the form shows. */
	private function refuse(string $message): JSONResponse {
		return new JSONResponse(['message' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	/** The not-found answer every missing record shares, guards included. */
	private function missing(string $message = 'no existe'): JSONResponse {
		return new JSONResponse(['message' => $message], Http::STATUS_NOT_FOUND);
	}
}
