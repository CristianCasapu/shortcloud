<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Command;

use OCA\Shortcloud\Db\LinkMapper;
use OCA\Shortcloud\Service\Config;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Delete extends Command {
	public function __construct(
		private LinkMapper $mapper,
		private Config $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('shortcloud:delete')
			->setDescription('Deletes a short link by id or by slug')
			->addArgument('link', InputArgument::REQUIRED, 'The numeric id or the slug')
			->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'The domain of the slug (default: the instance domain)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$arg = (string)$input->getArgument('link');
		try {
			$link = ctype_digit($arg)
				? $this->mapper->findById((int)$arg)
				: $this->mapper->findBySlug($input->getOption('domain') ?? $this->config->getDefaultHost(), $arg);
		} catch (DoesNotExistException) {
			$output->writeln('<error>No such short link</error>');
			return 1;
		}
		$this->mapper->delete($link);
		$output->writeln('Deleted ' . $this->config->buildShortUrl($link->getDomain(), $link->getSlug()) . ' (' . $link->getUserId() . ')');
		return 0;
	}
}
