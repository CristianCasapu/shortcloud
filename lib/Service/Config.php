<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

use OCA\Shortcloud\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Application settings and the list of short domains.
 *
 * Never use "enabled" as a key here: it is Nextcloud's own app-enabled flag.
 */
class Config {
	public const EXTERNAL_NONE = 'none';
	public const EXTERNAL_LIST = 'list';
	public const EXTERNAL_ANY = 'any';

	public const DEFAULT_PREFIX = 'go';
	public const DEFAULT_SLUG_LENGTH = 7;

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IRequest $request,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
	}

	public function isPaused(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'paused', '0') === '1';
	}

	public function autoCreate(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'auto_create', '1') === '1';
	}

	public function manageHtaccess(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'manage_htaccess', '0') === '1';
	}

	public function getPrefix(): string {
		$prefix = trim($this->appConfig->getValueString(Application::APP_ID, 'prefix', self::DEFAULT_PREFIX), "/ \t");
		return $prefix === '' ? self::DEFAULT_PREFIX : $prefix;
	}

	public function getSlugLength(): int {
		$n = (int)$this->appConfig->getValueString(Application::APP_ID, 'slug_length', (string)self::DEFAULT_SLUG_LENGTH);
		return max(4, min(32, $n ?: self::DEFAULT_SLUG_LENGTH));
	}

	public function getExternalTargets(): string {
		$v = $this->appConfig->getValueString(Application::APP_ID, 'external_targets', self::EXTERNAL_NONE);
		return in_array($v, [self::EXTERNAL_NONE, self::EXTERNAL_LIST, self::EXTERNAL_ANY], true) ? $v : self::EXTERNAL_NONE;
	}

	/** @return string[] */
	public function getAllowedHosts(): array {
		return $this->getList('allowed_hosts');
	}

	/** @return string[] */
	public function getCreatorGroups(): array {
		return $this->getList('creator_groups');
	}

	/** @return string[] */
	public function getReservedSlugs(): array {
		return $this->getList('reserved');
	}

	/**
	 * Custom short domains, as configured by the administrator.
	 *
	 * @return list<array{host: string, prefix: string, scheme: string}>
	 */
	public function getCustomDomains(): array {
		$raw = json_decode($this->appConfig->getValueString(Application::APP_ID, 'domains', '[]'), true);
		$out = [];
		if (!is_array($raw)) {
			return $out;
		}
		foreach ($raw as $entry) {
			if (!is_array($entry) || !isset($entry['host'])) {
				continue;
			}
			$host = self::normalizeHost((string)$entry['host']);
			if ($host === '' || $host === $this->getDefaultHost()) {
				continue;
			}
			$out[] = [
				'host' => $host,
				'prefix' => trim((string)($entry['prefix'] ?? ''), "/ \t"),
				'scheme' => ($entry['scheme'] ?? 'https') === 'http' ? 'http' : 'https',
			];
		}
		return $out;
	}

	/**
	 * Every domain a short link can live on; the first one is the instance itself.
	 *
	 * @return list<array{host: string, prefix: string, scheme: string, default: bool}>
	 */
	public function getDomains(): array {
		$domains = [[
			'host' => $this->getDefaultHost(),
			'prefix' => $this->getPrefix(),
			'scheme' => $this->getDefaultScheme(),
			'default' => true,
		]];
		foreach ($this->getCustomDomains() as $d) {
			$d['default'] = false;
			$domains[] = $d;
		}
		return $domains;
	}

	/** @return array{host: string, prefix: string, scheme: string, default: bool}|null */
	public function getDomain(string $host): ?array {
		$host = self::normalizeHost($host);
		foreach ($this->getDomains() as $d) {
			if ($d['host'] === $host) {
				return $d;
			}
		}
		return null;
	}

	/**
	 * The host name of this Nextcloud, as users see it.
	 * overwrite.cli.url wins so that links look the same from the web, the clients and cron.
	 */
	public function getDefaultHost(): string {
		$cli = $this->config->getSystemValueString('overwrite.cli.url', '');
		$host = $cli !== '' ? (string)parse_url($cli, PHP_URL_HOST) : '';
		if ($host === '') {
			$host = $this->request->getServerHost();
		}
		return self::normalizeHost($host);
	}

	public function getDefaultScheme(): string {
		$cli = $this->config->getSystemValueString('overwrite.cli.url', '');
		$scheme = $cli !== '' ? (string)parse_url($cli, PHP_URL_SCHEME) : '';
		if ($scheme === '') {
			$scheme = $this->request->getServerProtocol();
		}
		return $scheme === 'http' ? 'http' : 'https';
	}

	/**
	 * Which configured short domain a request host belongs to.
	 * Unknown hosts (a proxy alias, an IP address) fall back to the instance domain.
	 */
	public function resolveRequestHost(string $host): string {
		$host = self::normalizeHost($host);
		foreach ($this->getCustomDomains() as $d) {
			if ($d['host'] === $host) {
				return $host;
			}
		}
		return $this->getDefaultHost();
	}

	public function buildShortUrl(string $host, string $slug): string {
		$domain = $this->getDomain($host);
		if ($domain === null) {
			// the domain was removed from the settings: keep the link addressable at least
			$domain = ['host' => $host, 'prefix' => $host === $this->getDefaultHost() ? $this->getPrefix() : '', 'scheme' => 'https'];
		}
		return $domain['scheme'] . '://' . $domain['host'] . '/' . ($domain['prefix'] !== '' ? $domain['prefix'] . '/' : '') . $slug;
	}

	public function getPingUrl(?string $host = null): string {
		return $this->buildShortUrl($host ?? $this->getDefaultHost(), '_ping');
	}

	/** Whether the user may create links: creation is paused, or limited to groups. */
	public function canCreate(?string $userId): bool {
		if ($userId === null || $this->isPaused()) {
			return false;
		}
		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}
		$groups = $this->getCreatorGroups();
		if ($groups === []) {
			return true;
		}
		foreach ($groups as $gid) {
			if ($this->groupManager->isInGroup($userId, $gid)) {
				return true;
			}
		}
		return false;
	}

	public function isAdmin(?string $userId): bool {
		return $userId !== null && $this->groupManager->isAdmin($userId);
	}

	public function getCurrentUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	/** Everything the web UI needs to know, for one user. */
	public function forUser(?string $userId): array {
		return [
			'domains' => $this->getDomains(),
			'canCreate' => $this->canCreate($userId),
			'isAdmin' => $this->isAdmin($userId),
			'paused' => $this->isPaused(),
			'externalTargets' => $this->getExternalTargets(),
			'allowedHosts' => $this->getAllowedHosts(),
			'slugLength' => $this->getSlugLength(),
		];
	}

	/** Every setting, for the administration page. */
	public function all(): array {
		return [
			'paused' => $this->isPaused(),
			'autoCreate' => $this->autoCreate(),
			'prefix' => $this->getPrefix(),
			'slugLength' => $this->getSlugLength(),
			'externalTargets' => $this->getExternalTargets(),
			'allowedHosts' => $this->getAllowedHosts(),
			'creatorGroups' => $this->getCreatorGroups(),
			'reserved' => $this->getReservedSlugs(),
			'domains' => $this->getCustomDomains(),
			'manageHtaccess' => $this->manageHtaccess(),
			'defaultHost' => $this->getDefaultHost(),
			'defaultScheme' => $this->getDefaultScheme(),
		];
	}

	/**
	 * Applies a partial settings array coming from the administration page.
	 *
	 * @throws \InvalidArgumentException on a bad value
	 */
	public function update(array $settings): void {
		foreach ($settings as $key => $value) {
			switch ($key) {
				case 'paused':
				case 'autoCreate':
				case 'manageHtaccess':
					$this->set(self::snake($key), $value ? '1' : '0');
					break;
				case 'prefix':
					$prefix = trim((string)$value, "/ \t");
					if ($prefix === '' || !preg_match('/^[A-Za-z0-9_+~-]{1,32}$/', $prefix)) {
						throw new \InvalidArgumentException('The prefix may only contain letters, digits, "_", "-", "+" and "~"');
					}
					$this->set('prefix', $prefix);
					break;
				case 'slugLength':
					$n = (int)$value;
					if ($n < 4 || $n > 32) {
						throw new \InvalidArgumentException('The slug length must be between 4 and 32');
					}
					$this->set('slug_length', (string)$n);
					break;
				case 'externalTargets':
					if (!in_array($value, [self::EXTERNAL_NONE, self::EXTERNAL_LIST, self::EXTERNAL_ANY], true)) {
						throw new \InvalidArgumentException('Unknown external target policy');
					}
					$this->set('external_targets', (string)$value);
					break;
				case 'allowedHosts':
					$this->set('allowed_hosts', json_encode(array_values(array_unique(array_filter(array_map(
						static fn ($h) => self::normalizeHost((string)$h), (array)$value
					))))));
					break;
				case 'creatorGroups':
					$this->set('creator_groups', json_encode(array_values(array_unique(array_filter(array_map('strval', (array)$value))))));
					break;
				case 'reserved':
					$this->set('reserved', json_encode(array_values(array_unique(array_filter(array_map(
						static fn ($s) => trim((string)$s, "/ \t"), (array)$value
					))))));
					break;
				case 'domains':
					$domains = [];
					foreach ((array)$value as $entry) {
						if (!is_array($entry)) {
							continue;
						}
						$host = self::normalizeHost((string)($entry['host'] ?? ''));
						if ($host === '') {
							continue;
						}
						if (!preg_match('/^(?=.{1,253}$)([a-z0-9-]{1,63}\.)+[a-z0-9-]{1,63}$/', $host)) {
							throw new \InvalidArgumentException("\"$host\" is not a valid host name");
						}
						if ($host === $this->getDefaultHost()) {
							throw new \InvalidArgumentException('The instance domain is always available and does not need to be added');
						}
						$prefix = trim((string)($entry['prefix'] ?? ''), "/ \t");
						if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_+~-]{1,32}$/', $prefix)) {
							throw new \InvalidArgumentException("The prefix of \"$host\" may only contain letters, digits, \"_\", \"-\", \"+\" and \"~\"");
						}
						$domains[$host] = ['host' => $host, 'prefix' => $prefix, 'scheme' => ($entry['scheme'] ?? 'https') === 'http' ? 'http' : 'https'];
					}
					$this->set('domains', json_encode(array_values($domains)));
					break;
				default:
					// ignore unknown keys so that newer clients do not break older servers
			}
		}
	}

	public static function normalizeHost(string $host): string {
		$host = strtolower(trim($host));
		if (str_contains($host, '://')) {
			$host = (string)parse_url($host, PHP_URL_HOST);
		}
		$host = preg_replace('/:\d+$/', '', $host) ?? $host;
		return trim($host, "/ \t.");
	}

	private function set(string $key, string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}

	/** @return string[] */
	private function getList(string $key): array {
		$raw = json_decode($this->appConfig->getValueString(Application::APP_ID, $key, '[]'), true);
		return is_array($raw) ? array_values(array_filter(array_map('strval', $raw), static fn ($s) => $s !== '')) : [];
	}

	private static function snake(string $camel): string {
		return strtolower((string)preg_replace('/[A-Z]/', '_$0', $camel));
	}
}
