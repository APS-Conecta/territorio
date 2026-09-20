<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Integration;

use OCA\Territorio\Controller\ImportController;
use OCA\Territorio\Db\DatasetMapper;
use OCA\Territorio\Db\FeatureMapper;
use OCA\Territorio\Service\ImportFiles;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RevertService;
use OCP\AppFramework\Http;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Server;

/**
 * Importing a file that is already in Nextcloud Files (#104), against the real
 * Files API rather than a mock of it.
 *
 * `tests/unit/Controller/ImportControllerTest.php` pins the refusals with a
 * mocked root folder, which is where judgement belongs. What a mock cannot
 * answer is the one thing this branch rests on: that the path the platform's
 * file picker hands back — absolute, inside the user's own root — is a path
 * `Folder::get()` resolves. It is asserted here because getting it wrong would
 * look exactly like a green unit suite.
 *
 * The user is `admin` because that is the only account compose.dev.yaml creates.
 */
final class FilesImportTest extends IntegrationTestCase {
	private const UID = 'admin';
	private const DATASET = 'test-files-import';
	private const NAME = 'territorio-test-104.geojson';

	private const GEOJSON = <<<'JSON'
	{"type":"FeatureCollection","features":[
	  {"type":"Feature","geometry":{"type":"Point","coordinates":[-70.58,-33.52]},
	   "properties":{"uid":"t:files","name":"Plaza de Archivos","category":"deporte_recreacion",
	     "subcategory":"plaza","source":"prueba"}}
	]}
	JSON;

	private IRootFolder $root;
	private FeatureMapper $features;

	protected function setUp(): void {
		$this->root = Server::get(IRootFolder::class);
		$this->features = Server::get(FeatureMapper::class);
		$this->removeFile();
		$this->forgetDataset();
	}

	protected function tearDown(): void {
		$this->removeFile();
		$this->forgetDataset();
		parent::tearDown();
	}

	/**
	 * The whole slice, end to end below the browser: a file sitting in Files is
	 * read from the caller's own folder, imported by its own name, and the batch
	 * it records is revertible like any other (ADR-0009).
	 */
	public function testAFileInFilesImportsAndTheBatchCanBeReverted(): void {
		$this->root->getUserFolder(self::UID)->newFile(self::NAME, self::GEOJSON);

		$response = $this->controller(['dataset' => self::DATASET, 'path' => '/' . self::NAME])
			->upload();

		self::assertSame(Http::STATUS_OK, $response->getStatus(), (string)json_encode($response->getData()));
		self::assertSame(1, $response->getData()['created']);

		$dataset = Server::get(DatasetMapper::class)->findBySlug(self::DATASET);
		$imported = $this->features->findByDataset((int)$dataset->getId());
		self::assertCount(1, $imported);
		// Keyed by external id, which is the `uid` the GeoJSON carries — asserting
		// on the key is how the rest of the suite reads this mapper.
		self::assertSame('Plaza de Archivos', $imported['t:files']->getName());

		Server::get(RevertService::class)->revert((int)$response->getData()['id'], self::UID);
		self::assertTrue(
			(bool)$this->features->find((int)$imported['t:files']->getId())->getRetired(),
			'a batch imported from Files reverts like one that was uploaded',
		);
	}

	/** A path that is not in the caller's tree is a sentence, not a stack trace. */
	public function testAPathThatDoesNotResolveIsRefusedWith422(): void {
		$response = $this->controller([
			'dataset' => self::DATASET,
			'path' => '/no-existe-' . self::NAME,
		])->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(
			'No se encontró ese archivo en sus archivos de Nextcloud.',
			$response->getData()['message'],
		);
	}

	/** The controller as the app wires it, with this test's parameters and user. */
	private function controller(array $params): ImportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $params[$key] ?? $default,
		);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn(self::UID);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ImportController(
			$request,
			Server::get(ImportService::class),
			Server::get(RevertService::class),
			$session,
			new ImportFiles($request, $session, $this->root),
		);
	}

	private function removeFile(): void {
		try {
			$this->root->getUserFolder(self::UID)->get('/' . self::NAME)->delete();
		} catch (NotFoundException) {
			// Nothing to clean up, which is the usual case.
		}
	}

	/** Imported rows are not tracked by $created, so clean by Dataset. */
	private function forgetDataset(): void {
		$db = Server::get(IDBConnection::class);

		try {
			$dataset = Server::get(DatasetMapper::class)->findBySlug(self::DATASET);
		} catch (\Throwable) {
			return;
		}
		$id = (int)$dataset->getId();

		foreach ($this->features->findByDataset($id) as $feature) {
			$qb = $db->getQueryBuilder();
			$qb->delete('territorio_revision')
				->where($qb->expr()->eq('feature_id', $qb->createNamedParameter(
					(int)$feature->getId(), IQueryBuilder::PARAM_INT,
				)))->executeStatement();
		}
		foreach (['territorio_feature' => 'dataset_id', 'territorio_import' => 'dataset_id'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq($column, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		$qb = $db->getQueryBuilder();
		$qb->delete('territorio_dataset')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
