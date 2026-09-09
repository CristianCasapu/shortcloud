<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

use OCA\Shortcloud\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Puts the rewrite rule back exactly when something could have removed it,
 * instead of polling: a Nextcloud core update changes the "version" value in
 * config.php, so the first request after an update (web or occ) notices the
 * change, checks .htaccess once and remembers the new version. App updates
 * are covered separately by AppChangedListener.
 */
class UpgradeWatch {
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private Config $settings,
		private Htaccess $htaccess,
		private LoggerInterface $logger,
	) {
	}

	/** Cheap: one in-memory config comparison per request; work only after an update. */
	public function check(): void {
		$current = $this->config->getSystemValueString('version', '');
		if ($current === '' || $this->appConfig->getValueString(Application::APP_ID, 'core_version', '') === $current) {
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, 'core_version', $current);
		$this->repair('Nextcloud ' . $current);
	}

	/** Re-installs the rule when the app manages it and it is not in place. */
	public function repair(string $reason): void {
		if (!$this->settings->manageHtaccess()) {
			return;
		}
		$status = $this->htaccess->status();
		if ($status === Htaccess::STATUS_OK || $status === Htaccess::STATUS_UNAVAILABLE) {
			return;
		}
		try {
			$this->htaccess->install();
			$this->logger->info('Shortcloud restored its rewrite rule in .htaccess after ' . $reason, ['app' => 'shortcloud']);
		} catch (\RuntimeException $e) {
			$this->logger->warning('Shortcloud could not restore its rewrite rule after ' . $reason . ': ' . $e->getMessage(), ['app' => 'shortcloud']);
		}
	}
}
