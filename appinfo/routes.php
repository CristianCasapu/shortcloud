<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'redirect#go', 'url' => '/go/{slug}', 'verb' => 'GET', 'requirements' => ['slug' => '.+']],
	],
	'ocs' => [
		['name' => 'links#config', 'url' => '/api/v1/config', 'verb' => 'GET'],
		['name' => 'links#index', 'url' => '/api/v1/links', 'verb' => 'GET'],
		['name' => 'links#create', 'url' => '/api/v1/links', 'verb' => 'POST'],
		['name' => 'links#update', 'url' => '/api/v1/links/{id}', 'verb' => 'PUT'],
		['name' => 'links#destroy', 'url' => '/api/v1/links/{id}', 'verb' => 'DELETE'],
		['name' => 'links#forShare', 'url' => '/api/v1/share/{shareId}', 'verb' => 'GET'],
		['name' => 'admin#getSettings', 'url' => '/api/v1/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#setSettings', 'url' => '/api/v1/admin/settings', 'verb' => 'PUT'],
		['name' => 'admin#rewriteStatus', 'url' => '/api/v1/admin/rewrite', 'verb' => 'GET'],
		['name' => 'admin#installRewrite', 'url' => '/api/v1/admin/rewrite', 'verb' => 'POST'],
		['name' => 'admin#removeRewrite', 'url' => '/api/v1/admin/rewrite', 'verb' => 'DELETE'],
		['name' => 'admin#setPrettyUrls', 'url' => '/api/v1/admin/pretty-urls', 'verb' => 'PUT'],
	],
];
