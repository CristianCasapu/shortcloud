<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Entry point for short links: /go/<slug> is rewritten here by the web server
 * (see the Shortcloud administration settings). Nextcloud does not let a
 * third-party app register routes at the web root, so this script boots the
 * server the same way remote.php and public.php do and then runs the app's
 * redirect controller through the normal middleware stack (brute-force
 * protection, rate limiting, security headers).
 */

use OCA\Shortcloud\Controller\RedirectController;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\Server;
use OCP\Util;

foreach ([__DIR__ . '/../../lib/base.php', __DIR__ . '/../../../lib/base.php'] as $base) {
	if (is_file($base)) {
		require_once $base;
		break;
	}
}
if (!class_exists(\OC::class)) {
	http_response_code(500);
	echo 'Shortcloud: Nextcloud not found next to the app directory.';
	exit;
}

try {
	if (Util::needUpgrade() || Server::get(\OCP\IConfig::class)->getSystemValueBool('maintenance', false)) {
		http_response_code(503);
		header('Retry-After: 120');
		echo 'Nextcloud is in maintenance mode, please try again in a moment.';
		exit;
	}

	$appManager = Server::get(IAppManager::class);
	if (!$appManager->isEnabledForUser('shortcloud')) {
		http_response_code(404);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Short links are switched off on this server.';
		exit;
	}

	\OC::$REQUESTEDAPP = 'shortcloud';
	$appManager->loadApps(['authentication']);
	$appManager->loadApps(['extended_authentication']);
	$appManager->loadApps(['filesystem', 'logging']);
	$appManager->loadApp('shortcloud');
	if ($appManager->isEnabledForUser('files_sharing')) {
		$appManager->loadApp('files_sharing');
	}
	\OC_User::setIncognitoMode(true);

	$slug = (string)Server::get(IRequest::class)->getParam('slug', '');
	$application = Server::get(\OCA\Shortcloud\AppInfo\Application::class);
	\OC\AppFramework\App::main(RedirectController::class, 'go', $application->getContainer(), [
		'slug' => $slug,
		'_route' => 'shortcloud.redirect.go',
	]);
} catch (\Throwable $e) {
	Server::get(\Psr\Log\LoggerInterface::class)->error('Shortcloud redirect failed: ' . $e->getMessage(), ['app' => 'shortcloud', 'exception' => $e]);
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'The short link could not be followed.';
}
