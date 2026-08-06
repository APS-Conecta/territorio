<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use BadFunctionCallException;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\Action;
use OCA\Territorio\Service\FeatureService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Retirement and history (ADR-0005).
 *
 * The point of retiring rather than deleting is that nothing a person did is
 * ever destroyed, so these tests care most about what SURVIVES: the record, its
 * history, and the ability to bring it back unchanged.
 */
final class RetirementTest extends TestCase {
	private FeatureService $service;
	private FeatureMapper $features;
	private RevisionLog $revisions;
	/** @var int[] */
	private array $created = [];

	protected function setUp(): void {
		$this->service = Server::get(FeatureService::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->revisions = Server::get(RevisionLog::class);
	}

	protected function tearDown(): void {
		if ($this->created === []) {
			return;
		}
		$db = Server::get(IDBConnection::class);
		foreach (['territorio_feature' => 'id', 'territorio_revision' => 'feature_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter(
					$this->created, IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->executeStatement();
		}
		$this->created = [];
	}

	private function place(string $name): int {
		$id = (int)$this->service->create([
			'name' => $name,
			'subcategoryId' => (int)Server::get(SubcategoryMapper::class)->findAll()[0]->getId(),
			'address' => 'Los Bambúes 2001',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
		])->getId();
		$this->created[] = $id;

		return $id;
	}

	/** @return string[] */
	private function visibleNames(): array {
		return array_map(static fn ($f): string => $f->getName(), $this->service->listAll());
	}

	public function testRetiringRemovesARecordFromEveryDefaultRead(): void {
		$id = $this->place('Se retira');
		self::assertContains('Se retira', $this->visibleNames());

		$this->service->retire($id);

		self::assertNotContains('Se retira', $this->visibleNames());
		self::assertContains(
			'Se retira',
			array_map(static fn ($f): string => $f->getName(), $this->service->listRetired()),
		);
	}

	/**
	 * The one read that must NOT hide a retired record. A client already holding
	 * it has to be told, or it keeps drawing it on the map until someone reloads.
	 */
	public function testTheChangeFeedCarriesTheRetirementSoClientsCanStopDrawingIt(): void {
		$id = $this->place('Debe desaparecer del mapa');
		$cursor = $this->revisions->current();

		$this->service->retire($id);

		$changed = $this->service->changesSince($cursor)['features'];
		$retired = array_values(array_filter($changed, static fn (array $f): bool => $f['id'] === $id));

		self::assertCount(1, $retired, 'the retirement must reach clients through the cursor');
		self::assertTrue($retired[0]['retired']);
	}

	public function testARestoredRecordComesBackIntactWithItsHistory(): void {
		$id = $this->place('Vuelve entera');
		$this->service->retire($id);

		$restored = $this->service->restore($id);

		self::assertFalse((bool)$restored->getRetired());
		self::assertSame('Vuelve entera', $restored->getName());
		self::assertSame('Los Bambúes 2001', $restored->getAddress());
		self::assertContains('Vuelve entera', $this->visibleNames());

		$actions = array_column($this->service->history($id), 'action');
		self::assertSame(
			[Action::Restored->value, Action::Retired->value, Action::Created->value],
			$actions,
			'history is append-only and keeps every step, newest first',
		);
	}

	public function testHistoryRecordsWhatChangedAndWhatItUsedToSay(): void {
		$id = $this->place('Nombre viejo');

		$this->service->update($id, [
			'name' => 'Nombre nuevo',
			'subcategoryId' => (int)Server::get(SubcategoryMapper::class)->findAll()[0]->getId(),
			'address' => 'Los Bambúes 2001',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
		]);

		$latest = $this->service->history($id)[0];

		self::assertSame(Action::Updated->value, $latest['action']);
		self::assertSame('Nombre viejo', $latest['changes']['name']['from']);
		self::assertSame('Nombre nuevo', $latest['changes']['name']['to']);
		// Untouched fields must not appear, or the real edit is buried.
		self::assertArrayNotHasKey('address', $latest['changes']);
	}

	public function testAnEditThatChangesNothingStillRecordsWhoTouchedIt(): void {
		$id = $this->place('Sin cambios');
		$before = count($this->service->history($id));

		$this->service->update($id, [
			'name' => 'Sin cambios',
			'subcategoryId' => (int)Server::get(SubcategoryMapper::class)->findAll()[0]->getId(),
			'address' => 'Los Bambúes 2001',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
		]);

		self::assertCount($before + 1, $this->service->history($id));
		self::assertNull($this->service->history($id)[0]['changes'], 'no fields changed, so no diff');
	}

	public function testNoCodePathCanHardDeleteAFeature(): void {
		$id = $this->place('Indestructible');

		$this->expectException(BadFunctionCallException::class);
		$this->features->delete($this->features->find($id));
	}

	public function testRetirementIsRecordedAgainstThePersonWhoDidIt(): void {
		$id = $this->place('Con autor');
		$this->service->retire($id);

		$entry = $this->service->history($id)[0];

		self::assertSame(Action::Retired->value, $entry['action']);
		// No session in the test runner, so nobody is the honest answer — null
		// means "no person behind it", which is also what an occ import records.
		self::assertNull($entry['actor']);
		self::assertNotEmpty($entry['changedAt']);
	}
}
