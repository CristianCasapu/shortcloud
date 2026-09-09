<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

use OCA\Shortcloud\Db\Link;
use OCA\Shortcloud\Db\LinkMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Public album links of the Photos app (also used by Memories) are not
 * Nextcloud shares: they are "link collaborators" in photos_albums_collabs and
 * no event announces them. This service finds them and gives each one a short
 * link, keyed as share id "album:<token>" so the redirect can notice when the
 * album link is removed.
 */
class AlbumLinks {
	public const PREFIX = 'album:';
	private const TYPE_LINK = 3;

	private ?bool $available = null;

	public function __construct(
		private IDBConnection $db,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private LinkMapper $mapper,
		private LinkService $links,
		private Config $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		if ($this->available === null) {
			try {
				$this->available = $this->appManager->isEnabledForUser('photos')
					&& $this->db->tableExists('photos_albums')
					&& $this->db->tableExists('photos_albums_collabs');
			} catch (\Throwable) {
				$this->available = false;
			}
		}
		return $this->available;
	}

	/**
	 * Every album that has a public link.
	 *
	 * @return array<string, array{token: string, albumId: int, name: string, owner: string}> keyed by token
	 */
	public function listLinkAlbums(): array {
		if (!$this->isAvailable()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('pa.album_id', 'pa.name', 'pa.user', 'pc.collaborator_id')
			->from('photos_albums', 'pa')
			->innerJoin('pa', 'photos_albums_collabs', 'pc', $qb->expr()->andX(
				$qb->expr()->eq('pc.album_id', 'pa.album_id'),
				$qb->expr()->eq('pc.collaborator_type', $qb->createNamedParameter(self::TYPE_LINK, IQueryBuilder::PARAM_INT)),
			));
		$result = $qb->executeQuery();
		$albums = [];
		while ($row = $result->fetch()) {
			$token = (string)$row['collaborator_id'];
			if ($token === '') {
				continue;
			}
			$albums[$token] = [
				'token' => $token,
				'albumId' => (int)$row['album_id'],
				'name' => (string)$row['name'],
				'owner' => (string)$row['user'],
			];
		}
		$result->closeCursor();
		return $albums;
	}

	/** @return array{token: string, albumId: int, name: string, owner: string}|null */
	public function findByToken(string $token): ?array {
		if (!$this->isAvailable() || $token === '') {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('pa.album_id', 'pa.name', 'pa.user', 'pc.collaborator_id')
			->from('photos_albums', 'pa')
			->innerJoin('pa', 'photos_albums_collabs', 'pc', $qb->expr()->andX(
				$qb->expr()->eq('pc.album_id', 'pa.album_id'),
				$qb->expr()->eq('pc.collaborator_type', $qb->createNamedParameter(self::TYPE_LINK, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('pc.collaborator_id', $qb->createNamedParameter($token)),
			))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if (!$row) {
			return null;
		}
		return ['token' => $token, 'albumId' => (int)$row['album_id'], 'name' => (string)$row['name'], 'owner' => (string)$row['user']];
	}

	public function tokenExists(string $token): bool {
		return $this->findByToken($token) !== null;
	}

	/** The album page as it should be linked right now; null when the album link is gone. */
	public function currentTarget(string $token): ?string {
		return $this->tokenExists($token) ? $this->targetFor($token) : null;
	}

	/** The public album page: Memories when it is installed, the Photos app otherwise. */
	public function targetFor(string $token): string {
		// The router answers "" for a route of an app that is not loaded in this request
		// (e.g. inside go.php), so every result is checked for the token before use.
		if ($this->appManager->isEnabledForUser('memories')) {
			return $this->route('memories.PublicAlbum.showShare', $token) ?? $this->fallback('/apps/memories/a/' . $token);
		}
		return $this->route('photos.publicAlbum.get', $token) ?? $this->fallback('/apps/photos/public/' . $token);
	}

	private function route(string $name, string $token): ?string {
		try {
			$url = $this->urlGenerator->linkToRouteAbsolute($name, ['token' => $token]);
		} catch (\Throwable) {
			return null;
		}
		return str_contains($url, $token) ? $url : null;
	}

	private function fallback(string $path): string {
		$pretty = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueBool('htaccess.IgnoreFrontController', false)
			|| getenv('front_controller_active') === 'true';
		return $this->urlGenerator->getAbsoluteURL(($pretty ? '' : '/index.php') . $path);
	}

	/** The short link of an album link, created for its owner if there is none yet. */
	public function forToken(string $token, string $userId, ?string $domain = null, ?string $slug = null): Link {
		$album = $this->findByToken($token);
		if ($album === null) {
			throw new LinkException('This album link does not exist', 'album');
		}
		$existing = $this->links->findForShare(self::PREFIX . $token, $userId);
		if ($existing !== [] && $domain === null && $slug === null) {
			return $existing[0];
		}
		return $this->links->create($userId, $this->targetFor($token), $domain, $slug, $album['name'], self::PREFIX . $token);
	}

	/**
	 * Gives every public album link a short link and marks the links of removed album links as gone.
	 *
	 * @return array{created: int, gone: int}
	 */
	public function sync(bool $create = true): array {
		$stats = ['created' => 0, 'gone' => 0];
		if (!$this->isAvailable()) {
			return $stats;
		}
		$albums = $this->listLinkAlbums();
		$known = [];
		foreach ($this->mapper->findByShareIdPrefix(self::PREFIX) as $link) {
			$token = substr((string)$link->getShareId(), strlen(self::PREFIX));
			$known[$token] = true;
			if (!isset($albums[$token]) && $link->getStatus() !== Link::STATUS_GONE) {
				$stats['gone'] += $this->mapper->markShareGone((string)$link->getShareId(), $this->time->getTime());
			}
		}
		if (!$create || !$this->config->autoCreate()) {
			return $stats;
		}
		foreach ($albums as $token => $album) {
			if (isset($known[$token]) || !$this->config->canCreate($album['owner'])) {
				continue;
			}
			try {
				$this->links->create($album['owner'], $this->targetFor($token), null, null, $album['name'], self::PREFIX . $token);
				$stats['created']++;
			} catch (\Throwable $e) {
				$this->logger->warning('Shortcloud could not shorten album link ' . $token . ': ' . $e->getMessage(), ['app' => 'shortcloud']);
			}
		}
		return $stats;
	}

	/** Runs sync() without ever failing the caller. */
	public function trySync(): void {
		try {
			$this->sync();
		} catch (\Throwable $e) {
			$this->logger->debug('Shortcloud album sync failed: ' . $e->getMessage(), ['app' => 'shortcloud']);
		}
	}
}
