<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Link>
 */
class LinkMapper extends QBMapper {
	public const TABLE = 'shortcloud_links';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Link::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findBySlug(string $domain, string $slug): Link {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('domain', $qb->createNamedParameter($domain)))
			->andWhere($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Link {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Link[] */
	public function findByUser(string $userId, string $search = '', int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$this->applySearch($qb, $search);
		return $this->findEntities($qb);
	}

	/** @return Link[] */
	public function findAll(string $search = '', int $limit = 500, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$this->applySearch($qb, $search);
		return $this->findEntities($qb);
	}

	/** @return Link[] */
	public function findByShare(string $shareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return Link[] */
	public function findByShareIdPrefix(string $prefix): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->like('share_id', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')));
		return $this->findEntities($qb);
	}

	public function countByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	public function slugExists(string $domain, string $slug): bool {
		try {
			$this->findBySlug($domain, $slug);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/** Counts a visit without loading or rewriting the whole row. */
	public function recordHit(int $id, int $time): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('hits', $qb->createFunction('`hits` + 1'))
			->set('last_hit', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Marks every link that points to the share as gone; returns how many rows changed. */
	public function markShareGone(string $shareId, int $time): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(Link::STATUS_GONE))
			->set('updated_at', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId)))
			->andWhere($qb->expr()->neq('status', $qb->createNamedParameter(Link::STATUS_GONE)));
		return $qb->executeStatement();
	}

	public function deleteByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}

	private function applySearch(IQueryBuilder $qb, string $search): void {
		$search = trim($search);
		if ($search === '') {
			return;
		}
		$like = '%' . $this->db->escapeLikeParameter($search) . '%';
		$qb->andWhere($qb->expr()->orX(
			$qb->expr()->iLike('slug', $qb->createNamedParameter($like)),
			$qb->expr()->iLike('title', $qb->createNamedParameter($like)),
			$qb->expr()->iLike('target', $qb->createNamedParameter($like)),
		));
	}
}
