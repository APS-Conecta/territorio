<?php

declare(strict_types=1);

namespace OCA\Territorio\Command;

use OCA\Territorio\Exception\InvalidImportException;
use OCA\Territorio\Service\ImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ territorio:import <archivo.geojson|.kml|.kmz|.csv> --dataset=<slug>
 *
 * The headless half of importing. The browser upload goes through the same
 * service, so the two cannot drift.
 *
 * Extends Symfony's Command directly, not OC\Core\Command\Base: Base lives
 * outside OCP and this app is OCP-only.
 */
class Import extends Command {
	public function __construct(
		private ImportService $import,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('territorio:import')
			// The formats are named here because this line is the only place the
			// command says what it takes, and a person reading `occ list` has
			// nothing else to go on. A KMZ is a KML in an archive (#103), and the
			// suffix is what picks the reader — in any case, as it always was.
			->setDescription('Importa un GeoJSON, KML, KMZ o CSV a un conjunto de datos')
			->addArgument('archivo', InputArgument::REQUIRED, 'Ruta al archivo .geojson, .kml, .kmz o .csv')
			->addOption('dataset', null, InputOption::VALUE_REQUIRED, 'Slug del conjunto de datos')
			->addOption('label', null, InputOption::VALUE_REQUIRED, 'Nombre visible del conjunto, si es nuevo')
			// There is no logged-in user on the command line, so the batch would
			// otherwise record what happened and never who asked for it.
			->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Quién ejecuta la importación', 'occ')
			// Boundary features are imported from the command line and nowhere
			// else: the browser upload is for the catastro a person curates, and
			// eleven thousand streets is not that. Producing the file is a
			// repo-side tool anyway (ADR-0004), so whoever has the file has a
			// shell.
			->addOption(
				'boundary',
				null,
				InputOption::VALUE_NONE,
				'El conjunto son límites (calles, canales, vías férreas): no se dibujan en el mapa',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = (string)$input->getArgument('archivo');
		$dataset = (string)($input->getOption('dataset') ?? '');

		// The empty-dataset rule lives in ImportService now (L1-02): with no
		// --dataset the service refuses with the same sentence the browser door
		// always used, caught by the InvalidImportException arm below and answered
		// as Command::FAILURE — the exit code moves one bucket from INVALID,
		// deliberately: one rule, one exit code.
		if (!is_readable($path)) {
			$output->writeln(sprintf('<error>No se puede leer %s</error>', $path));

			return Command::INVALID;
		}

		try {
			$batch = $this->import->importFile(
				(string)file_get_contents($path),
				$path,
				$dataset,
				// Raw, as the browser route passes it: ImportService normalises
				// the label, so --label="" means "no label" here exactly as an
				// empty box does there — one rule, one home.
				$input->getOption('label'),
				(string)$input->getOption('actor'),
				// Null, not false, when the flag is absent: "not saying" must leave
				// an existing Dataset alone. Passing false would mean a refresh that
				// forgot --boundary put the comuna's streets back on the map.
				$input->getOption('boundary') ? true : null,
			);
		} catch (InvalidImportException $e) {
			// Refused while reading, so nothing was written. A failure later, while
			// writing, still leaves a revertible batch.
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf(
			'Importado a "%s": %d creados, %d actualizados, %d sin cambios, '
			. '%d omitidos por edición local, %d duplicados en el archivo, '
			. '%d con geometría no soportada',
			$dataset,
			$batch->getCreated(),
			$batch->getUpdated(),
			$batch->getUnchanged(),
			$batch->getSkipped(),
			$batch->getDuplicates(),
			$batch->getUnsupported(),
		));
		$output->writeln(sprintf(
			'Lote #%d — revisiones %d…%d',
			$batch->getId(),
			$batch->getRevisionFrom(),
			$batch->getRevisionTo(),
		));

		return Command::SUCCESS;
	}
}
