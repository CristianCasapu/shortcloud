<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Controller;

use OCA\Shortcloud\AppInfo\Application;
use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\Htaccess;
use OCA\Shortcloud\Service\PrettyUrls;
use OCA\Shortcloud\Service\Probe;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;

/** Administrator-only endpoints (OCS controllers require admin unless told otherwise). */
class AdminController extends OCSController {
	public function __construct(
		IRequest $request,
		private Config $config,
		private Htaccess $htaccess,
		private PrettyUrls $prettyUrls,
		private Probe $probe,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function getSettings(): DataResponse {
		return new DataResponse($this->payload());
	}

	/**
	 * @throws OCSBadRequestException
	 */
	public function setSettings(array $settings): DataResponse {
		$prefixBefore = $this->config->getPrefix();
		try {
			$this->config->update($settings);
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($this->l->t($e->getMessage()));
		}
		// a new prefix needs a new rewrite rule
		if ($this->config->getPrefix() !== $prefixBefore && $this->config->manageHtaccess() && $this->htaccess->isWritable()) {
			try {
				$this->htaccess->install();
			} catch (\RuntimeException) {
				// reported through the status below
			}
		}
		return new DataResponse($this->payload());
	}

	public function rewriteStatus(bool $fresh = false): DataResponse {
		if ($fresh) {
			$this->probe->works(true);
		}
		return new DataResponse($this->rewrite());
	}

	/**
	 * @throws OCSBadRequestException
	 */
	public function installRewrite(): DataResponse {
		try {
			$this->htaccess->install();
			$this->probe->works(true);
		} catch (\RuntimeException $e) {
			throw new OCSBadRequestException($this->l->t($e->getMessage()));
		}
		$this->config->update(['manageHtaccess' => true]);
		return new DataResponse($this->payload());
	}

	/**
	 * @throws OCSBadRequestException
	 */
	public function removeRewrite(): DataResponse {
		try {
			$this->htaccess->remove();
		} catch (\RuntimeException $e) {
			throw new OCSBadRequestException($this->l->t($e->getMessage()));
		}
		$this->config->update(['manageHtaccess' => false]);
		return new DataResponse($this->payload());
	}

	/**
	 * Switches Nextcloud's pretty URLs (no "/index.php" in addresses) on or off.
	 *
	 * @throws OCSBadRequestException
	 */
	public function setPrettyUrls(bool $enabled): DataResponse {
		try {
			$enabled ? $this->prettyUrls->enable() : $this->prettyUrls->disable();
		} catch (\RuntimeException $e) {
			throw new OCSBadRequestException($this->l->t($e->getMessage()));
		}
		return new DataResponse($this->payload());
	}

	private function payload(): array {
		return ['settings' => $this->config->all(), 'rewrite' => $this->rewrite(), 'prettyUrls' => $this->prettyUrls->state()];
	}

	private function rewrite(): array {
		$domains = [];
		foreach ($this->config->getCustomDomains() as $d) {
			$domains[] = $d + [
				'pingUrl' => $this->config->getPingUrl($d['host']),
				'vhost' => $this->htaccess->vhostSnippet($d['host'], $d['prefix'], \OC::$WEBROOT),
			];
		}
		return [
			'status' => $this->htaccess->status(),
			'probe' => $this->probe->works(),
			'writable' => $this->htaccess->isWritable(),
			'path' => $this->htaccess->path(),
			'block' => $this->htaccess->block(),
			'nginx' => $this->htaccess->nginxSnippet(),
			'pingUrl' => $this->config->getPingUrl(),
			'exampleUrl' => $this->config->buildShortUrl($this->config->getDefaultHost(), 'abc1234'),
			'customDomains' => $domains,
		];
	}
}
