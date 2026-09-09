<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getDomain()
 * @method void setDomain(string $domain)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string getTarget()
 * @method void setTarget(string $target)
 * @method ?string getShareId()
 * @method void setShareId(?string $shareId)
 * @method ?string getTitle()
 * @method void setTitle(?string $title)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getHits()
 * @method void setHits(int $hits)
 * @method ?int getLastHit()
 * @method void setLastHit(?int $lastHit)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Link extends Entity implements JsonSerializable {
	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';
	public const STATUS_GONE = 'gone';

	protected string $userId = '';
	protected string $domain = '';
	protected string $slug = '';
	protected string $target = '';
	protected ?string $shareId = null;
	protected ?string $title = null;
	protected string $status = self::STATUS_ACTIVE;
	protected int $hits = 0;
	protected ?int $lastHit = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('domain', 'string');
		$this->addType('slug', 'string');
		$this->addType('target', 'string');
		$this->addType('shareId', 'string');
		$this->addType('title', 'string');
		$this->addType('status', 'string');
		$this->addType('hits', 'integer');
		$this->addType('lastHit', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/** The short URL is added by the service, which knows the domain configuration. */
	public ?string $shortUrl = null;

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'domain' => $this->getDomain(),
			'slug' => $this->getSlug(),
			'target' => $this->getTarget(),
			'shareId' => $this->getShareId(),
			'title' => $this->getTitle(),
			'status' => $this->getStatus(),
			'hits' => $this->getHits(),
			'lastHit' => $this->getLastHit(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
			'shortUrl' => $this->shortUrl,
		];
	}
}
