<template>
	<div class="shortcloud-admin">
		<NcSettingsSection :name="t('shortcloud', 'Shortcloud')"
			:description="t('shortcloud', 'Short links for public shares and any other address. Example: {url}', { url: rewrite.exampleUrl })"
			doc-url="https://github.com/CristianCasapu/shortcloud#readme">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<h3>{{ t('shortcloud', 'Web server rule') }}</h3>
			<p class="shortcloud-admin__text">
				{{ t('shortcloud', 'Nextcloud does not let an app answer at the root of the site, so one rewrite rule sends {prefix}/… to the app. Without it, short links answer 404 and only the long form works.', { prefix: '/' + settings.prefix }) }}
			</p>
			<div class="shortcloud-admin__status">
				<NcNoteCard v-if="rewrite.status === 'ok' && rewrite.probe" type="success">
					{{ t('shortcloud', 'The rule is installed in {path} and the short address answers.', { path: rewrite.path }) }}
				</NcNoteCard>
				<NcNoteCard v-else-if="rewrite.status === 'ok'" type="warning">
					{{ t('shortcloud', 'The rule is in {path}, but the web server does not apply it: short links answer 404. Under nginx, or Apache without AllowOverride, add the snippet below to the web server configuration instead.', { path: rewrite.path }) }}
				</NcNoteCard>
				<NcNoteCard v-else-if="rewrite.status === 'outdated'" type="warning">
					{{ t('shortcloud', 'The rule in .htaccess is out of date (the prefix or the app path changed). Install it again.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="rewrite.status === 'unavailable'" type="info">
					{{ t('shortcloud', 'The app is outside the web root or .htaccess is not readable; add the rule to the web server configuration by hand.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('shortcloud', 'The rule is not installed. Short links answer 404 until it is.') }}
				</NcNoteCard>
			</div>
			<div class="shortcloud-admin__buttons">
				<NcButton variant="primary" :disabled="busy || !rewrite.writable" @click="installRewrite">
					{{ rewrite.status === 'ok' ? t('shortcloud', 'Reinstall rewrite rule') : t('shortcloud', 'Install rewrite rule') }}
				</NcButton>
				<NcButton :disabled="busy" @click="runProbe()">
					{{ t('shortcloud', 'Test the short address') }}
				</NcButton>
				<NcButton v-if="rewrite.status === 'ok' || rewrite.status === 'outdated'" variant="tertiary" :disabled="busy" @click="removeRewrite">
					{{ t('shortcloud', 'Remove rule') }}
				</NcButton>
			</div>
			<NcCheckboxRadioSwitch v-model="settings.manageHtaccess" type="switch" :disabled="busy" @update:model-value="save({ manageHtaccess: $event })">
				{{ t('shortcloud', 'Keep the rule in place after Nextcloud upgrades (checked right after an app or Nextcloud update, plus once a day)') }}
			</NcCheckboxRadioSwitch>
			<details class="shortcloud-admin__details">
				<summary>{{ t('shortcloud', 'Rule for Apache (.htaccess) and nginx') }}</summary>
				<pre>{{ rewrite.block }}</pre>
				<pre>{{ rewrite.nginx }}</pre>
			</details>

			<h3>{{ t('shortcloud', 'Pretty URLs') }}</h3>
			<p class="shortcloud-admin__text">
				{{ t('shortcloud', 'Nextcloud can hide "/index.php" from every address it produces (share links become {short} instead of {long}). Old addresses with /index.php keep working. This is Nextcloud\'s own setting htaccess.RewriteBase, applied with a regenerated .htaccess; it needs Apache with mod_rewrite and AllowOverride All.', { short: '/s/…', long: '/index.php/s/…' }) }}
			</p>
			<NcCheckboxRadioSwitch :model-value="prettyUrls.configured" type="switch" :disabled="busy || !prettyUrls.supported" @update:model-value="setPrettyUrls">
				{{ t('shortcloud', 'Hide /index.php from addresses (pretty URLs)') }}
			</NcCheckboxRadioSwitch>
			<p v-if="!prettyUrls.supported" class="shortcloud-admin__text">
				{{ t('shortcloud', '.htaccess is not writable, so this switch is disabled. Set htaccess.RewriteBase in config.php and run "occ maintenance:update:htaccess" instead.') }}
			</p>
			<p v-else-if="prettyUrls.configured && !prettyUrls.active" class="shortcloud-admin__text">
				{{ t('shortcloud', 'htaccess.RewriteBase is set but .htaccess has no front-controller rules; run "occ maintenance:update:htaccess".') }}
			</p>

			<h3>{{ t('shortcloud', 'Short links') }}</h3>
			<NcCheckboxRadioSwitch v-model="settings.paused" type="switch" :disabled="busy" @update:model-value="save({ paused: $event })">
				{{ t('shortcloud', 'Pause: no new short links can be made, existing ones keep working') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="settings.autoCreate" type="switch" :disabled="busy" @update:model-value="save({ autoCreate: $event })">
				{{ t('shortcloud', 'Create a short link automatically for every new public link share') }}
			</NcCheckboxRadioSwitch>
			<div class="shortcloud-admin__row">
				<NcTextField v-model="settings.prefix"
					:label="t('shortcloud', 'Path prefix on {host}', { host: settings.defaultHost })"
					:helper-text="t('shortcloud', 'Short links look like {url}', { url: exampleUrl })"
					@blur="savePrefix" />
				<NcTextField v-model.number="settings.slugLength"
					type="number"
					min="4"
					max="32"
					:label="t('shortcloud', 'Length of random endings')"
					@blur="save({ slugLength: settings.slugLength })" />
			</div>
			<NcSelect v-model="creatorGroups"
				:options="groups"
				:input-label="t('shortcloud', 'Who may create short links (empty: everyone)')"
				:placeholder="t('shortcloud', 'Everyone')"
				label="name"
				multiple
				:close-on-select="false"
				@update:model-value="saveGroups" />
			<NcTextArea v-model="reservedText"
				:label="t('shortcloud', 'Reserved endings, one per line (never handed out)')"
				rows="2"
				@blur="save({ reserved: lines(reservedText) })" />

			<h3>{{ t('shortcloud', 'Targets') }}</h3>
			<p class="shortcloud-admin__text">
				{{ t('shortcloud', 'A short link to any site would turn your domain into an open redirector for phishing. Share links are always allowed; choose what else is.') }}
			</p>
			<fieldset class="shortcloud-admin__radios">
				<NcCheckboxRadioSwitch v-model="settings.externalTargets" type="radio" value="none" name="externalTargets" @update:model-value="save({ externalTargets: $event })">
					{{ t('shortcloud', 'Only addresses on this Nextcloud') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="settings.externalTargets" type="radio" value="list" name="externalTargets" @update:model-value="save({ externalTargets: $event })">
					{{ t('shortcloud', 'This Nextcloud and the hosts listed below') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="settings.externalTargets" type="radio" value="any" name="externalTargets" @update:model-value="save({ externalTargets: $event })">
					{{ t('shortcloud', 'Any address') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextArea v-if="settings.externalTargets === 'list'"
				v-model="allowedText"
				:label="t('shortcloud', 'Allowed hosts, one per line (sub-domains included)')"
				rows="3"
				@blur="save({ allowedHosts: lines(allowedText) })" />

			<h3>{{ t('shortcloud', 'Custom short domains') }}</h3>
			<p class="shortcloud-admin__text">
				{{ t('shortcloud', 'A dedicated domain such as {example} gives the shortest links. Point its DNS to this server, give it a certificate and add the virtual host shown after saving; nothing else of Nextcloud is exposed on it and it does not need to be a trusted domain.', { example: 'https://cc.link/abc1234' }) }}
			</p>
			<table v-if="settings.domains.length" class="shortcloud-admin__domains">
				<thead>
					<tr>
						<th>{{ t('shortcloud', 'Host') }}</th>
						<th>{{ t('shortcloud', 'Prefix (optional)') }}</th>
						<th>{{ t('shortcloud', 'Scheme') }}</th>
						<th>{{ t('shortcloud', 'Status') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(d, i) in settings.domains" :key="d.host">
						<td><code>{{ d.host }}</code></td>
						<td>{{ d.prefix || '—' }}</td>
						<td>{{ d.scheme }}</td>
						<td>
							<span v-if="domainProbe[d.host] === true">✔ {{ t('shortcloud', 'answers') }}</span>
							<span v-else-if="domainProbe[d.host] === false">✖ {{ t('shortcloud', 'no answer') }}</span>
							<NcButton v-else variant="tertiary" @click="probeDomain(d.host)">
								{{ t('shortcloud', 'Test') }}
							</NcButton>
						</td>
						<td>
							<NcButton variant="tertiary" :aria-label="t('shortcloud', 'Remove')" @click="removeDomain(Number(i))">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<div class="shortcloud-admin__row">
				<NcTextField v-model="newDomain.host" :label="t('shortcloud', 'Host name')" placeholder="cc.link" />
				<NcTextField v-model="newDomain.prefix" :label="t('shortcloud', 'Prefix (optional)')" placeholder="" />
				<NcButton :disabled="busy || !newDomain.host.trim()" @click="addDomain">
					{{ t('shortcloud', 'Add domain') }}
				</NcButton>
			</div>
			<details v-for="d in rewrite.customDomains" :key="'vh-' + d.host" class="shortcloud-admin__details">
				<summary>{{ t('shortcloud', 'Apache virtual host for {host}', { host: d.host }) }}</summary>
				<pre>{{ d.vhost }}</pre>
			</details>

			<h3>{{ t('shortcloud', 'Command line') }}</h3>
			<pre class="shortcloud-admin__pre">occ shortcloud:setup            {{ t('shortcloud', '# install / check the rule') }}
occ shortcloud:setup --pretty-urls=on
occ shortcloud:backfill --dry-run {{ t('shortcloud', '# short links for shares made before the app') }}
occ shortcloud:add https://… --user admin --slug my-name</pre>
		</NcSettingsSection>
	</div>
</template>

<script setup lang="ts">
import { showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Delete from 'vue-material-design-icons/Delete.vue'
import { computed, reactive, ref } from 'vue'
import { adminInstallRewrite, adminRemoveRewrite, adminRewriteStatus, adminSave, adminSetPrettyUrls, errorMessage, probe } from '../api'

const props = defineProps<{ state: any }>()

const settings = reactive({ ...props.state.settings })
const rewrite = ref({ ...props.state.rewrite })
const prettyUrls = ref({ ...props.state.prettyUrls })
const groups = ref<{ id: string, name: string }[]>(props.state.groups)
const creatorGroups = ref(groups.value.filter((g) => settings.creatorGroups.includes(g.id)))
const reservedText = ref((settings.reserved as string[]).join('\n'))
const allowedText = ref((settings.allowedHosts as string[]).join('\n'))
const newDomain = reactive({ host: '', prefix: '' })
const busy = ref(false)
const error = ref('')
const probeResult = ref<boolean | null>(null)
const domainProbe = reactive<Record<string, boolean | null>>({})
let lastPrefix = settings.prefix

const exampleUrl = computed(() => `${settings.defaultScheme}://${settings.defaultHost}/${settings.prefix}/abc1234`)

function lines(text: string): string[] {
	return text.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean)
}

function apply(data: any) {
	Object.assign(settings, data.settings)
	rewrite.value = data.rewrite
	if (data.prettyUrls) {
		prettyUrls.value = data.prettyUrls
	}
}

async function save(partial: Record<string, unknown>) {
	busy.value = true
	error.value = ''
	try {
		apply(await adminSave(partial))
		showSuccess(t('shortcloud', 'Saved'))
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not save'))
	} finally {
		busy.value = false
	}
}

async function savePrefix() {
	if (settings.prefix === lastPrefix) {
		return
	}
	await save({ prefix: settings.prefix })
	lastPrefix = settings.prefix
	probeResult.value = null
}

function saveGroups(value: { id: string }[]) {
	save({ creatorGroups: value.map((g) => g.id) })
}

async function addDomain() {
	const domains = [...settings.domains, { host: newDomain.host.trim(), prefix: newDomain.prefix.trim(), scheme: 'https' }]
	await save({ domains })
	if (!error.value) {
		newDomain.host = ''
		newDomain.prefix = ''
	}
}

async function removeDomain(index: number) {
	const domains = settings.domains.filter((_: unknown, i: number) => i !== index)
	await save({ domains })
}

async function installRewrite() {
	busy.value = true
	error.value = ''
	try {
		apply(await adminInstallRewrite())
		showSuccess(t('shortcloud', 'Rewrite rule installed'))
		await runProbe()
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not write .htaccess'))
	} finally {
		busy.value = false
	}
}

async function removeRewrite() {
	busy.value = true
	error.value = ''
	try {
		apply(await adminRemoveRewrite())
		probeResult.value = null
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not write .htaccess'))
	} finally {
		busy.value = false
	}
}

async function runProbe() {
	probeResult.value = await probe(rewrite.value.pingUrl)
	try {
		apply(await adminRewriteStatus(true))
	} catch {
		// the browser-side result is still shown
	}
}

async function probeDomain(host: string) {
	const d = rewrite.value.customDomains.find((x: any) => x.host === host)
	domainProbe[host] = d ? await probe(d.pingUrl) : false
}

async function setPrettyUrls(enabled: boolean) {
	busy.value = true
	error.value = ''
	try {
		apply(await adminSetPrettyUrls(enabled))
		showSuccess(enabled ? t('shortcloud', 'Pretty URLs enabled') : t('shortcloud', 'Pretty URLs disabled'))
	} catch (e) {
		error.value = errorMessage(e, t('shortcloud', 'Could not change pretty URLs'))
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.shortcloud-admin h3 {
	margin: 28px 0 8px;
	font-weight: bold;
}
.shortcloud-admin__text {
	color: var(--color-text-maxcontrast);
	max-width: 800px;
	margin-bottom: 8px;
}
.shortcloud-admin__buttons {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin: 8px 0;
}
.shortcloud-admin__row {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	flex-wrap: wrap;
	max-width: 800px;
	margin: 8px 0;
}
.shortcloud-admin__row > * {
	flex: 1 1 220px;
}
.shortcloud-admin__radios {
	display: flex;
	flex-direction: column;
}
.shortcloud-admin__details {
	margin: 8px 0;
	max-width: 800px;
}
.shortcloud-admin__details pre,
.shortcloud-admin__pre {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	overflow-x: auto;
	font-family: monospace;
	white-space: pre;
	margin: 6px 0;
}
.shortcloud-admin__domains {
	border-collapse: collapse;
	margin: 8px 0;
	max-width: 800px;
}
.shortcloud-admin__domains th,
.shortcloud-admin__domains td {
	text-align: start;
	padding: 4px 12px 4px 0;
	border-bottom: 1px solid var(--color-border);
}
.shortcloud-admin :deep(.v-select) {
	max-width: 800px;
}
.shortcloud-admin :deep(textarea) {
	max-width: 800px;
}
</style>
