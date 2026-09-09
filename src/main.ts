import { loadState } from '@nextcloud/initial-state'
import { createApp } from 'vue'
import App from './views/App.vue'
import type { UserConfig } from './types'

const config = loadState<UserConfig>('shortcloud', 'config')
createApp(App, { config }).mount('#shortcloud')
