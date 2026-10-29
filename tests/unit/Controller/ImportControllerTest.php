<?php

declare(strict_types=1);

namespace OCA\Territorio\Tests\Unit\Controller;

use OCA\Territorio\Controller\ImportController;
use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Db\ImportBatch;
use OCA\Territorio\Service\ImportFiles;
use OCA\Territorio\Service\ImportService;
use OCA\Territorio\Service\RevertService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Importing a file that is already in Nextcloud Files (#104).
 *
 * The refusals are what is asserted here, asserted THROUGH the controller,
 * which is where a refusal becomes the 422 the dialog shows: the acquisition
 * machinery now lives in `ImportFiles` (L1-04), built real in this suite over
 * the same mocked OCP edges. A path is a trust boundary in a way an uploaded
 * temporary file is not. The Files API is mocked — whether `getUserFolder()`
 * really mounts a share is Nextcloud's test to write, and the browser gate
 * drives the whole thing end to end.
 *
 * Every one of these used to be a 500: nothing in this controller caught a
 * file-system exception, because nothing in this app had ever touched Files.
 */
final class ImportControllerTest extends TestCase {
	/** The caller. A path is resolved against THIS user's folder and no other. */
	private const UID = 'alicia';

	private array $params = [];
	private IRequest&MockObject $request;
	private ImportService&MockObject $import;
	private IRootFolder&MockObject $root;
	private Folder&MockObject $home;
	private ImportController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $this->params[$key] ?? $default,
		);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn(self::UID);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->import = $this->createMock(ImportService::class);
		$this->home = $this->createMock(Folder::class);
		$this->root = $this->createMock(IRootFolder::class);
		$this->root->method('getUserFolder')->with(self::UID)->willReturn($this->home);

		$this->controller = new ImportController(
			$this->request,
			$this->import,
			$this->createStub(RevertService::class),
			$session,
			new ImportFiles($this->request, $session, $this->root),
		);
	}

	/** A node in the caller's folder, with the size and name the test cares about. */
	private function node(int $size, string $name, string $contents = '<kml/>'): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn($size);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($contents);

		return $file;
	}

	/**
	 * The reader is picked from the file's OWN name, not from how it arrived.
	 *
	 * Which is the whole reason the node's `getName()` is what reaches
	 * `importFile()` rather than the last segment of the path the browser sent:
	 * the name is the server's, and the suffix dispatch in `ImportService` is the
	 * one an upload goes through.
	 */
	public function testAFileInFilesIsReadFromTheCallersOwnFolderAndImportedByItsOwnName(): void {
		$this->params = ['dataset' => 'catastro', 'path' => '/Documentos/catastro.kml'];
		$this->home->expects(self::once())
			->method('get')
			->with('/Documentos/catastro.kml')
			->willReturn($this->node(1024, 'catastro.kml', '<kml>bytes</kml>'));

		$this->import->expects(self::once())
			->method('importFile')
			->with('<kml>bytes</kml>', 'catastro.kml', 'catastro', '', self::UID)
			->willReturn(new ImportBatch());

		self::assertSame(Http::STATUS_OK, $this->controller->upload()->getStatus());
	}

	/**
	 * Resolution goes through the caller's own user folder and nowhere else:
	 * resolving from the root, or from a path the client sends whole, is what
	 * would make somebody else's file reachable.
	 *
	 * That is also the reason a file shared WITH the caller is importable — a
	 * share is mounted in their user folder, so it is reachable by design rather
	 * than by a missing check. This test does NOT exercise a share: the harness
	 * has one account. The name says what is measured so a green tick is not
	 * read as evidence of the share.
	 */
	public function testAPathIsResolvedOnlyThroughTheCallersOwnFolder(): void {
		$this->params = ['dataset' => 'catastro', 'path' => '/Compartido/catastro.geojson'];
		$this->root->expects(self::once())->method('getUserFolder')->with(self::UID);
		$this->home->method('get')->willReturn($this->node(512, 'catastro.geojson', '{}'));
		$this->import->expects(self::once())->method('importFile')->willReturn(new ImportBatch());

		self::assertSame(Http::STATUS_OK, $this->controller->upload()->getStatus());
	}

	/**
	 * The 8 MB cap is about this process's memory, not about how the bytes
	 * arrived: `getContent()` reads the node whole, so the size is checked first
	 * and the node is never read at all.
	 */
	public function testAFileOverTheCapIsRefusedWithoutBeingRead(): void {
		$this->params = ['dataset' => 'catastro', 'path' => '/enorme.geojson'];
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(8 * 1024 * 1024 + 1);
		$file->expects(self::never())->method('getContent');
		$this->home->method('get')->willReturn($file);
		$this->import->expects(self::never())->method('importFile');

		$response = $this->controller->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('El archivo supera los 8 MB.', $response->getData()['message']);
	}

	/** And it is the same sentence an oversized upload gets, from one constant. */
	public function testTheOversizedMessageIsTheOneAnUploadGets(): void {
		$this->params = ['dataset' => 'catastro'];
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'enorme.geojson',
			'error' => UPLOAD_ERR_OK,
			'size' => 8 * 1024 * 1024 + 1,
			'tmp_name' => '/dev/null',
		]);

		self::assertSame(
			'El archivo supera los 8 MB.',
			$this->controller->upload()->getData()['message'],
		);
	}

	/**
	 * A path outside the caller's tree does not resolve, and the refusal is the
	 * 422 the dialog knows how to show — never the 500 an uncaught
	 * `NotFoundException` would be.
	 */
	public function testAPathTheCallerCannotReachIsRefusedInSpanish(): void {
		$this->params = ['dataset' => 'catastro', 'path' => '/../admin/secreto.geojson'];
		$this->home->method('get')->willThrowException(new NotFoundException());
		$this->import->expects(self::never())->method('importFile');

		$response = $this->controller->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(
			'No se encontró ese archivo en sus archivos de Nextcloud.',
			$response->getData()['message'],
		);
	}

	/** A folder resolves perfectly well and has no content to read. */
	public function testAFolderIsRefusedRatherThanRead(): void {
		$this->params = ['dataset' => 'catastro', 'path' => '/Documentos'];
		$this->home->method('get')->willReturn($this->createMock(Folder::class));
		$this->import->expects(self::never())->method('importFile');

		$response = $this->controller->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('Eso es una carpeta, no un archivo.', $response->getData()['message']);
	}

	/** So does a file whose bytes cannot be read — locked, or no longer readable. */
	public function testAFileThatCannotBeReadIsRefusedRatherThanCrashing(): void {
		$this->params = ['dataset' => 'catastro', 'path' => '/bloqueado.geojson'];
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn(64);
		$file->method('getContent')->willThrowException(new NotPermittedException());
		$this->home->method('get')->willReturn($file);

		$response = $this->controller->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(
			'No se pudo leer ese archivo de Nextcloud.',
			$response->getData()['message'],
		);
	}

	/**
	 * The Dataset is still required (L1-02) — refused by the SERVICE now, not
	 * the door, so `occ` and any future entry point inherit the same sentence.
	 * The file is read first; that is the change this test watches, on purpose.
	 */
	public function testAPathWithoutADatasetIsRefusedWithTheOneSentence(): void {
		$this->params = ['path' => '/catastro.geojson'];
		$this->home->method('get')->willReturn($this->node(512, 'catastro.geojson', '{}'));
		$this->import->method('importFile')
			->willThrowException(new InvalidImportException('Indique a qué conjunto de datos importar.'));

		$response = $this->controller->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(
			'Indique a qué conjunto de datos importar.',
			$response->getData()['message'],
		);
	}

	/** #207: an array `path` counts as no path — the same rule `label`
	 *  already has — so nothing is looked up in Files and the uploaded-file
	 *  door answers instead. A cast would have looked up a file literally
	 *  named "Array" in the caller's tree. */
	public function testAPathThatArrivesAsAListIsLookedUpNowhere(): void {
		$this->params = ['dataset' => 'catastro', 'path' => ['x']];
		$this->root->expects(self::never())->method('getUserFolder');
		$this->import->expects(self::never())->method('importFile');

		$response = $this->controller->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('No llegó ningún archivo.', $response->getData()['message']);
	}

	/**
	 * And the upload it shares its body with still imports — the branch is a
	 * second source for the same call, not a second import path.
	 */
	public function testAnUploadStillReachesTheSameImport(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'territorio');
		file_put_contents($tmp, '{"type":"FeatureCollection","features":[]}');
		$this->params = ['dataset' => 'catastro'];
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'catastro.geojson',
			'error' => UPLOAD_ERR_OK,
			'size' => filesize($tmp),
			'tmp_name' => $tmp,
		]);

		$this->import->expects(self::once())
			->method('importFile')
			->with(
				'{"type":"FeatureCollection","features":[]}',
				'catastro.geojson',
				'catastro',
				'',
				self::UID,
			)
			->willReturn(new ImportBatch());

		self::assertSame(Http::STATUS_OK, $this->controller->upload()->getStatus());
		unlink($tmp);
	}

	/**
	 * #207: `dataset[]=x` used to cast to the slug "Array" and import the
	 * rows into a Dataset so labelled, through a 200. An array counts as no
	 * dataset now — is_string, the rule `label` already has — so ImportService
	 * receives the empty slug and refuses with its own sentence, before any
	 * Dataset or batch exists. The file is read first, exactly as a path
	 * without a dataset is: the refusal is the service's, not the door's.
	 */
	public function testADatasetThatArrivesAsAListCountsAsNoDataset(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'territorio');
		file_put_contents($tmp, '{"type":"FeatureCollection","features":[]}');
		$this->params = ['dataset' => ['x']];
		$this->request->method('getUploadedFile')->willReturn([
			'name' => 'catastro.geojson',
			'error' => UPLOAD_ERR_OK,
			'size' => filesize($tmp),
			'tmp_name' => $tmp,
		]);

		$this->import->expects(self::once())
			->method('importFile')
			->with(
				'{"type":"FeatureCollection","features":[]}',
				'catastro.geojson',
				'',
				'',
				self::UID,
			)
			->willThrowException(new InvalidImportException('Indique a qué conjunto de datos importar.'));

		$response = $this->controller->upload();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(
			'Indique a qué conjunto de datos importar.',
			$response->getData()['message'],
		);
		unlink($tmp);
	}

	/** L0-03: a malformed batch id is the same not-found as a batch nobody has. */
	public function testAMalformedBatchIdIsTheSameNotFound(): void {
		$reverts = $this->createMock(RevertService::class);
		$reverts->expects(self::never())->method('revert');
		$session = $this->createStub(IUserSession::class);
		$request = $this->createStub(IRequest::class);
		$controller = new ImportController(
			$request,
			$this->import,
			$reverts,
			$session,
			new ImportFiles($request, $session, $this->root),
		);

		$response = $controller->revert('retired');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Ese lote no existe.', $response->getData()['message']);
	}
}
