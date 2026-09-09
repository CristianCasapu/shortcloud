<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\AppInfo;

use OCA\Files\Event\LoadSidebar;
use OCA\Shortcloud\Listener\AppChangedListener;
use OCA\Shortcloud\Listener\LoadSidebarListener;
use OCA\Shortcloud\Listener\ShareCreatedListener;
use OCA\Shortcloud\Listener\ShareDeletedListener;
use OCA\Shortcloud\Listener\UserDeletedListener;
use OCA\Shortcloud\Service\UpgradeWatch;
use OCA\Shortcloud\SetupCheck\RewriteCheck;
use OCP\App\Events\AppEnableEvent;
use OCP\App\Events\AppUpdateEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'shortcloud';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(ShareCreatedEvent::class, ShareCreatedListener::class);
		$context->registerEventListener(ShareDeletedEvent::class, ShareDeletedListener::class);
		$context->registerEventListener(LoadSidebar::class, LoadSidebarListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(AppUpdateEvent::class, AppChangedListener::class);
		$context->registerEventListener(AppEnableEvent::class, AppChangedListener::class);
		$context->registerSetupCheck(RewriteCheck::class);
	}

	public function boot(IBootContext $context): void {
		// one config comparison per request; real work only right after a Nextcloud update
		$context->injectFn(static function (UpgradeWatch $watch): void {
			try {
				$watch->check();
			} catch (\Throwable) {
				// never let the watch break a request
			}
		});
	}
}
