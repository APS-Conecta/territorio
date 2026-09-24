<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Service;

use OCA\Territorio\Db\Feature;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Domain\Kind;
use OCA\Territorio\Service\RegistryService;
use OCA\Territorio\Service\TaxonomyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What another app on this instance sees when it injects Territorio.
 *
 * Tested against mocked mappers and no controller at all, which is the point:
 * the service is the interface, and a test that had to go through a route would
 * be proving the route instead.
 */
final class RegistryServiceTest extends TestCase {
	private FeatureMapper&MockObject $features;
	private RegistryService $registry;

	protected function setUp(): void {
		$this->features = $this->createMock(FeatureMapper::class);

		$taxonomy = $this->createMock(TaxonomyService::class);
		$taxonomy->method('tree')->willReturn([
			['id' => 1, 'slug' => 'salud', 'label' => 'Salud', 'color' => '#dc2626', 'subcategories' => [
				['id' => 11, 'slug' => 'cesfam', 'label' => 'CESFAM'],
				['id' => 12, 'slug' => 'farmacia', 'label' => 'Farmacia'],
			]],
			['id' => 2, 'slug' => 'organizacion_social', 'label' => 'Organización social', 'color' => '#ea580c', 'subcategories' => [
				['id' => 21, 'slug' => 'taller', 'label' => 'Taller'],
			]],
		]);

		$this->registry = new RegistryService($this->features, $taxonomy);
	}

	/** The question the issue names: "how many talleres". */
	public function testItCountsByCategoryAndSubcategory(): void {
		$this->features->method('findAll')->willReturn([
			self::feature(1, 11),
			self::feature(2, 12),
			self::feature(3, 12),
			self::feature(4, 21),
		]);

		$counts = $this->registry->counts();

		self::assertSame(4, $counts['total']);
		self::assertSame(3, $counts['categories']['salud']['total']);
		self::assertSame(2, $counts['categories']['salud']['subcategories']['farmacia']);
		self::assertSame(1, $counts['categories']['organizacion_social']['subcategories']['taller']);
	}

	/**
	 * Keyed by slug, not by id. Ids differ per install and mean nothing to a
	 * caller; a slug is the stable name the seed and the imports both use.
	 */
	public function testCountsAreKeyedBySlugSoACallerCanNameWhatItWants(): void {
		$this->features->method('findAll')->willReturn([self::feature(1, 21)]);

		self::assertSame(
			['salud', 'organizacion_social'],
			array_keys($this->registry->counts()['categories']),
		);
	}

	/** A Category nobody has filed anything under still reports, as a zero. */
	public function testACategoryWithNothingInItReportsZero(): void {
		$this->features->method('findAll')->willReturn([self::feature(1, 11)]);

		$counts = $this->registry->counts();

		self::assertSame(0, $counts['categories']['organizacion_social']['total']);
		self::assertSame(0, $counts['categories']['salud']['subcategories']['farmacia']);
	}

	/**
	 * ADR-0005: retired records leave every default read. The service reads
	 * through `findAll()`, which is the one place that rule is enforced — so this
	 * pins that it does not go around it.
	 */
	public function testItReadsThroughTheOneMethodThatExcludesRetiredRecords(): void {
		$this->features->expects(self::once())->method('findAll')->willReturn([]);
		$this->features->expects(self::never())->method('findRetired');

		self::assertSame(0, $this->registry->counts()['total']);
	}

	/**
	 * A Feature pointing at a Subcategory the taxonomy no longer has is counted
	 * under unknownSubcategory rather than dropped — a total that quietly disagrees with
	 * the Registry is worse than an awkward one.
	 */
	public function testAFeatureWithAnUnknownSubcategoryIsStillCounted(): void {
		$this->features->method('findAll')->willReturn([self::feature(1, 11), self::feature(2, 404)]);

		$counts = $this->registry->counts();

		self::assertSame(2, $counts['total']);
		self::assertSame(1, $counts['unknownSubcategory']);
	}

	public function testItLooksUpOneRecord(): void {
		$this->features->method('findAll')->willReturn([self::feature(7, 11, 'CESFAM Uno')]);

		$found = $this->registry->find(7);

		self::assertSame('CESFAM Uno', $found['name']);
		self::assertSame('salud', $found['category']);
		self::assertSame('cesfam', $found['subcategory']);
	}

	/** Null, not an exception: "is this id still there" is a fair question. */
	public function testLookingUpSomethingThatIsNotThereIsNotAnError(): void {
		$this->features->method('findAll')->willReturn([]);

		self::assertNull($this->registry->find(404));
	}

	/** "Every taller in the comuna", which is the other half of the same question. */
	public function testItListsBySubcategorySlug(): void {
		$this->features->method('findAll')->willReturn([
			self::feature(1, 11, 'CESFAM Uno'),
			self::feature(2, 21, 'Taller de Prueba'),
			self::feature(3, 21, 'Otro taller'),
		]);

		$talleres = $this->registry->inSubcategory('organizacion_social', 'taller');

		self::assertSame(['Taller de Prueba', 'Otro taller'], array_column($talleres, 'name'));
	}

	public function testAnUnknownSubcategoryNamesNothingRatherThanEverything(): void {
		$this->features->method('findAll')->willReturn([self::feature(1, 11)]);

		// Null, not []: the pair names nothing, which is a different answer
		// than "real pair, nothing filed under it" — the OCS door 404s the
		// first and 200s the second (L0-05).
		self::assertNull($this->registry->inSubcategory('salud', 'no_existe'));
	}

	private static function feature(int $id, int $subcategoryId, string $name = 'Registro'): Feature {
		$feature = new Feature();
		$feature->setId($id);
		$feature->setSubcategoryId($subcategoryId);
		$feature->setName($name);
		$feature->setKind(Kind::Place->value);

		return $feature;
	}
}
