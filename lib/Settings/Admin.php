<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Settings;

use OCA\Shortcloud\AppInfo\Application;
use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\Htaccess;
use OCA\Shortcloud\Service\PrettyUrls;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private Config $config,
		private Htaccess $htaccess,
		private PrettyUrls $prettyUrls,
		private IGroupManager $groupManager,
	) {
	}

	public function getForm(): TemplateResponse {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = ['id' => $group->getGID(), 'name' => $group->getDisplayName()];
		}
		$domains = [];
		foreach ($this->config->getCustomDomains() as $d) {
			$domains[] = $d + [
				'pingUrl' => $this->config->getPingUrl($d['host']),
				'vhost' => $this->htaccess->vhostSnippet($d['host'], $d['prefix'], \OC::$WEBROOT),
			];
		}
		$this->initialState->provideInitialState('admin', [
			'settings' => $this->config->all(),
			'groups' => $groups,
			'prettyUrls' => $this->prettyUrls->state(),
			'rewrite' => [
				'status' => $this->htaccess->status(),
				'writable' => $this->htaccess->isWritable(),
				'path' => $this->htaccess->path(),
				'block' => $this->htaccess->block(),
				'nginx' => $this->htaccess->nginxSnippet(),
				'pingUrl' => $this->config->getPingUrl(),
				'exampleUrl' => $this->config->buildShortUrl($this->config->getDefaultHost(), 'abc1234'),
				'customDomains' => $domains,
			],
		]);
		Util::addScript(Application::APP_ID, 'shortcloud-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
