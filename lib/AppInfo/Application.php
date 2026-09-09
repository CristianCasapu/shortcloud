<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\AppInfo;

use OCA\Files\Event\LoadSidebar;
use OCA\Shortcloud\Listener\LoadSidebarListener;
use OCA\Shortcloud\Listener\ShareCreatedListener;
use OCA\Shortcloud\Listener\ShareDeletedListener;
use OCA\Shortcloud\SetupCheck\RewriteCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'shortcloud';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(ShareCreatedEvent::class, ShareCreatedListener::class);
		$context->registerEventListener(ShareDeletedEvent::class, ShareDeletedListener::class);
		$context->registerEventListener(LoadSidebar::class, LoadSidebarListener::class);
		$context->registerSetupCheck(RewriteCheck::class);
	}

	public function boot(IBootContext $context): void {
	}
}
