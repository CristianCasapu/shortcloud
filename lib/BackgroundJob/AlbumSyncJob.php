<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\BackgroundJob;

use OCA\Shortcloud\Service\AlbumLinks;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Public album links (Photos / Memories) announce no event, so they are picked up every few minutes. */
class AlbumSyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AlbumLinks $albums,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->albums->trySync();
	}
}
