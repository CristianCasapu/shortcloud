<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

use OCP\IConfig;

/**
 * Nextcloud's own "pretty URL" mode: with htaccess.RewriteBase set, the generated
 * tail of .htaccess sends every unknown path to index.php and Nextcloud stops
 * writing "/index.php" into the links it produces. This wraps what
 * "occ maintenance:update:htaccess" does so it can be switched from the settings.
 */
class PrettyUrls {
	public function __construct(
		private IConfig $config,
		private Htaccess $htaccess,
	) {
	}

	public function isConfigured(): bool {
		return $this->config->getSystemValueString('htaccess.RewriteBase', '') !== '';
	}

	/** Whether the generated .htaccess tail actually carries the front-controller rules. */
	public function isActiveInHtaccess(): bool {
		$path = $this->htaccess->path();
		return is_readable($path) && str_contains((string)file_get_contents($path), 'front_controller_active');
	}

	public function isSupported(): bool {
		return $this->htaccess->isWritable() && class_exists(\OC\Setup::class) && method_exists(\OC\Setup::class, 'updateHtaccess');
	}

	public function rewriteBase(): string {
		$webroot = \OC::$WEBROOT;
		return $webroot === '' ? '/' : $webroot;
	}

	/**
	 * @throws \RuntimeException
	 */
	public function enable(): void {
		if (!$this->isSupported()) {
			throw new \RuntimeException('.htaccess is not writable, or this Nextcloud cannot regenerate it.');
		}
		if ($this->config->getSystemValueString('overwrite.cli.url', '') === '') {
			throw new \RuntimeException('Set overwrite.cli.url in config.php first (Nextcloud needs it to build pretty URLs).');
		}
		$this->config->setSystemValue('htaccess.RewriteBase', $this->rewriteBase());
		// also drop /index.php from links made on the command line and by cron (mails, background jobs)
		$this->config->setSystemValue('htaccess.IgnoreFrontController', true);
		$this->regenerate();
	}

	/**
	 * @throws \RuntimeException
	 */
	public function disable(): void {
		if (!$this->isSupported()) {
			throw new \RuntimeException('.htaccess is not writable, or this Nextcloud cannot regenerate it.');
		}
		$this->config->deleteSystemValue('htaccess.RewriteBase');
		$this->config->deleteSystemValue('htaccess.IgnoreFrontController');
		$this->regenerate();
	}

	/** Regenerates the Nextcloud tail of .htaccess and puts the short-link block back. */
	private function regenerate(): void {
		$hadBlock = $this->htaccess->status() !== Htaccess::STATUS_MISSING && $this->htaccess->status() !== Htaccess::STATUS_UNAVAILABLE;
		if (!\OC\Setup::updateHtaccess()) {
			throw new \RuntimeException('Nextcloud could not update .htaccess (is it writable, and is overwrite.cli.url set?).');
		}
		if ($hadBlock) {
			$this->htaccess->install();
		}
	}

	public function state(): array {
		return [
			'configured' => $this->isConfigured(),
			'active' => $this->isActiveInHtaccess(),
			'supported' => $this->isSupported(),
			'rewriteBase' => $this->rewriteBase(),
		];
	}
}
