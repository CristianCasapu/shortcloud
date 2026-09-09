import '@nextcloud/dialogs/style.css'
/**
 * Adds "Copy short link" and "Short link…" to the "…" menu of every public link
 * share in the Files sharing sidebar (Nextcloud 32+ inline actions API).
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { registerSidebarInlineAction } from '@nextcloud/sharing/ui'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import linkVariant from '@mdi/svg/svg/link-variant.svg?raw'
import contentCopy from '@mdi/svg/svg/content-copy.svg?raw'
import { copyText, createLink, errorMessage, linksForShare } from './api'
import type { UserConfig } from './types'

const SHARE_TYPE_LINK = 3

const config = loadState<UserConfig>('shortcloud', 'config', {
	domains: [], canCreate: false, isAdmin: false, paused: false, externalTargets: 'none', allowedHosts: [], slugLength: 7,
})

type LegacyShare = { id: string | number, type: number, token?: string }
type LegacyNode = { basename?: string, displayname?: string }

const isLink = (share: LegacyShare) => share?.type === SHARE_TYPE_LINK && share?.id !== undefined && share?.id !== null

async function shortLinkFor(share: LegacyShare) {
	const links = await linksForShare(share.id)
	const first = links[0]
	if (first) {
		return first
	}
	if (!config.canCreate) {
		throw new Error(config.paused
			? t('shortcloud', 'Creating short links is paused by the administrator')
			: t('shortcloud', 'You are not allowed to create short links'))
	}
	return createLink({ shareId: share.id })
}

registerSidebarInlineAction({
	id: 'shortcloud-copy',
	order: 5,
	iconSvg: contentCopy,
	label: () => t('shortcloud', 'Copy short link'),
	enabled: (share) => isLink(share as unknown as LegacyShare),
	exec: async (share) => {
		try {
			const link = await shortLinkFor(share as unknown as LegacyShare)
			if (await copyText(link.shortUrl)) {
				showSuccess(t('shortcloud', 'Short link copied: {url}', { url: link.shortUrl }))
			} else {
				showError(t('shortcloud', 'Could not copy, the short link is {url}', { url: link.shortUrl }))
			}
		} catch (e) {
			showError(errorMessage(e, (e as Error)?.message || t('shortcloud', 'Could not load the short link')))
		}
	},
})

registerSidebarInlineAction({
	id: 'shortcloud-edit',
	order: 4,
	iconSvg: linkVariant,
	label: () => t('shortcloud', 'Short link…'),
	enabled: (share) => isLink(share as unknown as LegacyShare),
	exec: async (share, node) => {
		const { default: ShortLinkDialog } = await import('./components/ShortLinkDialog.vue')
		const n = node as unknown as LegacyNode
		await spawnDialog(ShortLinkDialog, {
			config,
			shareId: (share as unknown as LegacyShare).id,
			shareName: n?.displayname || n?.basename || '',
		})
	},
})
