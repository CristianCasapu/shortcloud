<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Listener;

use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\Htaccess;
use OCP\App\Events\AppEnableEvent;
use OCP\App\Events\AppUpdateEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * App updates happen during "occ upgrade", which regenerates .htaccess; put the
 * short-link rule back right away instead of waiting for the background job.
 *
 * @template-implements IEventListener<AppUpdateEvent|AppEnableEvent>
 */
class AppChangedListener implements IEventListener {
	public function __construct(
		private Config $config,
		private Htaccess $htaccess,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AppUpdateEvent && !$event instanceof AppEnableEvent) {
			return;
		}
		if (!$this->config->manageHtaccess()) {
			return;
		}
		$status = $this->htaccess->status();
		if ($status === Htaccess::STATUS_OK || $status === Htaccess::STATUS_UNAVAILABLE) {
			return;
		}
		try {
			$this->htaccess->install();
			$this->logger->info('Shortcloud restored its rewrite rule in .htaccess after an app change', ['app' => 'shortcloud']);
		} catch (\RuntimeException $e) {
			$this->logger->warning('Shortcloud could not restore its rewrite rule: ' . $e->getMessage(), ['app' => 'shortcloud']);
		}
	}
}
