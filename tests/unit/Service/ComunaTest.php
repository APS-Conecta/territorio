<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Exception\InvalidComunaException;
use OCA\Territorio\Domain\Comuna;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary rule this install is scoped by: a CUT code is five digits.
 * Bare PHP — {@see Comuna} depends on no OCP class, so its tests do too.
 */
final class ComunaTest extends TestCase {
	public function testAComunaIsChosenByItsCutCode(): void {
		$comuna = Comuna::of('13110', 'La Florida');

		self::assertSame('13110', $comuna->cut);
		self::assertSame('La Florida', $comuna->name);
		self::assertTrue($comuna->isChosen());
	}

	public function testAnUnchosenComunaIsTheOrdinaryFreshInstallCase(): void {
		$comuna = Comuna::none();

		self::assertSame('', $comuna->cut);
		self::assertFalse($comuna->isChosen());
	}

	/**
	 * A bad code is refused rather than stored: stored, it would make every
	 * Unidad Vecinal file fail later for a reason nobody could see from here.
	 * The refusal is the family's now (L7-01) — the same catch answers it as
	 * every other understood-and-refused submission.
	 */
	public function testABadCutCodeIsRefusedByTheFamily(): void {
		$this->expectException(InvalidComunaException::class);
		$this->expectExceptionMessage('no es un código CUT');

		Comuna::of('1311', 'La Florida');
	}

	/**
	 * JsonSerializable is the sibling convention (Basemap): whatever holds a
	 * Comuna can serialize it without knowing to call jsonSerialize() by hand.
	 */
	public function testAComunaSerializesItself(): void {
		self::assertSame(
			['cut' => '13110', 'name' => 'La Florida', 'chosen' => true],
			json_decode(json_encode(Comuna::of('13110', 'La Florida')), true),
		);
	}
}
