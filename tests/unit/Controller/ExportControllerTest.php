<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\ExportController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Who may do what, asserted rather than assumed (ADR-0006).
 *
 * Both rules this controller carries are ATTRIBUTES, which means both are one
 * line away from being deleted by somebody tidying up, and neither leaves a
 * trace in any other test when it is: the app would go on working, more
 * permissively. `RoutesTest` only knows the route names exist.
 *
 * Reflection, not a request. Whether Nextcloud enforces these is Nextcloud's
 * test to write and `tools/browser-check.py` checks it end to end; what belongs
 * here is that this app still asks.
 */
final class ExportControllerTest extends TestCase {
	private function attributes(string $method, string $attribute): array {
		return (new ReflectionMethod(ExportController::class, $method))
			->getAttributes($attribute);
	}

	/**
	 * The export log says who took personal data out of the building, so it is
	 * the one route in this app that is not open to everybody.
	 *
	 * In Nextcloud it is #[NoAdminRequired] that OPENS a route up, so this asserts
	 * an absence — which is exactly the kind of rule that is deleted by accident,
	 * because adding the attribute makes nothing fail.
	 */
	public function testTheExportLogIsAdminOnly(): void {
		self::assertSame(
			[],
			$this->attributes('history', NoAdminRequired::class),
			'#[NoAdminRequired] on history() would show every user who exported what',
		);
	}

	/** ADR-0006: an export carrying contact details asks for the password. */
	public function testTheContactBearingExportRequiresAConfirmedPassword(): void {
		$attributes = $this->attributes('withContacts', PasswordConfirmationRequired::class);
		self::assertCount(1, $attributes);

		// strict, because ADR-0006 asks the person to confirm THIS export rather
		// than to have confirmed something once this session.
		self::assertSame(['strict' => true], $attributes[0]->getArguments());
	}

	/**
	 * And the route that cannot carry them does not ask.
	 *
	 * "Exports without contact fields need no confirmation; the prompt appears
	 * only when it is earned" — ADR-0006. A prompt on every export teaches people
	 * to type their password without reading why, which is the habit the rule
	 * exists to avoid.
	 */
	public function testThePlainExportAsksForNothing(): void {
		self::assertSame([], $this->attributes('plain', PasswordConfirmationRequired::class));
		self::assertCount(1, $this->attributes('plain', NoAdminRequired::class));
	}
}
