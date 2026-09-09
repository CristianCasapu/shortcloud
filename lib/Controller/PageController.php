<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Controller;

use OCA\Shortcloud\AppInfo\Application;
use OCA\Shortcloud\Service\AlbumLinks;
use OCA\Shortcloud\Service\Config;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private Config $config,
		private AlbumLinks $albums,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->albums->trySync();
		$this->initialState->provideInitialState('config', $this->config->forUser($this->config->getCurrentUserId()));
		Util::addScript(Application::APP_ID, 'shortcloud-main');
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}
