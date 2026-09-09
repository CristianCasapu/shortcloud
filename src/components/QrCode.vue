<template>
	<div class="shortcloud-qr">
		<img v-if="dataUrl" :src="dataUrl" :alt="text" width="180" height="180">
		<a v-if="dataUrl" :href="dataUrl" :download="fileName">{{ t('shortcloud', 'Download QR code') }}</a>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import QRCode from 'qrcode'
import { computed, ref, watch } from 'vue'

const props = defineProps<{ text: string }>()
const dataUrl = ref('')
const fileName = computed(() => 'shortcloud-' + props.text.replace(/^https?:\/\//, '').replace(/[^A-Za-z0-9._-]+/g, '_') + '.png')

watch(() => props.text, async (text) => {
	dataUrl.value = text ? await QRCode.toDataURL(text, { width: 360, margin: 1, errorCorrectionLevel: 'M' }) : ''
}, { immediate: true })
</script>

<style scoped>
.shortcloud-qr {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	padding: 8px;
}
.shortcloud-qr img {
	border-radius: var(--border-radius-large);
	background: #fff;
	padding: 6px;
}
</style>
