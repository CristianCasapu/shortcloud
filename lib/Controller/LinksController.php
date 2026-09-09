<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Controller;

use OCA\Shortcloud\AppInfo\Application;
use OCA\Shortcloud\Db\Link;
use OCA\Shortcloud\Service\AlbumLinks;
use OCA\Shortcloud\Service\Config;
use OCA\Shortcloud\Service\LinkException;
use OCA\Shortcloud\Service\LinkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;

class LinksController extends OCSController {
	public function __construct(
		IRequest $request,
		private LinkService $links,
		private AlbumLinks $albums,
		private Config $config,
		private ShareManager $shareManager,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Domains, permissions and policies for the current user. */
	#[NoAdminRequired]
	public function config(): DataResponse {
		return new DataResponse($this->config->forUser($this->userId()));
	}

	/** The current user's links, or everyone's for an administrator asking for all. */
	#[NoAdminRequired]
	public function index(string $search = '', bool $all = false): DataResponse {
		$userId = $this->userId();
		$this->albums->trySync();
		$links = $all && $this->config->isAdmin($userId)
			? $this->links->listAll($search)
			: $this->links->listForUser($userId, $search);
		return new DataResponse(['links' => $links]);
	}

	/** The current user's short links for one of their shares. */
	#[NoAdminRequired]
	public function forShare(string $shareId): DataResponse {
		$share = $this->ownShare($shareId);
		return new DataResponse(['links' => $this->links->findForShare($share, $this->userId())]);
	}

	/**
	 * The short link of a public album link (Photos / Memories), created if missing.
	 * Only the album owner (or an administrator) may ask for it.
	 *
	 * @throws OCSNotFoundException
	 * @throws OCSForbiddenException
	 * @throws OCSBadRequestException
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function album(string $token, ?string $domain = null, ?string $slug = null): DataResponse {
		$userId = $this->userId();
		$album = $this->albums->findByToken($token);
		if ($album === null || ($album['owner'] !== $userId && !$this->config->isAdmin($userId))) {
			throw new OCSNotFoundException($this->l->t('Album link not found'));
		}
		$existing = $this->links->findForShare(AlbumLinks::PREFIX . $token, $album['owner']);
		if ($existing !== [] && self::blank($domain) === null && self::blank($slug) === null) {
			return new DataResponse($existing[0]);
		}
		if (!$this->config->canCreate($userId)) {
			throw new OCSForbiddenException($this->config->isPaused()
				? $this->l->t('Creating short links is paused by the administrator')
				: $this->l->t('You are not allowed to create short links'));
		}
		try {
			$link = $this->albums->forToken($token, $album['owner'], self::blank($domain), self::blank($slug));
		} catch (LinkException $e) {
			throw new OCSBadRequestException($this->l->t($e->getMessage()));
		}
		return new DataResponse($link, Http::STATUS_CREATED);
	}

	/**
	 * Creates a short link, either for one of the user's link shares (shareId) or for any target.
	 * For a share without slug/domain, the existing short link is returned instead of a second one.
	 *
	 * @throws OCSBadRequestException
	 * @throws OCSForbiddenException
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(?string $target = null, ?string $shareId = null, ?string $domain = null, ?string $slug = null, ?string $title = null): DataResponse {
		$userId = $this->userId();
		if (!$this->config->canCreate($userId)) {
			throw new OCSForbiddenException($this->config->isPaused()
				? $this->l->t('Creating short links is paused by the administrator')
				: $this->l->t('You are not allowed to create short links'));
		}
		try {
			if ($shareId !== null && $shareId !== '') {
				$share = $this->ownShare($shareId);
				$link = $this->links->createForShare($share, $userId, self::blank($domain), self::blank($slug));
			} else {
				$link = $this->links->create($userId, (string)$target, self::blank($domain), self::blank($slug), self::blank($title));
			}
		} catch (LinkException $e) {
			throw new OCSBadRequestException($this->l->t($e->getMessage()));
		}
		return new DataResponse($link, Http::STATUS_CREATED);
	}

	/**
	 * @throws OCSBadRequestException
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $slug = null, ?string $domain = null, ?string $target = null, ?string $title = null, ?string $status = null): DataResponse {
		$link = $this->ownLink($id);
		$changes = [];
		if ($slug !== null) {
			$changes['slug'] = $slug;
		}
		if ($domain !== null) {
			$changes['domain'] = $domain;
		}
		if ($target !== null) {
			$changes['target'] = $target;
		}
		if ($title !== null) {
			$changes['title'] = $title;
		}
		if ($status !== null) {
			$changes['status'] = $status;
		}
		try {
			$link = $this->links->update($link, $changes);
		} catch (LinkException $e) {
			throw new OCSBadRequestException($this->l->t($e->getMessage()));
		}
		return new DataResponse($link);
	}

	/**
	 * @throws OCSNotFoundException
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		$this->links->delete($this->ownLink($id));
		return new DataResponse([]);
	}

	// ---------------------------------------------------------------- helpers

	private function userId(): string {
		return (string)$this->config->getCurrentUserId();
	}

	/**
	 * @throws OCSNotFoundException
	 */
	private function ownLink(int $id): Link {
		try {
			$link = $this->links->get($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException($this->l->t('Short link not found'));
		}
		if ($link->getUserId() !== $this->userId() && !$this->config->isAdmin($this->userId())) {
			throw new OCSNotFoundException($this->l->t('Short link not found'));
		}
		return $link;
	}

	/**
	 * A link share the current user made (or owns the file of).
	 *
	 * @throws OCSNotFoundException
	 * @throws OCSBadRequestException
	 */
	private function ownShare(string $shareId): IShare {
		try {
			$share = $this->shareManager->getShareById(LinkService::normalizeShareId($shareId), $this->userId());
		} catch (ShareNotFound) {
			throw new OCSNotFoundException($this->l->t('Share not found'));
		}
		if ($share->getShareType() !== IShare::TYPE_LINK) {
			throw new OCSBadRequestException($this->l->t('Only public link shares can be shortened'));
		}
		$userId = $this->userId();
		if ($share->getSharedBy() !== $userId && $share->getShareOwner() !== $userId && !$this->config->isAdmin($userId)) {
			throw new OCSNotFoundException($this->l->t('Share not found'));
		}
		return $share;
	}

	private static function blank(?string $value): ?string {
		return $value === null || trim($value) === '' ? null : trim($value);
	}
}
