<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The app version is written in three places, and `appinfo/info.xml` owns it.
 *
 * CI regenerates `openapi.json` and diffs it, but nothing failed when the README
 * line was forgotten. A missed bump of info.xml is worse: the instance keeps
 * answering from the old version, and a bump without `occ upgrade` makes every
 * page answer 503 (README, "Deploying a version bump"). This test only keeps
 * the three files agreeing; it cannot know whether the bump itself was due.
 */
final class VersionAgreementTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	public function testTheOwnerHoldsASemanticVersion(): void {
		self::assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			$this->versions()['appinfo/info.xml'],
			'appinfo/info.xml does not hold a version of the form X.Y.Z.',
		);
	}

	public function testOpenapiAndReadmeRepeatTheOwnersVersion(): void {
		$versions = $this->versions();
		$owner = $versions['appinfo/info.xml'];

		$stale = [];

		foreach (['openapi.json' => 'run make openapi', 'README.md' => 'edit its "Version X.Y.Z." line'] as $file => $fix) {
			if ($versions[$file] !== $owner) {
				$stale[] = "$file says " . ($versions[$file] ?: 'no version') . " but appinfo/info.xml says $owner: $fix.";
			}
		}

		self::assertEmpty($stale, implode("\n", $stale));
	}

	/** @return array<string, string> file => the version it states, '' when it states none */
	private function versions(): array {
		$infoXml = (string)simplexml_load_file(self::ROOT . '/appinfo/info.xml')->version;
		$openapi = json_decode(file_get_contents(self::ROOT . '/openapi.json'), true)['info']['version'] ?? '';
		preg_match('/^Version (\S+?)\. /m', file_get_contents(self::ROOT . '/README.md'), $readme);

		return [
			'appinfo/info.xml' => $infoXml,
			'openapi.json' => (string)$openapi,
			'README.md' => $readme[1] ?? '',
		];
	}
}
