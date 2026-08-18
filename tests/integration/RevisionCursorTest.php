<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Db\RevisionLog;
use OCA\Territorio\Db\SubcategoryMapper;
use OCA\Territorio\Service\FeatureService;
use OCP\Server;

/**
 * The change cursor, driven the way a browser drives it (ADR-0002).
 *
 * This needs a real database: the Revision is an autoincrement primary key, and
 * the whole guarantee — that two saves in the same instant get different numbers
 * and that neither is skipped — is the database's, not PHP's. A mocked allocator
 * would prove nothing.
 */
final class RevisionCursorTest extends IntegrationTestCase {
	private FeatureService $service;
	private FeatureMapper $features;
	private RevisionLog $revisions;

	protected function setUp(): void {
		$this->service = Server::get(FeatureService::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->revisions = Server::get(RevisionLog::class);
	}

	/** One poll, exactly as the browser makes it. */
	private function poll(int &$cursor): array {
		$answer = $this->service->changesSince($cursor);
		$cursor = $answer['revision'];

		return array_column($answer['features'], 'name');
	}

	public function testACursorSeesEveryChangeExactlyOnce(): void {
		$cursor = $this->revisions->current();

		self::assertSame([], $this->poll($cursor), 'a fresh cursor has nothing to catch up on');

		$this->place('Primera');
		self::assertSame(['Primera'], $this->poll($cursor));

		$second = $this->place('Segunda');
		$this->service->update($second, [
			'name' => 'Segunda corregida',
			'subcategoryId' => (int)Server::get(SubcategoryMapper::class)->findAll()[0]->getId(),
			'geometry' => ['type' => 'Point', 'coordinates' => [-70.59, -33.53]],
		]);

		// Two writes, one record: the client is owed the current state once, not
		// one message per write.
		self::assertSame(['Segunda corregida'], $this->poll($cursor));

		// And nothing is replayed on the next poll.
		self::assertSame([], $this->poll($cursor));
		self::assertSame([], $this->poll($cursor));
	}

	public function testEveryWriteAdvancesTheRevision(): void {
		$before = $this->revisions->current();

		$id = $this->place('Avanza');
		$afterInsert = $this->revisions->current();
		self::assertGreaterThan($before, $afterInsert, 'an insert must advance the Revision');

		$this->service->update($id, [
			'name' => 'Avanza otra vez',
			'subcategoryId' => (int)Server::get(SubcategoryMapper::class)->findAll()[0]->getId(),
			'geometry' => null,
		]);
		self::assertGreaterThan($afterInsert, $this->revisions->current(), 'an update must advance it too');
	}

	/**
	 * The stamp is applied by the mapper, not the service, so that a caller who
	 * reaches the mapper directly still advances the cursor. If this ever fails,
	 * that write is invisible to every open map.
	 */
	public function testWritingThroughTheMapperDirectlyStillAdvancesTheCursor(): void {
		$id = $this->place('Directa');
		$cursor = $this->revisions->current();

		$feature = $this->features->find($id);
		$feature->setName('Cambiada por el mapper');
		$this->features->update($feature);

		self::assertGreaterThan($cursor, $this->revisions->current());
		self::assertSame(['Cambiada por el mapper'], $this->poll($cursor));
	}

	public function testEachFeatureCarriesTheRevisionThatLastTouchedIt(): void {
		$id = $this->place('Sellada');

		self::assertSame(
			$this->revisions->current(),
			$this->features->find($id)->getRevision(),
		);
	}
}
