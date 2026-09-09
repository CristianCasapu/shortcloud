<template>
	<NcContent app-name="shortcloud">
		<NcAppContent>
			<div class="shortcloud">
				<header class="shortcloud__header">
					<h2>{{ t('shortcloud', 'Short links') }}</h2>
					<p class="shortcloud__intro">
						{{ t('shortcloud', 'Every public share link gets a short address automatically. You can also shorten any address here.') }}
					</p>
				</header>

				<NcNoteCard v-if="config.paused" type="warning">
					{{ t('shortcloud', 'Creating short links is paused by the administrator. Existing links keep working.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="!config.canCreate" type="info">
					{{ t('shortcloud', 'You are not allowed to create short links; existing ones are listed below.') }}
				</NcNoteCard>

				<form v-if="config.canCreate" class="shortcloud__create" @submit.prevent="create">
					<NcTextField v-model="newTarget"
						class="shortcloud__create-target"
						:label="t('shortcloud', 'Address to shorten')"
						placeholder="https://"
						type="url"
						required />
					<NcButton variant="primary" native-type="submit" :disabled="creating || !newTarget.trim()">
						<template #icon>
							<NcLoadingIcon v-if="creating" :size="20" />
							<LinkPlus v-else :size="20" />
						</template>
						{{ t('shortcloud', 'Shorten') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="!newTarget.trim()" @click="createCustom">
						{{ t('shortcloud', 'Customize…') }}
					</NcButton>
				</form>
				<p v-if="config.canCreate && config.externalTargets === 'none'" class="shortcloud__policy">
					{{ t('shortcloud', 'Only addresses on this Nextcloud can be shortened.') }}
				</p>
				<p v-else-if="config.canCreate && config.externalTargets === 'list'" class="shortcloud__policy">
					{{ t('shortcloud', 'Addresses on this Nextcloud and on: {hosts}', { hosts: config.allowedHosts.join(', ') }) }}
				</p>

				<div class="shortcloud__toolbar">
					<NcTextField v-model="search"
						class="shortcloud__search"
						:label="t('shortcloud', 'Search')"
						:placeholder="t('shortcloud', 'Search by ending, label or target')"
						@update:model-value="scheduleReload" />
					<NcCheckboxRadioSwitch v-if="config.isAdmin" v-model="showAll" type="switch" @update:model-value="reload">
						{{ t('shortcloud', 'All accounts') }}
					</NcCheckboxRadioSwitch>
					<span class="shortcloud__count">{{ n('shortcloud', '%n link', '%n links', links.length) }}</span>
				</div>

				<NcEmptyContent v-if="!loading && links.length === 0"
					:name="t('shortcloud', 'No short links yet')"
					:description="t('shortcloud', 'Share a file or folder by link, or shorten an address above.')">
					<template #icon>
						<LinkVariant />
					</template>
				</NcEmptyContent>

				<div v-else class="shortcloud__table-wrap">
					<table class="shortcloud__table">
						<thead>
							<tr>
								<th>{{ t('shortcloud', 'Short link') }}</th>
								<th>{{ t('shortcloud', 'Points to') }}</th>
								<th v-if="showAll">{{ t('shortcloud', 'Account') }}</th>
								<th class="num">{{ t('shortcloud', 'Visits') }}</th>
								<th>{{ t('shortcloud', 'Last visit') }}</th>
								<th class="actions" />
							</tr>
						</thead>
						<tbody>
							<tr v-for="link in links" :key="link.id" :class="{ 'is-inactive': link.status !== 'active' }">
								<td class="short">
									<a :href="link.shortUrl" target="_blank" rel="noopener noreferrer">{{ pretty(link.shortUrl) }}</a>
									<span v-if="link.status === 'paused'" class="shortcloud__badge">{{ t('shortcloud', 'paused') }}</span>
									<span v-else-if="link.status === 'gone'" class="shortcloud__badge shortcloud__badge--gone">{{ t('shortcloud', 'share removed') }}</span>
								</td>
								<td class="target">
									<span v-if="link.title" class="shortcloud__title">{{ link.title }}</span>
									<a :href="link.target" target="_blank" rel="noopener noreferrer" class="shortcloud__target">{{ link.target }}</a>
								</td>
								<td v-if="showAll">{{ link.userId }}</td>
								<td class="num">{{ link.hits }}</td>
								<td class="date">{{ link.lastHit ? relative(link.lastHit) : '—' }}</td>
								<td class="actions">
									<NcButton variant="tertiary" :aria-label="t('shortcloud', 'Copy short link')" :title="t('shortcloud', 'Copy short link')" @click="copy(link)">
										<template #icon>
											<ContentCopy :size="20" />
										</template>
									</NcButton>
									<NcButton variant="tertiary" :aria-label="t('shortcloud', 'Details')" :title="t('shortcloud', 'Details')" @click="edit(link)">
										<template #icon>
											<Pencil :size="20" />
										</template>
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import LinkPlus from 'vue-material-design-icons/LinkPlus.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { onMounted, ref } from 'vue'
import { copyText, createLink, errorMessage, listLinks } from '../api'
import type { ShortLink, UserConfig } from '../types'
import ShortLinkDialog from '../components/ShortLinkDialog.vue'

const props = defineProps<{ config: UserConfig }>()

const links = ref<ShortLink[]>([])
const loading = ref(true)
const search = ref('')
const showAll = ref(false)
const newTarget = ref('')
const creating = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

onMounted(reload)

async function reload() {
	loading.value = true
	try {
		links.value = await listLinks(search.value, showAll.value)
	} catch (e) {
		showError(errorMessage(e, t('shortcloud', 'Could not load the short links')))
	} finally {
		loading.value = false
	}
}

function scheduleReload() {
	clearTimeout(timer)
	timer = setTimeout(reload, 300)
}

async function create() {
	creating.value = true
	try {
		const link = await createLink({ target: newTarget.value.trim() })
		newTarget.value = ''
		links.value.unshift(link)
		if (await copyText(link.shortUrl)) {
			showSuccess(t('shortcloud', 'Short link created and copied: {url}', { url: link.shortUrl }))
		} else {
			showSuccess(t('shortcloud', 'Short link created: {url}', { url: link.shortUrl }))
		}
	} catch (e) {
		showError(errorMessage(e, t('shortcloud', 'Could not create the short link')))
	} finally {
		creating.value = false
	}
}

async function createCustom() {
	const [link] = await spawnDialog(ShortLinkDialog, { config: props.config, target: newTarget.value.trim() }) as unknown as [ShortLink | null, boolean]
	if (link) {
		newTarget.value = ''
		await reload()
	}
}

async function edit(link: ShortLink) {
	await spawnDialog(ShortLinkDialog, { config: props.config, link })
	await reload()
}

async function copy(link: ShortLink) {
	if (await copyText(link.shortUrl)) {
		showSuccess(t('shortcloud', 'Short link copied'))
	} else {
		showError(t('shortcloud', 'Could not copy, please select and copy the link by hand'))
	}
}

function pretty(url: string) {
	return url.replace(/^https?:\/\//, '')
}

function relative(ts: number) {
	const diff = Math.round(Date.now() / 1000 - ts)
	if (diff < 60) {
		return t('shortcloud', 'just now')
	}
	if (diff < 3600) {
		return n('shortcloud', '%n minute ago', '%n minutes ago', Math.round(diff / 60))
	}
	if (diff < 86400) {
		return n('shortcloud', '%n hour ago', '%n hours ago', Math.round(diff / 3600))
	}
	if (diff < 86400 * 30) {
		return n('shortcloud', '%n day ago', '%n days ago', Math.round(diff / 86400))
	}
	return new Date(ts * 1000).toLocaleDateString()
}
</script>

<style scoped>
.shortcloud {
	max-width: 1100px;
	margin: 0 auto;
	padding: 20px 24px 40px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.shortcloud__header h2 {
	margin-bottom: 4px;
}
.shortcloud__intro,
.shortcloud__policy,
.shortcloud__count {
	color: var(--color-text-maxcontrast);
}
.shortcloud__create {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}
.shortcloud__create-target {
	flex: 1 1 320px;
}
.shortcloud__toolbar {
	display: flex;
	gap: 16px;
	align-items: center;
	flex-wrap: wrap;
	margin-top: 12px;
}
.shortcloud__search {
	flex: 1 1 280px;
	max-width: 420px;
}
.shortcloud__table-wrap {
	overflow-x: auto;
}
.shortcloud__table {
	width: 100%;
	border-collapse: collapse;
}
.shortcloud__table th,
.shortcloud__table td {
	text-align: start;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}
.shortcloud__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}
.shortcloud__table .num {
	text-align: end;
	white-space: nowrap;
}
.shortcloud__table .date {
	white-space: nowrap;
}
.shortcloud__table .actions {
	white-space: nowrap;
	text-align: end;
}
.shortcloud__table .actions > * {
	display: inline-flex;
}
.shortcloud__table .short a {
	font-weight: bold;
	word-break: break-all;
}
.shortcloud__table .target {
	max-width: 420px;
}
.shortcloud__title {
	display: block;
}
.shortcloud__target {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 420px;
}
.shortcloud__badge {
	display: inline-block;
	margin-inline-start: 8px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
.shortcloud__badge--gone {
	background: var(--color-error);
	color: var(--color-error-text, #fff);
}
tr.is-inactive .short a {
	text-decoration: line-through;
}
</style>
