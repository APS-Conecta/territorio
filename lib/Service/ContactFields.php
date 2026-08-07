<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

/**
 * Which fields describe a person rather than a place (ADR-0006).
 *
 * One list, because the promise made by the unconfirmed export — that it carries
 * nothing about anybody — is only as good as the agreement between everything
 * that writes a file. The list lived in two writers and a paragraph of Spanish
 * prose, so adding a field meant remembering three places and the guarantee
 * broke if you remembered two.
 *
 * `address` is deliberately not here. Where a junta meets is a fact about the
 * territory and it is on the sign outside; a name, a telephone number and an
 * email address are the presidenta's.
 */
final class ContactFields {
	/** @var list<string> the row keys, as the readers and writers name them */
	public const KEYS = ['phone', 'email', 'contactPerson', 'contactRole'];

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
