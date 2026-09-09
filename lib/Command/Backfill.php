<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Command;

use OCA\Shortcloud\Service\AlbumLinks;
use OCA\Shortcloud\Service\LinkService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Gives the link shares that existed before the app was installed their short links. */
class Backfill extends Command {
	public function __construct(
		private IUserManager $userManager,
		private ShareManager $shareManager,
		private LinkService $links,
		private AlbumLinks $albums,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('shortcloud:backfill')
			->setDescription('Creates a short link for every existing public link share that has none')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only this account')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be created');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dry = (bool)$input->getOption('dry-run');
		$created = 0;
		$skipped = 0;
		$handle = function (IUser $user) use ($output, $dry, &$created, &$skipped): void {
			$shares = $this->shareManager->getSharesBy($user->getUID(), IShare::TYPE_LINK, null, false, -1, 0);
			foreach ($shares as $share) {
				if ($this->links->findForShare($share, $user->getUID()) !== []) {
					$skipped++;
					continue;
				}
				$name = '';
				try {
					$name = $share->getNode()->getPath();
				} catch (\Throwable) {
				}
				if ($dry) {
					$output->writeln($user->getUID() . ': would shorten share ' . $share->getId() . ' ' . $name);
					$created++;
					continue;
				}
				try {
					$link = $this->links->createForShare($share, $user->getUID());
					$output->writeln($user->getUID() . ': ' . $link->shortUrl . ' -> ' . $name);
					$created++;
				} catch (\Throwable $e) {
					$output->writeln('<error>' . $user->getUID() . ': share ' . $share->getId() . ': ' . $e->getMessage() . '</error>');
				}
			}
		};
		$only = $input->getOption('user');
		if ($only !== null) {
			$user = $this->userManager->get((string)$only);
			if ($user === null) {
				$output->writeln('<error>No such account</error>');
				return 1;
			}
			$handle($user);
		} else {
			$this->userManager->callForSeenUsers($handle);
		}
		$output->writeln(($dry ? 'Would create ' : 'Created ') . $created . ' short link(s), ' . $skipped . ' share(s) already had one.');
		if ($only === null && $this->albums->isAvailable()) {
			if ($dry) {
				$n = 0;
				$albums = $this->albums->listLinkAlbums();
				foreach ($albums as $token => $album) {
					if ($this->links->findForShare(AlbumLinks::PREFIX . $token) === []) {
						$output->writeln($album['owner'] . ': would shorten album link "' . $album['name'] . '"');
						$n++;
					}
				}
				$output->writeln('Would create ' . $n . ' album short link(s).');
			} else {
				$stats = $this->albums->sync();
				$output->writeln('Album links: ' . $stats['created'] . ' created, ' . $stats['gone'] . ' marked gone.');
			}
		}
		return 0;
	}
}
