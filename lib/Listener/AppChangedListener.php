<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Listener;

use OCA\Shortcloud\Service\UpgradeWatch;
use OCP\App\Events\AppEnableEvent;
use OCP\App\Events\AppUpdateEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * "occ upgrade" regenerates .htaccess and then updates apps, so an app update
 * is the moment to make sure the short-link rule is still there.
 *
 * @template-implements IEventListener<AppUpdateEvent|AppEnableEvent>
 */
class AppChangedListener implements IEventListener {
	public function __construct(
		private UpgradeWatch $watch,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof AppUpdateEvent) {
			$this->watch->repair('the update of ' . $event->getAppId());
		} elseif ($event instanceof AppEnableEvent) {
			$this->watch->repair('enabling ' . $event->getAppId());
		}
	}
}
