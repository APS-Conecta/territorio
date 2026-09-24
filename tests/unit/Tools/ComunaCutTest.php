<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Tools;

use InvalidArgumentException;
use OCA\Territorio\Domain\Comuna;
use PHPUnit\Framework\TestCase;

use function Territorio\Tools\Comuna\cutComuna;

require_once __DIR__ . '/../../../tools/comuna-cut.php';

/**
 * Cutting the national Unidades Vecinales file down to one comuna.
 *
 * The fixture is shaped like `UnidadesVecinales_2024v4` — the attribute names
 * are the real ones (`t_com`, `t_id_uv_ca`, `t_uv_nom`, `uv_carto`), because
 * matching them is the whole job — with invented values. The national file is
 * 128 MB, 6,887 polygons across 346 comunas, and the app must never need it.
 */
final class ComunaCutTest extends TestCase {
	public function testItKeepsOnlyTheComunaAskedFor(): void {
		$cut = cutComuna(self::NATIONAL, '13110');

		self::assertCount(2, $cut['features']);
		self::assertSame(
			['Villa de Prueba', 'Unidad Vecinal 7'],
			array_column(array_column($cut['features'], 'properties'), 'name'),
		);
	}

	/**
	 * The file says which comuna it is, so the app can refuse one meant for
	 * somewhere else. On the collection rather than on every feature: it is one
	 * fact about the file, and repeating it 37 times invites the copies to
	 * disagree.
	 */
	public function testTheFileSaysWhichComunaItIsFor(): void {
		self::assertSame('13110', cutComuna(self::NATIONAL, '13110')['comuna']);
	}

	/**
	 * `t_id_uv_ca` is nationally unique and is what a re-import matches on
	 * (ADR-0003), so a boundary corrected upstream updates the record it already
	 * has instead of arriving as a second copy.
	 */
	public function testEachUnidadVecinalKeepsItsNationalId(): void {
		self::assertSame(
			['13110-0003', '13110-0007'],
			array_column(cutComuna(self::NATIONAL, '13110')['features'], 'id'),
		);
	}

	/** They are a territorial division, so they toggle beside the Category tree. */
	public function testTheyAreFiledAsUnidadesVecinales(): void {
		foreach (cutComuna(self::NATIONAL, '13110')['features'] as $feature) {
			self::assertSame('division_territorial', $feature['properties']['category']);
			self::assertSame('unidad_vecinal', $feature['properties']['subcategory']);
		}
	}

	/**
	 * A Unidad Vecinal is "numbered within its comuna" (CONTEXT.md), and plenty
	 * carry no name at all — the number is what people actually say.
	 */
	public function testAnUnnamedUnidadVecinalIsCalledByItsNumber(): void {
		$features = cutComuna(self::NATIONAL, '13110')['features'];

		self::assertSame('Unidad Vecinal 7', $features[1]['properties']['name']);
	}

	public function testGeometryIsCarriedThroughUntouched(): void {
		$feature = cutComuna(self::NATIONAL, '13110')['features'][0];

		self::assertSame('Polygon', $feature['geometry']['type']);
		self::assertSame([[[-70.60, -33.53], [-70.58, -33.53], [-70.58, -33.51], [-70.60, -33.53]]],
			$feature['geometry']['coordinates']);
	}

	/**
	 * A CUT code that matches nothing is a typo, not an empty comuna — and
	 * writing an empty file would look like the comuna simply has no Unidades
	 * Vecinales.
	 */
	public function testACodeThatMatchesNothingIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		cutComuna(self::NATIONAL, '99999');
	}

	public function testACodeThatIsNotACutCodeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		cutComuna(self::NATIONAL, 'la florida');
	}

	/** The national file is EPSG:4674 — already degrees, so nothing reprojects. */
	public function testSomethingThatIsNotAFeatureCollectionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		cutComuna(['type' => 'Topology'], '13110');
	}

	/** @var array<string, mixed> */
	private const NATIONAL = [
		'type' => 'FeatureCollection',
		'features' => [
			[
				'type' => 'Feature',
				'properties' => [
					't_com' => '13110', 't_com_nom' => 'Comuna de Prueba',
					't_id_uv_ca' => '13110-0003', 'uv_carto' => '3', 't_uv_nom' => 'Villa de Prueba',
				],
				'geometry' => ['type' => 'Polygon', 'coordinates' => [[
					[-70.60, -33.53], [-70.58, -33.53], [-70.58, -33.51], [-70.60, -33.53],
				]]],
			],
			[
				'type' => 'Feature',
				'properties' => [
					't_com' => '13110', 't_com_nom' => 'Comuna de Prueba',
					't_id_uv_ca' => '13110-0007', 'uv_carto' => '7', 't_uv_nom' => '',
				],
				'geometry' => ['type' => 'Polygon', 'coordinates' => [[
					[-70.58, -33.53], [-70.56, -33.53], [-70.56, -33.51], [-70.58, -33.53],
				]]],
			],
			[
				'type' => 'Feature',
				'properties' => [
					't_com' => '13101', 't_com_nom' => 'Otra Comuna',
					't_id_uv_ca' => '13101-0001', 'uv_carto' => '1', 't_uv_nom' => 'Ajena',
				],
				'geometry' => ['type' => 'Polygon', 'coordinates' => [[
					[-70.70, -33.43], [-70.68, -33.43], [-70.68, -33.41], [-70.70, -33.43],
				]]],
			],
		],
	];
}
