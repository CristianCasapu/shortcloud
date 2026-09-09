<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

use OCA\Shortcloud\Db\Link;
use OCA\Shortcloud\Db\LinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as ShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class LinkService {
	/** Lower case letters and digits without the ones that look alike (0/o, 1/l/i). */
	public const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

	public const SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

	/** Paths that must never become a slug on a domain without prefix, plus the app's own probe. */
	public const RESERVED = [
		'_ping', 'index.php', 'apps', 'apps-extra', 'core', 'dist', 's', 'f', 'u', 'call', 'login', 'logout',
		'ocs', 'ocs-provider', 'ocm-provider', 'remote.php', 'public.php', 'status.php', 'cron.php', 'settings',
		'avatar', 'admin', 'api', 'themes', 'img', 'js', 'css', 'favicon.ico', 'robots.txt', 'go',
	];

	public const MAX_TARGET_LENGTH = 2000;

	public function __construct(
		private LinkMapper $mapper,
		private Config $config,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private IURLGenerator $urlGenerator,
		private IConfig $systemConfig,
		private ShareManager $shareManager,
		private LoggerInterface $logger,
	) {
	}

	// ---------------------------------------------------------------- reading

	/** @return Link[] */
	public function listForUser(string $userId, string $search = ''): array {
		return $this->decorate($this->mapper->findByUser($userId, $search));
	}

	/** @return Link[] */
	public function listAll(string $search = ''): array {
		return $this->decorate($this->mapper->findAll($search));
	}

	/** @return Link[] */
	public function findForShare(IShare|string $share, ?string $userId = null): array {
		$shareId = $share instanceof IShare ? $share->getFullId() : self::normalizeShareId($share);
		$links = $this->mapper->findByShare($shareId);
		if ($userId !== null) {
			$links = array_values(array_filter($links, static fn (Link $l) => $l->getUserId() === $userId));
		}
		return $this->decorate($links);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function get(int $id): Link {
		return $this->decorate([$this->mapper->findById($id)])[0];
	}

	// ---------------------------------------------------------------- writing

	/**
	 * Creates a short link for an arbitrary target.
	 *
	 * @throws LinkException on a bad slug, domain or target
	 */
	public function create(string $userId, string $target, ?string $domain = null, ?string $slug = null, ?string $title = null, ?string $shareId = null, bool $trusted = false): Link {
		$domain = $this->checkDomain($domain);
		$target = $this->checkTarget($target, $trusted || $shareId !== null);
		$link = new Link();
		$link->setUserId($userId);
		$link->setDomain($domain);
		$link->setTarget($target);
		$link->setShareId($shareId);
		$link->setTitle($title !== null ? mb_substr($title, 0, 255) : null);
		$link->setStatus(Link::STATUS_ACTIVE);
		$now = $this->time->getTime();
		$link->setCreatedAt($now);
		$link->setUpdatedAt($now);

		if ($slug !== null && $slug !== '') {
			$link->setSlug($this->checkSlug($slug, $domain));
			try {
				$link = $this->mapper->insert($link);
			} catch (DBException $e) {
				if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw new LinkException('This short link is already taken', 'slug_taken');
				}
				throw $e;
			}
			return $this->decorate([$link])[0];
		}

		$length = $this->config->getSlugLength();
		for ($attempt = 0; $attempt < 12; $attempt++) {
			$link->setSlug($this->randomSlug($length + intdiv($attempt, 4)));
			try {
				$link = $this->mapper->insert($link);
				return $this->decorate([$link])[0];
			} catch (DBException $e) {
				if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}
		throw new LinkException('Could not find a free short link, please try again', 'no_slug');
	}

	/**
	 * Creates the short link of a link share, or returns the one that exists.
	 */
	public function createForShare(IShare $share, string $userId, ?string $domain = null, ?string $slug = null): Link {
		$existing = $this->findForShare($share, $userId);
		if ($existing !== [] && $domain === null && $slug === null) {
			return $existing[0];
		}
		$title = null;
		try {
			$title = $share->getNode()->getName();
		} catch (\Throwable) {
			// the node may be gone or unreachable; the title is only cosmetic
		}
		return $this->create($userId, $this->shareUrl($share->getToken()), $domain, $slug, $title, $share->getFullId());
	}

	/**
	 * @param array{slug?: string|null, domain?: string|null, target?: string|null, title?: string|null, status?: string|null} $changes
	 * @throws LinkException
	 */
	public function update(Link $link, array $changes): Link {
		$domain = array_key_exists('domain', $changes) && $changes['domain'] !== null && $changes['domain'] !== ''
			? $this->checkDomain((string)$changes['domain'])
			: $link->getDomain();
		$slug = array_key_exists('slug', $changes) && $changes['slug'] !== null && $changes['slug'] !== ''
			? $this->checkSlug((string)$changes['slug'], $domain)
			: $link->getSlug();
		if ($domain !== $link->getDomain() || $slug !== $link->getSlug()) {
			if ($this->mapper->slugExists($domain, $slug)) {
				throw new LinkException('This short link is already taken', 'slug_taken');
			}
			$link->setDomain($domain);
			$link->setSlug($slug);
		}
		if (array_key_exists('target', $changes) && $changes['target'] !== null && $changes['target'] !== '' && $changes['target'] !== $link->getTarget()) {
			if ($link->getShareId() !== null) {
				throw new LinkException('The target of a share link follows the share and cannot be edited', 'share_target');
			}
			$link->setTarget($this->checkTarget((string)$changes['target'], false));
		}
		if (array_key_exists('title', $changes)) {
			$title = $changes['title'] !== null ? trim((string)$changes['title']) : '';
			$link->setTitle($title === '' ? null : mb_substr($title, 0, 255));
		}
		if (array_key_exists('status', $changes) && $changes['status'] !== null) {
			$status = (string)$changes['status'];
			if (!in_array($status, [Link::STATUS_ACTIVE, Link::STATUS_PAUSED], true)) {
				throw new LinkException('A link can only be active or paused', 'status');
			}
			if ($link->getStatus() === Link::STATUS_GONE && $status === Link::STATUS_ACTIVE && $link->getShareId() !== null) {
				throw new LinkException('The share behind this link was deleted', 'gone');
			}
			$link->setStatus($status);
		}
		$link->setUpdatedAt($this->time->getTime());
		try {
			$link = $this->mapper->update($link);
		} catch (DBException $e) {
			if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new LinkException('This short link is already taken', 'slug_taken');
			}
			throw $e;
		}
		return $this->decorate([$link])[0];
	}

	public function delete(Link $link): void {
		$this->mapper->delete($link);
	}

	public function markShareGone(string $shareId): int {
		return $this->mapper->markShareGone($shareId, $this->time->getTime());
	}

	// ---------------------------------------------------------------- resolving

	/**
	 * Resolves a visit. Returns the target URL for an active link, or one of the
	 * statuses "gone" / "paused" / "missing".
	 *
	 * @return array{status: string, target: ?string, link: ?Link}
	 */
	public function resolve(string $host, string $slug): array {
		$domain = $this->config->resolveRequestHost($host);
		try {
			$link = $this->mapper->findBySlug($domain, $slug);
		} catch (DoesNotExistException) {
			return ['status' => 'missing', 'target' => null, 'link' => null];
		}
		if ($link->getStatus() === Link::STATUS_PAUSED) {
			return ['status' => 'paused', 'target' => null, 'link' => $link];
		}
		if ($link->getStatus() === Link::STATUS_GONE) {
			return ['status' => 'gone', 'target' => null, 'link' => $link];
		}
		$target = $link->getTarget();
		if ($link->getShareId() !== null && str_starts_with((string)$link->getShareId(), 'album:')) {
			$current = $this->albumTarget(substr((string)$link->getShareId(), 6));
			if ($current === false) {
				$link->setStatus(Link::STATUS_GONE);
				$link->setUpdatedAt($this->time->getTime());
				$this->mapper->update($link);
				return ['status' => 'gone', 'target' => null, 'link' => $link];
			}
			if ($current !== null && $current !== $target) {
				$link->setTarget($current);
				$link->setUpdatedAt($this->time->getTime());
				$this->mapper->update($link);
				$target = $current;
			}
		} elseif ($link->getShareId() !== null) {
			$current = $this->currentShareUrl($link);
			if ($current === false) {
				$link->setStatus(Link::STATUS_GONE);
				$link->setUpdatedAt($this->time->getTime());
				$this->mapper->update($link);
				return ['status' => 'gone', 'target' => null, 'link' => $link];
			}
			if ($current !== null && $current !== $target) {
				// the share token was customised after the short link was made: follow it
				$link->setTarget($current);
				$link->setUpdatedAt($this->time->getTime());
				$this->mapper->update($link);
				$target = $current;
			}
		}
		$this->mapper->recordHit($link->getId(), $this->time->getTime());
		return ['status' => 'active', 'target' => $target, 'link' => $link];
	}

	/**
	 * The share URL as it is right now; null when it cannot be determined, false when the share is gone.
	 */
	private function currentShareUrl(Link $link): string|false|null {
		try {
			$share = $this->shareManager->getShareById((string)$link->getShareId());
		} catch (ShareNotFound) {
			return false;
		} catch (\Throwable $e) {
			$this->logger->debug('Shortcloud could not look up share ' . $link->getShareId() . ': ' . $e->getMessage(), ['app' => 'shortcloud']);
			return null;
		}
		$token = $share->getToken();
		if ($token === null || $token === '') {
			return null;
		}
		return $this->shareUrl($token);
	}

	/**
	 * Album links live in the Photos tables; the lookup is delegated to avoid a circular dependency.
	 * Returns the current album URL, false when the album link is gone, null when unknown.
	 */
	private function albumTarget(string $token): string|false|null {
		try {
			return \OCP\Server::get(AlbumLinks::class)->currentTarget($token) ?? false;
		} catch (\Throwable) {
			return null;
		}
	}

	public function shareUrl(string $token): string {
		try {
			$url = $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $token]);
			if (str_contains($url, $token)) {
				return $url;
			}
		} catch (\Throwable) {
			// fall through: files_sharing routes unavailable in this context
		}
		$pretty = $this->systemConfig->getSystemValueBool('htaccess.IgnoreFrontController', false) || getenv('front_controller_active') === 'true';
		return $this->urlGenerator->getAbsoluteURL(($pretty ? '' : '/index.php') . '/s/' . $token);
	}

	// ---------------------------------------------------------------- validation

	/** @throws LinkException */
	public function checkSlug(string $slug, string $domain): string {
		$slug = trim($slug, "/ \t");
		if (!preg_match(self::SLUG_PATTERN, $slug)) {
			throw new LinkException('A short link may only contain letters, digits, "-" and "_" (1 to 64 characters)', 'slug_invalid');
		}
		$lower = strtolower($slug);
		if (in_array($lower, self::RESERVED, true) || in_array($lower, array_map('strtolower', $this->config->getReservedSlugs()), true)) {
			throw new LinkException('This name is reserved', 'slug_reserved');
		}
		return $slug;
	}

	/** @throws LinkException */
	public function checkDomain(?string $host): string {
		if ($host === null || trim($host) === '') {
			return $this->config->getDefaultHost();
		}
		$domain = $this->config->getDomain($host);
		if ($domain === null) {
			throw new LinkException('This domain is not available for short links', 'domain');
		}
		return $domain['host'];
	}

	/** @throws LinkException */
	public function checkTarget(string $target, bool $internal): string {
		$target = trim($target);
		if ($target === '' || strlen($target) > self::MAX_TARGET_LENGTH) {
			throw new LinkException('The target address is missing or too long', 'target');
		}
		if (preg_match('/[\x00-\x20\x7f]/', $target)) {
			throw new LinkException('The target address contains invalid characters', 'target');
		}
		$parts = parse_url($target);
		if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			throw new LinkException('The target must be a complete http(s) address', 'target');
		}
		$host = Config::normalizeHost($parts['host']);
		if ($this->isShortUrl($host, $parts['path'] ?? '')) {
			throw new LinkException('A short link cannot point to another short link', 'target_loop');
		}
		if ($internal || $this->isOwnHost($host)) {
			return $target;
		}
		switch ($this->config->getExternalTargets()) {
			case Config::EXTERNAL_ANY:
				return $target;
			case Config::EXTERNAL_LIST:
				foreach ($this->config->getAllowedHosts() as $allowed) {
					if ($host === $allowed || str_ends_with($host, '.' . ltrim($allowed, '.'))) {
						return $target;
					}
				}
				throw new LinkException('Short links to this site are not allowed', 'target_external');
			default:
				throw new LinkException('Short links may only point to this Nextcloud', 'target_external');
		}
	}

	private function isOwnHost(string $host): bool {
		if ($host === $this->config->getDefaultHost()) {
			return true;
		}
		foreach ((array)$this->systemConfig->getSystemValue('trusted_domains', []) as $trusted) {
			$trusted = Config::normalizeHost((string)$trusted);
			if ($trusted !== '' && ($trusted === $host || fnmatch($trusted, $host))) {
				return true;
			}
		}
		return false;
	}

	private function isShortUrl(string $host, string $path): bool {
		foreach ($this->config->getDomains() as $domain) {
			if ($domain['host'] !== $host) {
				continue;
			}
			if ($domain['prefix'] === '') {
				return true;
			}
			if (str_starts_with($path, '/' . $domain['prefix'] . '/')) {
				return true;
			}
		}
		return false;
	}

	private function randomSlug(int $length): string {
		return $this->random->generate($length, self::ALPHABET);
	}

	/** @param Link[] $links @return Link[] */
	private function decorate(array $links): array {
		foreach ($links as $link) {
			$link->shortUrl = $this->config->buildShortUrl($link->getDomain(), $link->getSlug());
		}
		return $links;
	}

	public static function normalizeShareId(string $shareId): string {
		return str_contains($shareId, ':') ? $shareId : 'ocinternal:' . $shareId;
	}
}
