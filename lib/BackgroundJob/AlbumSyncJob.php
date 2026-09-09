<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\BackgroundJob;

use OCA\Shortcloud\Service\AlbumLinks;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Public album links (Photos / Memories) announce no event. The job asks the
 * database one cheap question ("did the album-link table change?") and only
 * does work when the answer is yes.
 */
class AlbumSyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AlbumLinks $albums,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		// time sensitive: insensitive jobs only run inside the maintenance window
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->albums->trySync(onlyIfChanged: true);
	}
}
