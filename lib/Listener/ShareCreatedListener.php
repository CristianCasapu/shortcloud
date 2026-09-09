<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Listener;

use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\LinkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Gives every new public link share a short link, whatever client created it.
 *
 * @template-implements IEventListener<ShareCreatedEvent>
 */
class ShareCreatedListener implements IEventListener {
	public function __construct(
		private Config $config,
		private LinkService $links,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ShareCreatedEvent) {
			return;
		}
		$share = $event->getShare();
		if ($share->getShareType() !== IShare::TYPE_LINK || !$this->config->autoCreate()) {
			return;
		}
		$userId = $share->getSharedBy();
		if (!$this->config->canCreate($userId)) {
			return;
		}
		try {
			$this->links->createForShare($share, $userId);
		} catch (\Throwable $e) {
			// a short link is a convenience: never let it break the share itself
			$this->logger->warning('Shortcloud could not create a short link for share ' . $share->getFullId() . ': ' . $e->getMessage(), [
				'app' => 'shortcloud',
				'exception' => $e,
			]);
		}
	}
}
