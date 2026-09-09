<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Controller;

use OCA\Shortcloud\AppInfo\Application;
use OCA\Shortcloud\Service\LinkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class RedirectController extends Controller {
	public function __construct(
		IRequest $request,
		private LinkService $links,
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		private IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Follows a short link. Reached as /go/{slug} through the rewrite rule
	 * (see go.php) or directly as /index.php/apps/shortcloud/go/{slug}.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'shortcloud')]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function go(string $slug): Response {
		$slug = trim($slug, "/ \t");
		if ($slug === '_ping') {
			$response = new DataResponse([
				'shortcloud' => true,
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
			]);
			// the administration page probes custom domains from the instance origin
			$response->addHeader('Access-Control-Allow-Origin', '*');
			$response->addHeader('X-Shortcloud', 'ping');
			return $response;
		}
		if (!preg_match(LinkService::SLUG_PATTERN, $slug)) {
			return $this->page('notfound', Http::STATUS_NOT_FOUND, true);
		}
		$result = $this->links->resolve($this->request->getInsecureServerHost(), $slug);
		switch ($result['status']) {
			case 'active':
				$response = new RedirectResponse((string)$result['target'], Http::STATUS_FOUND);
				$response->addHeader('Referrer-Policy', 'no-referrer');
				return $response;
			case 'gone':
				return $this->page('gone', Http::STATUS_GONE, false);
			case 'paused':
				return $this->page('paused', Http::STATUS_NOT_FOUND, false);
			default:
				return $this->page('notfound', Http::STATUS_NOT_FOUND, true);
		}
	}

	private function page(string $kind, int $status, bool $throttle): TemplateResponse {
		$texts = [
			'notfound' => [$this->l->t('This short link does not exist'), $this->l->t('Check the address for typing mistakes, or ask the person who sent it for a new link.')],
			'gone' => [$this->l->t('This link has expired'), $this->l->t('The share behind this short link was removed or has expired.')],
			'paused' => [$this->l->t('This link is paused'), $this->l->t('The owner has switched this short link off for now.')],
		];
		[$title, $message] = $texts[$kind];
		$response = new TemplateResponse(Application::APP_ID, 'message', [
			'title' => $title,
			'message' => $message,
			'home' => $this->urlGenerator->getAbsoluteURL('/'),
		], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus($status);
		if ($throttle) {
			$response->throttle(['action' => 'shortcloud']);
		}
		return $response;
	}
}
