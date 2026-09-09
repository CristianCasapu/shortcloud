<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Listener;

use OCA\Shortcloud\Service\LinkService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;

/**
 * A deleted share leaves its short links in place, answering "gone" instead of redirecting.
 *
 * @template-implements IEventListener<ShareDeletedEvent>
 */
class ShareDeletedListener implements IEventListener {
	public function __construct(
		private LinkService $links,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ShareDeletedEvent) {
			return;
		}
		$share = $event->getShare();
		if ($share->getShareType() !== IShare::TYPE_LINK) {
			return;
		}
		try {
			$this->links->markShareGone($share->getFullId());
		} catch (\Throwable) {
			// the redirect itself notices a missing share, so nothing is lost
		}
	}
}
