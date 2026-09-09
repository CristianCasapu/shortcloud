<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\SetupCheck;

use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\Htaccess;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/** Shows up in Administration › Overview when the short-link rule is missing. */
class RewriteCheck implements ISetupCheck {
	public function __construct(
		private IL10N $l,
		private Htaccess $htaccess,
		private Config $config,
	) {
	}

	public function getCategory(): string {
		return 'config';
	}

	public function getName(): string {
		return $this->l->t('Shortcloud short links');
	}

	public function run(): SetupResult {
		$status = $this->htaccess->status();
		if ($status === Htaccess::STATUS_OK) {
			return SetupResult::success($this->l->t('The rewrite rule for short links (%s) is in place.', [$this->config->buildShortUrl($this->config->getDefaultHost(), '…')]));
		}
		if ($status === Htaccess::STATUS_UNAVAILABLE) {
			return SetupResult::info($this->l->t('Short links need a rewrite rule in the web server configuration; .htaccess cannot be used here. See the Shortcloud administration settings for the snippet.'));
		}
		return SetupResult::warning($this->l->t('The rewrite rule for short links is missing from .htaccess, so short links answer 404. Open Administration settings › Shortcloud and click "Install rewrite rule".'));
	}
}
