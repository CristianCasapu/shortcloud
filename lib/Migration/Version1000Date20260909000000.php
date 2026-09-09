<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260909000000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('shortcloud_links')) {
			return null;
		}
		$table = $schema->createTable('shortcloud_links');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('domain', Types::STRING, ['notnull' => true, 'length' => 253]);
		$table->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('target', Types::TEXT, ['notnull' => true]);
		$table->addColumn('share_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
		$table->addColumn('hits', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_hit', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['domain', 'slug'], 'shortcloud_domain_slug');
		$table->addIndex(['user_id'], 'shortcloud_user');
		$table->addIndex(['share_id'], 'shortcloud_share');
		return $schema;
	}
}
