<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Listener;

use OCA\Files\Event\LoadSidebar;
use OCA\Shortcloud\AppInfo\Application;
use OCA\Shortcloud\Service\Config;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Adds the "short link" entries to the sharing sidebar of the Files app.
 *
 * @template-implements IEventListener<LoadSidebar>
 */
class LoadSidebarListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private Config $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadSidebar) {
			return;
		}
		$this->initialState->provideInitialState('config', $this->config->forUser($this->config->getCurrentUserId()));
		Util::addScript(Application::APP_ID, 'shortcloud-sidebar', 'files_sharing');
	}
}
