<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit;

use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * The app's two icon files, and the one thing that makes them two files (#26).
 *
 * Nextcloud 34 picks between them by name — `AppManager::getAppIcon()` at
 * `lib/private/App/AppManager.php:118` takes `['app.svg']` or `['app-dark.svg']`
 * depending on its `$dark` flag — and every surface that shows the app's own
 * iconography asks WITHOUT it:
 *
 *   - the header's current-app button and the waffle grid, through
 *     `NavigationManager::add()` at `lib/private/NavigationManager.php:353`;
 *   - the favicon and the touch icon, through `Theming\Util::getAppIcon()`,
 *     which looks for `img/app.svg` and composites it onto the primary colour.
 *
 * All three paint the glyph on a dark or primary-coloured surface, so the light
 * variant has to BE white; core's own CSS states it outright in
 * `core/src/components/AppItem.vue` — "App icons are bright by default; flip
 * them to dark when the primary color is bright" — and does the flipping with
 * `filter: var(--primary-invert-if-bright)`. Only `OC_App::listAllApps()`
 * (`lib/private/legacy/OC_App.php:440`) asks `getAppIcon()` with `dark: true`,
 * for the apps list, which is a light page and wants the black glyph — and this
 * app asks for `app-dark.svg` by name, outside `getAppIcon()` entirely, in
 * `AdminSection::getIcon()` (`lib/Settings/AdminSection.php:32`) for its own
 * Settings section, which is light as well.
 *
 * None of that is in the developer manual: it documents the navigation `icon`
 * key as "defaults to app.svg" and says nothing about `app-dark.svg` or about
 * colour (checked 2026-09-15 against the info.xml and Icons pages of the 34
 * manual). Hence the citations above point at the server's source, which is
 * where the convention actually lives.
 *
 * The files are checked here rather than in `tools/browser-check.py` because
 * this is a fact about two files in the repo; the browser gate proves the other
 * half — that these bytes are what the running app serves and paints.
 */
final class AppIconTest extends TestCase {
	private const LIGHT = __DIR__ . '/../../img/app.svg';
	private const DARK = __DIR__ . '/../../img/app-dark.svg';

	public function testTheLightIconIsAWhiteGlyph(): void {
		self::assertStringContainsString(
			'fill="#fff"',
			file_get_contents(self::LIGHT),
			'img/app.svg is what the header, the app menu and the favicon draw on a dark or '
			. 'primary-coloured surface. Without a fill it is black, and invisible there.',
		);
	}

	public function testTheDarkIconIsNotAWhiteGlyph(): void {
		self::assertStringNotContainsString(
			'fill=',
			file_get_contents(self::DARK),
			'img/app-dark.svg is drawn on the white apps list, where the default fill — black — '
			. 'is the one that reads.',
		);
	}

	public function testTheTwoIconsDrawTheSameGlyph(): void {
		self::assertSame(
			$this->glyph(self::DARK),
			$this->glyph(self::LIGHT),
			'The two files are one drawing in two colours. Editing the artwork in one of them and '
			. 'not the other gives the app two different icons depending on which surface you '
			. 'look at.',
		);
	}

	public function testEachIconSaysWhichSurfaceItServes(): void {
		foreach ([self::LIGHT, self::DARK] as $path) {
			self::assertMatchesRegularExpression(
				'/<!--.*getAppIcon.*-->/s',
				file_get_contents($path),
				basename($path) . ' carries no comment naming the surface it serves. The two files '
				. 'differ by one attribute; the reason has to be written down beside it or the '
				. 'next person deletes one of them.',
			);
		}
	}

	public function testEachIconIsWellFormedXml(): void {
		foreach ([self::LIGHT, self::DARK] as $path) {
			$document = new DOMDocument();

			self::assertTrue(
				@$document->loadXML(file_get_contents($path)),
				basename($path) . ' is not well-formed XML, so librsvg refuses it and the favicon '
				. 'silently falls back to the Nextcloud logo. The trap is the comments above: a '
				. 'CSS custom property written out in full puts a double hyphen inside an XML '
				. 'comment, which is illegal, and nothing else in the repo would notice.',
			);
		}
	}

	public function testEachIconStartsWithItsRootElement(): void {
		foreach ([self::LIGHT, self::DARK] as $path) {
			self::assertStringStartsWith(
				'<svg',
				file_get_contents($path),
				basename($path) . ' does not start with its root element. IconBuilder decides a '
				. 'file is an SVG by looking for a leading "<svg" or "<?xml" before handing it to '
				. 'Imagick, so anything in front of the root element — a comment, a blank line — '
				. 'costs the app its favicon and its touch icon, silently.',
			);
		}
	}

	public function testTheDarkIconNamesItsInTreeCallers(): void {
		$callers = $this->inTreeCallersOf('app-dark.svg');

		self::assertNotEmpty(
			$callers,
			'No file under lib/ asks for app-dark.svg any more. Either this app stopped using it '
			. '— in which case the comment in the file is now wrong — or the search below broke.',
		);

		foreach ($callers as $caller) {
			self::assertStringContainsString(
				$caller,
				file_get_contents(self::DARK),
				'img/app-dark.svg does not name ' . $caller . ', which asks for it from inside '
				. 'this repo. The comment exists to stop a future deleter, and a deleter greps '
				. 'this repo, not the server. A caller it does not list is a caller that breaks '
				. 'silently.',
			);
		}
	}

	/** The classes under lib/ that name an image file — the callers a grep of this repo finds. */
	private function inTreeCallersOf(string $image): array {
		$callers = [];

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(__DIR__ . '/../../lib'),
		);

		foreach ($files as $file) {
			if ($file->getExtension() === 'php'
				&& str_contains(file_get_contents($file->getPathname()), $image)) {
				$callers[] = $file->getBasename('.php');
			}
		}

		return $callers;
	}

	/** The drawing alone: no comments, no fill attribute, no incidental whitespace. */
	private function glyph(string $path): string {
		$svg = preg_replace('/<!--.*?-->/s', '', file_get_contents($path));
		$svg = trim(preg_replace('/\s+/', ' ', $svg));

		return str_replace(' fill="#fff"', '', $svg);
	}
}
