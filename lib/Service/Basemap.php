<?php

declare(strict_types=1);

namespace OCA\Territorio\Service;

use JsonSerializable;

/**
 * The tile service the map draws on, resolved from what an administrator typed.
 *
 * ADR-0004 makes the tile URL a setting so a clinic that loses internet access
 * repoints it at a self-hosted server without a code change. That means the
 * Content-Security-Policy host cannot be a constant — it is derived here, from
 * the same string, so the two can never disagree.
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

	private function __construct(
		private readonly string $url,
		private readonly string $attribution,
		private readonly string $cspHost,
		private readonly bool $rejected,
	) {
	}

	public static function resolve(?string $url, ?string $attribution): self {
		$url = trim((string)$url);
		$attribution = trim((string)$attribution);
		$origin = $url === '' ? null : self::originOf($url);

		// A rejected URL takes its attribution down with it. We fall back to
		// OpenStreetMap's tiles, and crediting a clinic's own cartography over
		// them would be both a lie and a licence breach.
		if ($origin === null) {
			return new self(
				self::DEFAULT_URL,
				self::DEFAULT_ATTRIBUTION,
				(string)self::originOf(self::DEFAULT_URL),
				// Blank is not a rejection: it means the setting was never used.
				$url !== '',
			);
		}

		// Serving OpenStreetMap's tiles obliges us to credit OpenStreetMap,
		// whatever the attribution box happens to say. An administrator may
		// leave their OWN server uncredited; they may not silently drop OSM's.
		if ($url === self::DEFAULT_URL && $attribution === '') {
			$attribution = self::DEFAULT_ATTRIBUTION;
		}

		return new self($url, $attribution, $origin, false);
	}

	public function url(): string {
		return $this->url;
	}

	public function attribution(): string {
		return $this->attribution;
	}

	/** The single origin to name in `img-src`, e.g. `https://tile.openstreetmap.org`. */
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

	/** @return array{url: string, attribution: string, usesOpenStreetMap: bool} */
	public function jsonSerialize(): array {
		return [
			'url' => $this->url,
			'attribution' => $this->attribution,
			'usesOpenStreetMap' => $this->usesOpenStreetMap(),
		];
	}

	/**
	 * The origin of a tile URL, or null if it is not one we will put in a policy.
	 *
	 * Not parse_url: a Leaflet template contains braces, which are illegal in a
	 * URI, so parse_url's behaviour on them is not something to depend on.
	 */
	private static function originOf(string $url): ?string {
		foreach (self::TILE_PLACEHOLDERS as $placeholder) {
			if (!str_contains($url, $placeholder)) {
				return null;
			}
		}

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
