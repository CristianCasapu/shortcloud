<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

use OCA\Shortcloud\AppInfo\Application;
use OCP\App\IAppManager;

/**
 * The one web-server rule that makes "/go/slug" reach the app.
 *
 * A third-party app cannot register a route at the web root (Nextcloud keeps an
 * allow-list for that), so the request is rewritten to the app's own entry
 * point, apps/shortcloud/go.php, which boots Nextcloud and runs the redirect
 * controller through the normal middleware stack.
 */
class Htaccess {
	public const BEGIN = '# BEGIN Shortcloud';
	public const END = '# END Shortcloud';
	/** Nextcloud regenerates everything below this line on every upgrade. */
	public const NC_MARKER = '#### DO NOT CHANGE ANYTHING ABOVE THIS LINE ####';

	public const STATUS_OK = 'ok';
	public const STATUS_OUTDATED = 'outdated';
	public const STATUS_MISSING = 'missing';
	public const STATUS_UNAVAILABLE = 'unavailable';

	public function __construct(
		private Config $config,
		private IAppManager $appManager,
	) {
	}

	public function path(): string {
		return \OC::$SERVERROOT . '/.htaccess';
	}

	/** Path of go.php relative to the web root, e.g. "apps/shortcloud/go.php"; null when the app lives outside the web root. */
	public function entryPoint(): ?string {
		try {
			$appPath = realpath($this->appManager->getAppPath(Application::APP_ID));
		} catch (\Throwable) {
			return null;
		}
		$root = realpath(\OC::$SERVERROOT);
		if ($appPath === false || $root === false || !str_starts_with($appPath, $root . '/')) {
			return null;
		}
		return substr($appPath, strlen($root) + 1) . '/go.php';
	}

	/** The block for .htaccess (per-directory context, relative paths). */
	public function block(): ?string {
		$entry = $this->entryPoint();
		if ($entry === null) {
			return null;
		}
		$prefix = preg_quote($this->config->getPrefix(), '/');
		return implode("\n", [
			self::BEGIN,
			'# Short links: /' . $this->config->getPrefix() . '/<slug> -> the Shortcloud app. Managed by the app, edit at your own risk.',
			'# END (not L) so that the pretty-URL rules below do not rewrite go.php to index.php in the next round.',
			'<IfModule mod_rewrite.c>',
			'  RewriteEngine on',
			'  RewriteRule ^' . $prefix . '/(.*)$ ' . $entry . '?slug=$1 [END,QSA]',
			'</IfModule>',
			self::END,
		]) . "\n";
	}

	/** Snippet for a dedicated short domain served by the same Apache (virtual host context). */
	public function vhostSnippet(string $host, string $prefix = '', string $webroot = ''): string {
		$entry = $this->entryPoint() ?? 'apps/shortcloud/go.php';
		$rule = $prefix === ''
			? 'RewriteRule ^/(.*)$ ' . $webroot . '/' . $entry . '?slug=$1 [END,QSA]'
			: 'RewriteRule ^/' . preg_quote($prefix, '/') . '/(.*)$ ' . $webroot . '/' . $entry . '?slug=$1 [END,QSA]';
		return implode("\n", [
			'<VirtualHost *:443>',
			'    ServerName ' . $host,
			'    DocumentRoot ' . \OC::$SERVERROOT,
			'    SSLEngine on',
			'    SSLCertificateFile /etc/letsencrypt/live/' . $host . '/fullchain.pem',
			'    SSLCertificateKeyFile /etc/letsencrypt/live/' . $host . '/privkey.pem',
			'    RewriteEngine on',
			'    ' . $rule,
			'    RewriteRule ^ - [R=404,L]',
			'</VirtualHost>',
		]) . "\n";
	}

	/** Snippet for nginx, for administrators who do not use Apache. */
	public function nginxSnippet(): string {
		$entry = $this->entryPoint() ?? 'apps/shortcloud/go.php';
		return implode("\n", [
			'location ~ ^/' . $this->config->getPrefix() . '/(.*)$ {',
			'    rewrite ^/' . $this->config->getPrefix() . '/(.*)$ /' . $entry . '?slug=$1 last;',
			'}',
		]) . "\n";
	}

	public function status(): string {
		$block = $this->block();
		$path = $this->path();
		if ($block === null || !is_file($path) || !is_readable($path)) {
			return self::STATUS_UNAVAILABLE;
		}
		$content = (string)file_get_contents($path);
		if (str_contains($content, $block)) {
			return self::STATUS_OK;
		}
		return str_contains($content, self::BEGIN) ? self::STATUS_OUTDATED : self::STATUS_MISSING;
	}

	public function isWritable(): bool {
		return is_file($this->path()) && is_writable($this->path());
	}

	/**
	 * Writes (or refreshes) the block at the end of .htaccess.
	 *
	 * @throws \RuntimeException when the file cannot be written
	 */
	public function install(): void {
		$block = $this->block();
		if ($block === null) {
			throw new \RuntimeException('The app directory is outside the web root; add the rewrite rule to the web server configuration by hand.');
		}
		if (!$this->isWritable()) {
			throw new \RuntimeException('.htaccess is not writable by the web server user.');
		}
		$content = $this->strip((string)file_get_contents($this->path()));
		// The block must run before the "pretty URL" rules Nextcloud generates below its
		// marker line, otherwise /go/… is swallowed by the generic "RewriteRule . index.php".
		$marker = self::NC_MARKER . "\n";
		$pos = strpos($content, $marker);
		if ($pos !== false) {
			$content = substr($content, 0, $pos + strlen($marker)) . "\n" . $block . substr($content, $pos + strlen($marker));
		} else {
			$content = rtrim($content, "\n") . "\n\n" . $block;
		}
		$this->write($content);
	}

	public function remove(): void {
		if (!$this->isWritable()) {
			throw new \RuntimeException('.htaccess is not writable by the web server user.');
		}
		$content = (string)file_get_contents($this->path());
		if (!str_contains($content, self::BEGIN)) {
			return;
		}
		$this->write(rtrim($this->strip($content), "\n") . "\n");
	}

	private function strip(string $content): string {
		$pattern = '/\n*' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n?/s';
		return (string)preg_replace($pattern, "\n", $content);
	}

	private function write(string $content): void {
		$path = $this->path();
		$tmp = $path . '.shortcloud.tmp';
		if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
			@unlink($tmp);
			throw new \RuntimeException('Could not write .htaccess');
		}
	}
}
