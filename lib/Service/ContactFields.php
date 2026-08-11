<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * Which fields describe a person rather than a place (ADR-0006).
 *
 * There is no list here. Each writer holds its own, because they spell the same
 * field differently and must — GeoJSON says `contact_person`, a CSV column says
 * `Persona de contacto` — and one list of row keys cannot carry both. A canonical
 * third copy was tried and read by nothing, which is worse than two: it looked
 * like the authority while the writers were the authority.
 *
 * What has to hold is that the two agree, and `ContactFieldsTest` is what makes
 * that true rather than claimed. The promise an unconfirmed export makes — that
 * it carries nothing about anybody (ADR-0006) — is only as good as that
 * agreement.
 */
final class ContactFields {
	/**
	 * Marks a file that was written WITHOUT them.
	 *
	 * A GeoJSON export is the re-importable one, and one written without contact
	 * fields does not say "these records have no telephone" — it says "this file
	 * is not about telephones". Nothing in GeoJSON can express that difference,
	 * so it is said here and the reader refuses such a file rather than letting
	 * an import read absence as deletion.
	 */
	public const PARTIAL = 'territorio_sin_contactos';
}
