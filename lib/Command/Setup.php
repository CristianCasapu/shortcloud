<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Command;

use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\Htaccess;
use OCA\Shortcloud\Service\PrettyUrls;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Setup extends Command {
	public function __construct(
		private Htaccess $htaccess,
		private Config $config,
		private PrettyUrls $prettyUrls,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('shortcloud:setup')
			->setDescription('Installs, checks or removes the web server rule that serves short links, and switches pretty URLs')
			->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the rule from .htaccess and stop managing it')
			->addOption('check', null, InputOption::VALUE_NONE, 'Only report the current state')
			->addOption('pretty-urls', null, InputOption::VALUE_REQUIRED, 'Switch Nextcloud pretty URLs (no /index.php in addresses): on or off');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$pretty = $input->getOption('pretty-urls');
		if ($pretty !== null) {
			$on = in_array(strtolower((string)$pretty), ['on', '1', 'yes', 'true'], true);
			try {
				$on ? $this->prettyUrls->enable() : $this->prettyUrls->disable();
				$output->writeln('<info>Pretty URLs ' . ($on ? 'enabled' : 'disabled') . ', .htaccess regenerated.</info>');
			} catch (\RuntimeException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
		}
		if ($input->getOption('remove')) {
			try {
				$this->htaccess->remove();
				$this->config->update(['manageHtaccess' => false]);
				$output->writeln('<info>Rule removed from ' . $this->htaccess->path() . '</info>');
			} catch (\RuntimeException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			return 0;
		}
		if (!$input->getOption('check') && $pretty === null) {
			try {
				$this->htaccess->install();
				$this->config->update(['manageHtaccess' => true]);
				$output->writeln('<info>Rule installed in ' . $this->htaccess->path() . ' (the app now keeps it in place after upgrades)</info>');
			} catch (\RuntimeException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				$output->writeln('Add this to the web server configuration instead:');
				$output->writeln((string)$this->htaccess->block());
				return 1;
			}
		}
		$this->report($output);
		return 0;
	}

	private function report(OutputInterface $output): void {
		$output->writeln('Rule status:  ' . $this->htaccess->status());
		$output->writeln('Short links:  ' . $this->config->buildShortUrl($this->config->getDefaultHost(), '<slug>'));
		$output->writeln('Probe URL:    ' . $this->config->getPingUrl());
		$state = $this->prettyUrls->state();
		$output->writeln('Pretty URLs:  ' . ($state['configured'] ? 'on' : 'off') . ' (RewriteBase ' . $state['rewriteBase'] . ')');
		foreach ($this->config->getCustomDomains() as $d) {
			$output->writeln('Custom domain: ' . $this->config->buildShortUrl($d['host'], '<slug>') . '  probe ' . $this->config->getPingUrl($d['host']));
		}
	}
}
