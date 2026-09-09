<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Command;

use OCA\Shortcloud\Service\LinkService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListLinks extends Command {
	public function __construct(
		private LinkService $links,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('shortcloud:list')
			->setDescription('Lists short links')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this account')
			->addOption('search', 's', InputOption::VALUE_REQUIRED, 'Filter by slug, label or target');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$search = (string)($input->getOption('search') ?? '');
		$user = $input->getOption('user');
		$links = $user !== null ? $this->links->listForUser((string)$user, $search) : $this->links->listAll($search);
		$table = new Table($output);
		$table->setHeaders(['id', 'account', 'short link', 'status', 'hits', 'target']);
		foreach ($links as $link) {
			$table->addRow([
				$link->getId(),
				$link->getUserId(),
				$link->shortUrl,
				$link->getStatus(),
				$link->getHits(),
				mb_strimwidth($link->getTarget(), 0, 70, '…'),
			]);
		}
		$table->render();
		return 0;
	}
}
