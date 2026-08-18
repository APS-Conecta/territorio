<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use BadFunctionCallException;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\Action;
use OCA\Territorio\Exception\InvalidFeatureException;
use OCA\Territorio\Service\FeatureService;
use OCP\Server;

/**
 * Retirement and history (ADR-0005).
 *
 * The point of retiring rather than deleting is that nothing a person did is
 * ever destroyed, so these tests care most about what SURVIVES: the record, its
 * history, and the ability to bring it back unchanged.
 */
final class RetirementTest extends IntegrationTestCase {
	private FeatureService $service;
	private FeatureMapper $features;
	private RevisionLog $revisions;

	protected function setUp(): void {
		$this->service = Server::get(FeatureService::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->revisions = Server::get(RevisionLog::class);
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
		$id = $this->place('Vuelve entera', ['address' => 'Calle de Prueba 123']);
		$this->service->retire($id);

		$restored = $this->service->restore($id);

		self::assertFalse((bool)$restored->getRetired());
		self::assertSame('Vuelve entera', $restored->getName());
		self::assertSame('Calle de Prueba 123', $restored->getAddress());
		self::assertContains('Vuelve entera', $this->visibleNames());

		$actions = array_column($this->service->history($id), 'action');
		self::assertSame(
			[Action::Restored->value, Action::Retired->value, Action::Created->value],
			$actions,
			'history is append-only and keeps every step, newest first',
		);
	}

	public function testHistoryRecordsWhatChangedAndWhatItUsedToSay(): void {
		$id = $this->place('Nombre viejo', ['address' => 'Calle de Prueba 123']);

		$this->service->update($id, [
			'name' => 'Nombre nuevo',
			'subcategoryId' => (int)Server::get(SubcategoryMapper::class)->findAll()[0]->getId(),
			'address' => 'Calle de Prueba 123',
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
		$id = $this->place('Sin cambios', ['address' => 'Calle de Prueba 123']);
		$before = count($this->service->history($id));

		$this->service->update($id, [
			'name' => 'Sin cambios',
			'subcategoryId' => (int)Server::get(SubcategoryMapper::class)->findAll()[0]->getId(),
			'address' => 'Calle de Prueba 123',
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

	/**
	 * History is append-only and never tidied, so a second "Retirado" entry
	 * describing nothing would sit there permanently.
	 */
	public function testRetiringSomethingAlreadyRetiredIsRefused(): void {
		$id = $this->place('Ya retirada');
		$this->service->retire($id);

		$this->expectException(InvalidFeatureException::class);
		$this->service->retire($id);
	}

	public function testRestoringSomethingThatIsNotRetiredIsRefused(): void {
		$id = $this->place('Nunca retirada');

		$this->expectException(InvalidFeatureException::class);
		$this->service->restore($id);
	}

	/**
	 * Someone holding a record their colleague has just retired must not be able
	 * to save into it: the edit would land in a record no map shows.
	 */
	public function testEditingARetiredRecordIsRefused(): void {
		$id = $this->place('Retirada y editada');
		$this->service->retire($id);

		$this->expectException(InvalidFeatureException::class);
		$this->service->update($id, [
			'name' => 'Otro nombre',
			'subcategoryId' => $this->anySubcategoryId(),
			'geometry' => null,
		]);
	}

	/**
	 * The diff must describe only what this save writes. A field the request did
	 * not mention is not written at all, so naming it as a change would credit
	 * this save with a colleague's edit.
	 */
	public function testTheDiffCoversOnlyFieldsTheRequestActuallySent(): void {
		$id = $this->place('Parcial', ['address' => 'Dirección original', 'phone' => '900000000']);

		$this->service->update($id, [
			'name' => 'Parcial',
			'subcategoryId' => $this->anySubcategoryId(),
			'phone' => '955555555',
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.58, -33.52]],
		]);

		$changes = $this->service->history($id)[0]['changes'];

		self::assertSame('900000000', $changes['phone']['from']);
		self::assertArrayNotHasKey('address', $changes, 'address was never sent, so it did not change');
		self::assertSame('Dirección original', $this->features->find($id)->getAddress());
	}
}
