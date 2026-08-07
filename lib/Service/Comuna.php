<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use InvalidArgumentException;

/**
 * The Chilean municipality this install is scoped to — see `CONTEXT.md`.
 *
 * Exactly one per install, chosen at setup by its CUT code, and every record in
 * the install belongs to it. A Territorio is always a portion of one Comuna and
 * never reaches into a second, which is why "which comuna" is a setting rather
 * than a field on anything.
 *
 * Depends on no OCP class, so it tests in bare PHP. {@see ComunaConfig} is the
 * part that needs Nextcloud.
 */
final class Comuna {
	private function __construct(
		public readonly string $cut,
		public readonly string $name,
	) {
	}

	/** No comuna chosen yet — a fresh install, before setup. */
	public static function none(): self {
		return new self('', '');
	}

	/**
	 * A CUT code is five digits: 13110 is La Florida. Anything else is a typo,
	 * and storing it would mean every UV file is later refused for a reason
	 * nobody can see.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function of(string $cut, string $name = ''): self {
		$cut = trim($cut);
		if (preg_match('/^\d{5}$/', $cut) !== 1) {
			throw new InvalidArgumentException(sprintf(
				'"%s" no es un código CUT: se esperan cinco dígitos, como 13110.',
				$cut,
			));
		}

		return new self($cut, trim($name));
	}

	public function isChosen(): bool {
		return $this->cut !== '';
	}

	/** @return array<string, string|bool> */
	public function jsonSerialize(): array {
		return ['cut' => $this->cut, 'name' => $this->name, 'chosen' => $this->isChosen()];
	}
}
