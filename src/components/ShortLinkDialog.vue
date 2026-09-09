<template>
	<NcDialog :name="dialogName"
		:open="open"
		size="normal"
		@update:open="onOpenChange"
		@closing="onClosing">
		<div class="shortcloud-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div v-if="current" class="shortcloud-dialog__current">
				<NcTextField :model-value="current.shortUrl"
					:label="t('shortcloud', 'Short link')"
					readonly
					@focus="selectAll" />
				<NcButton variant="primary" :aria-label="t('shortcloud', 'Copy short link')" @click="copy(current.shortUrl)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('shortcloud', 'Copy') }}
				</NcButton>
				<NcButton :aria-label="t('shortcloud', 'QR code')" :pressed="showQr" @click="showQr = !showQr">
					<template #icon>
						<Qrcode :size="20" />
					</template>
				</NcButton>
			</div>
			<QrCode v-if="current && showQr" :text="current.shortUrl" />
			<p v-if="current && current.hits > 0" class="shortcloud-dialog__hint">
				{{ n('shortcloud', '%n visit', '%n visits', current.hits) }}
			</p>

			<template v-if="!current || canEdit">
				<h3 class="shortcloud-dialog__heading">
					{{ current ? t('shortcloud', 'Customize') : t('shortcloud', 'New short link') }}
				</h3>
				<NcTextField v-if="!shareId"
					v-model="form.target"
					:label="t('shortcloud', 'Address to shorten')"
					placeholder="https://"
					:disabled="!!current?.shareId"
					type="url" />
				<NcTextField v-if="!shareId"
					v-model="form.title"
					:label="t('shortcloud', 'Label (optional)')" />
				<div class="shortcloud-dialog__row">
					<NcSelect v-model="form.domain"
						class="shortcloud-dialog__domain"
						:options="domainOptions"
						:clearable="false"
						:searchable="false"
						:input-label="t('shortcloud', 'Domain')"
						label="label" />
					<NcTextField v-model="form.slug"
						class="shortcloud-dialog__slug"
						:label="t('shortcloud', 'Custom ending (optional)')"
						:placeholder="current ? current.slug : t('shortcloud', 'random')"
						:helper-text="t('shortcloud', 'Letters, digits, - and _')" />
				</div>
				<p class="shortcloud-dialog__preview">
					{{ preview }}
				</p>
			</template>
		</div>

		<template #actions>
			<NcButton v-if="current && canEdit" variant="tertiary" @click="remove">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('shortcloud', 'Delete') }}
			</NcButton>
			<NcButton v-if="current && canEdit && current.status !== 'gone'" variant="tertiary" @click="togglePause">
				<template #icon>
					<Play v-if="current.status === 'paused'" :size="20" />
					<Pause v-else :size="20" />
				</template>
				{{ current.status === 'paused' ? t('shortcloud', 'Resume') : t('shortcloud', 'Pause') }}
			</NcButton>
			<NcButton variant="secondary" @click="close()">
				{{ t('shortcloud', 'Close') }}
			</NcButton>
			<NcButton v-if="!current || canEdit" variant="primary" :disabled="busy" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
					<Check v-else :size="20" />
				</template>
				{{ current ? t('shortcloud', 'Save') : t('shortcloud', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pause from 'vue-material-design-icons/Pause.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Qrcode from 'vue-material-design-icons/Qrcode.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { copyText, createLink, deleteLink, errorMessage, linksForShare, updateLink } from '../api'
import { domainLabel, type Domain, type ShortLink, type UserConfig } from '../types'
import QrCode from './QrCode.vue'

const props = defineProps<{
	config: UserConfig
	/** An existing link to show/edit */
	link?: ShortLink | null
	/** A link share to shorten: the existing short link is looked up, or created */
	shareId?: string | number | null
	/** Name of the shared file, for the title */
	shareName?: string
	/** For a brand-new custom link: pre-filled target */
	target?: string
}>()

const emit = defineEmits<{
	/** The link as it is when the dialog closes (null when deleted or nothing created) */
	close: [link: ShortLink | null, deleted: boolean]
}>()

const open = ref(true)
const busy = ref(false)
const error = ref('')
const showQr = ref(false)
const current = ref<ShortLink | null>(props.link ?? null)
const deleted = ref(false)

const canEdit = computed(() => props.config.canCreate || props.config.isAdmin)

const domainOptions = computed(() => props.config.domains.map((d) => ({ ...d, label: domainLabel(d) })))
const defaultDomain = () => domainOptions.value.find((d) => d.host === (current.value?.domain ?? '')) ?? domainOptions.value[0]

const form = reactive({
	target: props.target ?? props.link?.target ?? '',
	title: props.link?.title ?? '',
	domain: defaultDomain() as (Domain & { label: string }) | undefined,
	slug: '',
})

const dialogName = computed(() => {
	if (props.shareName) {
		return t('shortcloud', 'Short link for {name}', { name: props.shareName })
	}
	return current.value ? t('shortcloud', 'Short link') : t('shortcloud', 'New short link')
})

const preview = computed(() => {
	const d = form.domain
	if (!d) {
		return ''
	}
	const slug = form.slug.trim() || current.value?.slug || '…'
	return domainLabel(d) + slug
})

onMounted(async () => {
	if (!current.value && props.shareId != null) {
		await loadForShare()
	}
})

async function loadForShare() {
	busy.value = true
	try {
		const links = await linksForShare(props.shareId!)
		const first = links[0]
		if (first) {
			current.value = first
		} else if (canEdit.value) {
			current.value = await createLink({ shareId: props.shareId! })
		} else {
			error.value = props.config.paused
				? t('shortcloud', 'Creating short links is paused by the administrator')
				: t('shortcloud', 'You are not allowed to create short links')
		}
		form.domain = defaultDomain()
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not load the short link'))
	} finally {
		busy.value = false
	}
}

async function save() {
	error.value = ''
	busy.value = true
	try {
		if (current.value) {
			const payload: Record<string, string> = {}
			if (form.slug.trim() && form.slug.trim() !== current.value.slug) {
				payload.slug = form.slug.trim()
			}
			if (form.domain && form.domain.host !== current.value.domain) {
				payload.domain = form.domain.host
			}
			if (!current.value.shareId) {
				if (form.target.trim() !== current.value.target) {
					payload.target = form.target.trim()
				}
				if ((form.title ?? '') !== (current.value.title ?? '')) {
					payload.title = form.title
				}
			}
			if (Object.keys(payload).length === 0) {
				close()
				return
			}
			current.value = await updateLink(current.value.id, payload)
			form.slug = ''
			showSuccess(t('shortcloud', 'Short link saved'))
		} else {
			current.value = await createLink({
				target: form.target.trim(),
				shareId: props.shareId ?? undefined,
				domain: form.domain?.host,
				slug: form.slug.trim() || undefined,
				title: form.title || undefined,
			})
			form.slug = ''
			showSuccess(t('shortcloud', 'Short link created'))
		}
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not save the short link'))
	} finally {
		busy.value = false
	}
}

async function togglePause() {
	if (!current.value) {
		return
	}
	busy.value = true
	try {
		current.value = await updateLink(current.value.id, { status: current.value.status === 'paused' ? 'active' : 'paused' })
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not save the short link'))
	} finally {
		busy.value = false
	}
}

async function remove() {
	if (!current.value) {
		return
	}
	busy.value = true
	try {
		await deleteLink(current.value.id)
		deleted.value = true
		current.value = null
		showSuccess(t('shortcloud', 'Short link deleted'))
		close()
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not delete the short link'))
	} finally {
		busy.value = false
	}
}

async function copy(text: string) {
	if (await copyText(text)) {
		showSuccess(t('shortcloud', 'Short link copied'))
	} else {
		showError(t('shortcloud', 'Could not copy, please select and copy the link by hand'))
	}
}

function selectAll(event: FocusEvent) {
	(event.target as HTMLInputElement)?.select?.()
}

function close() {
	open.value = false
}

function onOpenChange(value: boolean) {
	if (!value) {
		close()
	}
}

function onClosing() {
	emit('close', current.value, deleted.value)
}
</script>

<style scoped>
.shortcloud-dialog {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 4px 0 12px;
}
.shortcloud-dialog__current {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}
.shortcloud-dialog__current > :first-child {
	flex: 1;
}
.shortcloud-dialog__heading {
	font-weight: bold;
	margin: 12px 0 0;
}
.shortcloud-dialog__row {
	display: flex;
	gap: 8px;
	align-items: flex-start;
	flex-wrap: wrap;
}
.shortcloud-dialog__domain {
	flex: 3 1 260px;
	min-width: 240px;
}
.shortcloud-dialog__slug {
	flex: 2 1 180px;
}
.shortcloud-dialog__preview {
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}
.shortcloud-dialog__hint {
	color: var(--color-text-maxcontrast);
}
</style>
