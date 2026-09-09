<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\BackgroundJob;

use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\Htaccess;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud rewrites the tail of .htaccess on every upgrade, which drops the
 * short-link rule. When the administrator asked the app to manage the rule,
 * this job puts it back within the hour.
 */
class HealJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Config $config,
		private Htaccess $htaccess,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->config->manageHtaccess()) {
			return;
		}
		$status = $this->htaccess->status();
		if ($status === Htaccess::STATUS_OK || $status === Htaccess::STATUS_UNAVAILABLE) {
			return;
		}
		try {
			$this->htaccess->install();
			$this->logger->info('Shortcloud restored its rewrite rule in .htaccess', ['app' => 'shortcloud']);
		} catch (\RuntimeException $e) {
			$this->logger->warning('Shortcloud could not restore its rewrite rule: ' . $e->getMessage(), ['app' => 'shortcloud']);
		}
	}
}
