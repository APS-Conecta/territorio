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
 * occ territorio:import <archivo.geojson> --dataset=<slug>
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
			->setDescription('Importa un GeoJSON a un conjunto de datos')
			->addArgument('archivo', InputArgument::REQUIRED, 'Ruta al archivo .geojson')
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

		if ($dataset === '') {
			$output->writeln('<error>Indique --dataset</error>');

			return Command::INVALID;
		}

		if (!is_readable($path)) {
			$output->writeln(sprintf('<error>No se puede leer %s</error>', $path));

			return Command::INVALID;
		}

		try {
			$batch = $this->import->importGeoJson(
				(string)file_get_contents($path),
				$dataset,
				$input->getOption('label') === null ? null : (string)$input->getOption('label'),
				(string)$input->getOption('actor'),
				(bool)$input->getOption('boundary'),
			);
		} catch (InvalidImportException $e) {
			// Refused while reading, so nothing was written. A failure later, while
			// writing, still leaves a revertible batch.
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		$output->writeln(sprintf(
			'Importado a "%s": %d creados, %d actualizados, %d sin cambios, '
			. '%d omitidos por edición local, %d duplicados en el archivo, %d aún no soportados',
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
