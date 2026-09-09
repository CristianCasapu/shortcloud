<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\BackgroundJob;

use OCA\Shortcloud\Service\UpgradeWatch;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Last-resort safety net, once a day inside the maintenance window: the rule is
 * normally restored the moment an app or Nextcloud itself is updated (see
 * UpgradeWatch), so this only catches an administrator editing .htaccess by hand.
 */
class HealJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private UpgradeWatch $watch,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->watch->repair('the daily check');
	}
}
