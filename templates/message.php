<?php
// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="guest-box shortcloud-message" style="max-width: 420px; margin: 0 auto; text-align: center;">
	<h2 style="margin-bottom: 12px;"><?php p($_['title']); ?></h2>
	<p><?php p($_['message']); ?></p>
	<p style="margin-top: 24px;"><a class="button primary" href="<?php p($_['home']); ?>"><?php p($l->t('Go to the start page')); ?></a></p>
</div>
