<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Migration;

use OCA\Territorio\Migration\RemoveRetiredTileSettings;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The repair step that retires `tile_url` and `tile_attribution` (ADR-0021).
 *
 * It is data-destructive and Nextcloud cannot downgrade an app, so the log is
 * the only record of what an administrator had set. The cases pin the order
 * (log, THEN delete), that the log is at warning level (the lowest a default
 * install keeps), that a second run is silent, and that an empty value is
 * still a stored key and still goes.
 */
final class RemoveRetiredTileSettingsTest extends TestCase {
	/** @var array<string, string> the app config, by key (one app, so the app id is not a dimension) */
	private array $stored;

	/** @var list<string> what happened to the config and the log, in order */
	private array $events;

	/** @var list<string> */
	private array $logged;

	protected function setUp(): void {
		$this->stored = [];
		$this->events = [];
		$this->logged = [];
	}

	private function step(): RemoveRetiredTileSettings {
		$config = $this->createStub(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(
			function (string $app, string $key): bool {
				self::assertSame('territorio', $app);

				return array_key_exists($key, $this->stored);
			},
		);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				self::assertSame('territorio', $app);

				return $this->stored[$key] ?? $default;
			},
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				self::assertSame('territorio', $app);
				$this->events[] = 'delete ' . $key;
				unset($this->stored[$key]);
			},
		);

		// WARNING, and nothing else. A default Nextcloud logs at loglevel 2 (warning)
		// and drops everything below it, and these lines are the only record of a
		// value the step is about to destroy: a call at info level would pass a
		// stub that listens to info() and be written nowhere. So any other level
		// fails here.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method(self::logicalNot(self::equalTo('warning')));
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->events[] = 'log';
				$this->logged[] = (string)$message;
			},
		);

		return new RemoveRetiredTileSettings($config, $logger);
	}

	private function runStep(RemoveRetiredTileSettings $step): void {
		$step->run($this->createStub(IOutput::class));
	}

	public function testBothSettingsAreRemovedAndTheirOldValuesLogged(): void {
		$this->stored = [
			'tile_url' => 'https://tiles.cesfam.example/chile.pmtiles',
			'tile_attribution' => '© Servicio de Salud',
			'comuna_cut' => '13110',
		];

		$this->runStep($this->step());

		self::assertSame(['comuna_cut' => '13110'], $this->stored, 'only the two retired keys go');
		self::assertCount(2, $this->logged);
		self::assertStringContainsString('territorio', $this->logged[0]);
		self::assertStringContainsString('tile_url', $this->logged[0]);
		self::assertStringContainsString('https://tiles.cesfam.example/chile.pmtiles', $this->logged[0]);
		self::assertStringContainsString('tile_attribution', $this->logged[1]);
		self::assertStringContainsString('© Servicio de Salud', $this->logged[1]);
	}

	/** The value is recorded before it is destroyed: a log written after the delete could be lost with it. */
	public function testEachValueIsLoggedBeforeItsKeyIsDeleted(): void {
		$this->stored = ['tile_url' => 'https://a.example/{z}/{x}/{y}.png', 'tile_attribution' => 'x'];

		$this->runStep($this->step());

		self::assertSame(
			['log', 'delete tile_url', 'log', 'delete tile_attribution'],
			$this->events,
		);
	}

	public function testASecondRunDoesNothingAndSaysNothing(): void {
		$this->stored = ['tile_url' => 'https://a.example/chile.pmtiles', 'tile_attribution' => 'x'];
		$step = $this->step();

		$this->runStep($step);
		$afterFirst = $this->events;
		$this->runStep($step);

		self::assertSame($afterFirst, $this->events, 'the second run touched the config or the log');
		self::assertCount(2, $this->logged);
	}

	public function testMissingKeysAreSilent(): void {
		$this->stored = ['comuna_cut' => '13110'];

		$this->runStep($this->step());

		self::assertSame([], $this->events);
		self::assertSame(['comuna_cut' => '13110'], $this->stored);
	}

	/** A key set to the empty string is still stored, so it is still removed (and still logged). */
	public function testAnEmptyStoredValueIsStillDeleted(): void {
		$this->stored = ['tile_url' => '', 'tile_attribution' => ''];

		$this->runStep($this->step());

		self::assertSame([], $this->stored);
		self::assertCount(2, $this->logged);
	}

	/** Only one of the two present: the other is not invented. */
	public function testOneKeyAloneIsRemovedAndLoggedOnce(): void {
		$this->stored = ['tile_attribution' => 'solo'];

		$this->runStep($this->step());

		self::assertSame([], $this->stored);
		self::assertCount(1, $this->logged);
		self::assertStringContainsString('tile_attribution', $this->logged[0]);
	}

	/** An administrator-typed value must not be able to forge a second log line. */
	public function testANewlineInTheOldValueCannotSplitTheLogLine(): void {
		$this->stored = ['tile_url' => "https://a.example/\nFAKE entry"];

		$this->runStep($this->step());

		self::assertCount(1, $this->logged);
		self::assertStringNotContainsString("\n", $this->logged[0]);
	}
}
