<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use JsonSerializable;

/**
 * The basemap the map draws on, resolved from what an administrator typed.
 *
 * ADR-0004 makes the URL a setting so a clinic that loses internet access
 * repoints it at a self-hosted server without a code change. That means the
 * Content-Security-Policy host cannot be a constant — it is derived here, from
 * the same string, so the two can never disagree.
 *
 * ADR-0019 adds the second form. A basemap is now either a directory of raster
 * tiles or a single PMTiles archive, and {@see kind} says which — because the
 * two are fetched differently and so are named by different CSP directives.
 *
 * Deliberately depends on no OCP class: this is the part worth testing, and it
 * tests in bare PHP. Reading the setting is `BasemapConfig`'s job.
 */
final class Basemap implements JsonSerializable {
	public const DEFAULT_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

	/**
	 * Plain text, not markup. Leaflet renders attribution as HTML, so anything
	 * arriving from configuration is escaped by the view; the OpenStreetMap
	 * copyright link is a constant in the component, never a stored string.
	 */
	public const DEFAULT_ATTRIBUTION = '© OpenStreetMap contributors';

	/** Leaflet substitutes these per tile; a URL without them is not a tile URL. */
	private const TILE_PLACEHOLDERS = ['{z}', '{x}', '{y}'];

	/**
	 * A directory of `<img>` tiles — `{z}/{x}/{y}.png`. Belongs in `img-src`.
	 */
	public const KIND_RASTER = 'raster';

	/**
	 * A single PMTiles archive the browser reads with HTTP Range requests.
	 * Belongs in `connect-src`: it is `fetch`ed, never an `<img>`, so a policy
	 * naming it in `img-src` blocks it while reading as though it allowed it.
	 */
	public const KIND_PMTILES = 'pmtiles';

	private function __construct(
		private readonly string $url,
		private readonly string $attribution,
		private readonly string $cspHost,
		private readonly bool $rejected,
		private readonly string $kind,
	) {
	}

	public static function resolve(?string $url, ?string $attribution): self {
		$url = trim((string)$url);
		$attribution = trim((string)$attribution);
		$origin = $url === '' ? null : self::originOf($url);
		$kind = $url === '' ? null : self::kindOf($url);

		// A rejected URL takes its attribution down with it. We fall back to
		// OpenStreetMap's tiles, and crediting a clinic's own cartography over
		// them would be both a lie and a licence breach.
		if ($origin === null || $kind === null) {
			return new self(
				self::DEFAULT_URL,
				self::DEFAULT_ATTRIBUTION,
				(string)self::originOf(self::DEFAULT_URL),
				// Blank is not a rejection: it means the setting was never used.
				$url !== '',
				self::KIND_RASTER,
			);
		}

		// Serving OpenStreetMap's tiles obliges us to credit OpenStreetMap,
		// whatever the attribution box happens to say. An administrator may
		// leave their OWN server uncredited; they may not silently drop OSM's.
		//
		// A PMTiles archive is the same obligation by another route: the Protomaps
		// basemap is an ODbL Produced Work and its own metadata declares
		// `© OpenStreetMap`. Self-hosting moves where the bytes come from, not
		// whose data it is — so a blank box credits OSM here too, and an
		// administrator serving something genuinely not OSM-derived says so in the
		// box rather than by leaving it empty.
		if (($url === self::DEFAULT_URL || $kind === self::KIND_PMTILES) && $attribution === '') {
			$attribution = self::DEFAULT_ATTRIBUTION;
		}

		return new self($url, $attribution, $origin, false, $kind);
	}

	public function url(): string {
		return $this->url;
	}

	public function attribution(): string {
		return $this->attribution;
	}

	/**
	 * How the browser fetches this basemap: {@see KIND_RASTER} or
	 * {@see KIND_PMTILES}. Decides both which Leaflet layer draws it and which
	 * Content-Security-Policy directive has to name the host.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * The single origin to name in the policy, e.g. `https://tiles.cesfam.local`.
	 * WHICH directive it goes in follows from {@see kind}: `img-src` for raster,
	 * `connect-src` for a PMTiles archive.
	 */
	public function cspHost(): string {
		return $this->cspHost;
	}

	/**
	 * Whether the tiles come from OpenStreetMap — the only thing that decides
	 * whether the view may render OpenStreetMap's own copyright link. It turns
	 * on the URL alone: what the attribution says cannot change whose tiles
	 * these are.
	 */
	public function usesOpenStreetMap(): bool {
		return $this->url === self::DEFAULT_URL;
	}

	/**
	 * True when a URL was supplied and Territorio declined to use it. Lets the
	 * settings page tell an administrator their value was not accepted, instead
	 * of leaving them to discover it from a map that quietly stayed the same.
	 */
	public function wasRejected(): bool {
		return $this->rejected;
	}

	/**
	 * @return array{url: string, attribution: string, usesOpenStreetMap: bool, kind: string}
	 */
	public function jsonSerialize(): array {
		return [
			'url' => $this->url(),
			'attribution' => $this->attribution(),
			'usesOpenStreetMap' => $this->usesOpenStreetMap(),
			'kind' => $this->kind,
		];
	}

	/**
	 * Which of the two forms this URL is, or null if it is neither.
	 *
	 * Neither means rejected. This is the test that used to live inside
	 * `originOf` as "a URL without `{z}/{x}/{y}` is not a tile URL" — true while
	 * raster was the only form, and the exact reason a `.pmtiles` setting was
	 * silently refused and left the map on OpenStreetMap.
	 */
	private static function kindOf(string $url): ?string {
		// Read off the PATH, not the whole string. A query string is not part of
		// the name — `chile.pmtiles?v=20260915` is how a re-extract is published
		// past a cache — and a raster template's braces are in the path too.
		$separator = strpos($url, '://');
		$authorityAndPath = $separator === false ? $url : substr($url, $separator + 3);
		$slash = strpos($authorityAndPath, '/');
		$path = $slash === false ? '' : substr($authorityAndPath, $slash);
		$path = (string)preg_split('/[?#]/', $path, 2)[0];

		if (str_ends_with(strtolower($path), '.pmtiles')) {
			return self::KIND_PMTILES;
		}

		foreach (self::TILE_PLACEHOLDERS as $placeholder) {
			if (!str_contains($url, $placeholder)) {
				return null;
			}
		}

		return self::KIND_RASTER;
	}

	/**
	 * The origin of a basemap URL, or null if it is not one we will put in a policy.
	 *
	 * Not parse_url: a Leaflet template contains braces, which are illegal in a
	 * URI, so parse_url's behaviour on them is not something to depend on.
	 */
	private static function originOf(string $url): ?string {
		if (preg_match('#^(https?)://([^/?\#\s]+)#i', $url, $matches) !== 1) {
			return null;
		}

		// {s} is a subdomain label Leaflet fills in per request. A policy naming
		// it literally matches no host, so the map would silently go blank —
		// the label becomes a CSP wildcard instead.
		$authority = preg_replace('/\{s\}/i', '*', $matches[2]);

		// Whatever is left must be a bare host, optionally wildcarded on its
		// leading label and optionally with a port. This is what stops a stored
		// setting from carrying a space, a semicolon or a newline into the
		// response header.
		if (preg_match('/^(\*\.)?[A-Za-z0-9.\-]+(:\d{1,5})?$/', (string)$authority) !== 1) {
			return null;
		}

		return strtolower($matches[1]) . '://' . $authority;
	}
}
