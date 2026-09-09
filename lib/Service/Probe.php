<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

use OCA\Shortcloud\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Asks the web server, over HTTP, whether "<short domain>/<prefix>/_ping" really
 * reaches go.php. Reading .htaccess alone cannot tell: nginx ignores the file,
 * Apache may run without AllowOverride. go.php answers the probe before
 * booting Nextcloud, with an "X-Shortcloud: ping" header.
 */
class Probe {
	public const HEADER = 'X-Shortcloud';
	private const CACHE_SECONDS = 300;

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private Config $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** True when the short address answers; cached for a few minutes unless $fresh. */
	public function works(bool $fresh = false, ?string $host = null): bool {
		$url = $this->config->getPingUrl($host);
		$key = 'probe:' . $url;
		if (!$fresh) {
			$cached = $this->appConfig->getValueString(Application::APP_ID, 'probe_cache', '');
			$parts = explode('|', $cached, 3);
			if (count($parts) === 3 && $parts[0] === $key && (int)$parts[1] > $this->time->getTime() - self::CACHE_SECONDS) {
				return $parts[2] === '1';
			}
		}
		$ok = $this->request($url);
		$this->appConfig->setValueString(Application::APP_ID, 'probe_cache', $key . '|' . $this->time->getTime() . '|' . ($ok ? '1' : '0'));
		return $ok;
	}

	private function request(string $url): bool {
		try {
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => 5,
				'connect_timeout' => 5,
				'verify' => false,
				'http_errors' => false,
				'headers' => ['User-Agent' => 'Shortcloud probe'],
				'nextcloud' => ['allow_local_address' => true],
			]);
			$header = $response->getHeader(self::HEADER);
			return $response->getStatusCode() === 200 && $header === 'ping';
		} catch (\Throwable $e) {
			$this->logger->debug('Shortcloud probe of ' . $url . ' failed: ' . $e->getMessage(), ['app' => 'shortcloud']);
			return false;
		}
	}
}
