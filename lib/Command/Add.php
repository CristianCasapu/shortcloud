<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Command;

use OCA\Shortcloud\Service\LinkException;
use OCA\Shortcloud\Service\LinkService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Add extends Command {
	public function __construct(
		private LinkService $links,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('shortcloud:add')
			->setDescription('Creates a short link for any address (target policies do not apply on the command line)')
			->addArgument('target', InputArgument::REQUIRED, 'The address to shorten')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Owner of the link (an existing account)')
			->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'Custom slug instead of a random one')
			->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'One of the configured short domains')
			->addOption('title', 't', InputOption::VALUE_REQUIRED, 'A label shown in the list');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)($input->getOption('user') ?? '');
		if ($uid === '' || $this->userManager->get($uid) === null) {
			$output->writeln('<error>--user must name an existing account</error>');
			return 1;
		}
		try {
			$link = $this->links->create($uid, (string)$input->getArgument('target'), $input->getOption('domain'), $input->getOption('slug'), $input->getOption('title'), null, true);
		} catch (LinkException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln((string)$link->shortUrl);
		return 0;
	}
}
