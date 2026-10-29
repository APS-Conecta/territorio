<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\ExportController;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\ExportService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
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
	private ExportService&MockObject $exports;
	private ExportController $controller;

	private function attributes(string $method, string $attribute): array {
		return (new ReflectionMethod(ExportController::class, $method))
			->getAttributes($attribute);
	}

	/**
	 * The controller over a body holding exactly these keys, with the service
	 * mocked. What these tests are about is what the controller reads out of
	 * the body before the service ever sees it (#241) — the `->with()` is the
	 * pin, and the service's own sentences are pinned in ExportServiceTest.
	 *
	 * @param array<string, mixed> $params
	 */
	private function body(array $params): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default,
		);

		$this->exports = $this->createMock(ExportService::class);
		$this->exports->method('export')->willReturn([
			'body' => '', 'filename' => 'territorio.csv', 'mime' => 'text/csv',
		]);
		$this->controller = new ExportController($request, $this->exports);
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

	/**
	 * #241: `format[]=x` was `(string)`'d to the text "Array", which the
	 * service's own unknown-format sentence then showed to a person. Not given
	 * is what the import's dataset and path already read (#207): the service
	 * hears '' and its existing refusal is the answer, as a 422.
	 */
	public function testAListForTheFormatIsReadAsNotGivenAndRefusedByTheService(): void {
		$this->body(['ids' => [1], 'format' => ['csv']]);
		$this->exports->expects(self::once())
			->method('export')
			->with([1], '', false, '')
			->willThrowException(new InvalidFeatureException('Formato desconocido: '));

		$response = $this->controller->plain();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('Formato desconocido: ', $response->getData()['message']);
	}

	/**
	 * #241: `scope` is written into the export log (ADR-0006), so `(string)` on
	 * a list put the text "Array" into the history of who took what out of the
	 * building. Not given reads as '', which is what the log already records
	 * for an export nobody narrowed. The refusal at the end is only to keep
	 * this in the bare suite — the success path builds a DataDownloadResponse,
	 * which needs the whole server; that half is `ExportTest`'s, in the
	 * integration suite where the server is real.
	 */
	public function testAListForTheScopeIsReadAsNotGiven(): void {
		$this->body(['ids' => [1], 'format' => 'csv', 'scope' => ['x']]);
		$this->exports->expects(self::once())
			->method('export')
			->with([1], 'csv', false, '')
			->willThrowException(new InvalidFeatureException('Formato desconocido: '));

		$response = $this->controller->plain();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}
}
