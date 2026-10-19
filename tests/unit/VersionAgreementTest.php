<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The app version is owned by `appinfo/info.xml`; `openapi.json` repeats it.
 *
 * CI regenerates `openapi.json` and diffs it, but a version missed there is
 * only caught after the push. A bump of info.xml without `occ upgrade` makes
 * every page answer 503. This test only keeps the two files agreeing; it cannot
 * know whether the bump itself was due.
 */
final class VersionAgreementTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	public function testTheOwnerHoldsASemanticVersion(): void {
		self::assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			$this->owner(),
			'appinfo/info.xml does not hold a version of the form X.Y.Z.',
		);
	}

	public function testOpenapiRepeatsTheOwnersVersion(): void {
		$spec = json_decode(file_get_contents(self::ROOT . '/openapi.json'), true)['info']['version'] ?? '';

		self::assertSame(
			$this->owner(),
			$spec,
			'openapi.json says ' . ($spec ?: 'no version') . ' but appinfo/info.xml says '
			. $this->owner() . ': run make openapi.',
		);
	}

	private function owner(): string {
		return (string)simplexml_load_file(self::ROOT . '/appinfo/info.xml')->version;
	}
}
