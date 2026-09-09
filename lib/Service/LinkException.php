<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Shortcloud\Service;

class LinkException extends \RuntimeException {
	public function __construct(string $message, private string $reason = 'invalid') {
		parent::__construct($message);
	}

	public function getReason(): string {
		return $this->reason;
	}
}
