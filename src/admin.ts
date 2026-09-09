import { loadState } from '@nextcloud/initial-state'
import { createApp } from 'vue'
import Admin from './views/Admin.vue'

const state = loadState<any>('shortcloud', 'admin')
createApp(Admin, { state }).mount('#shortcloud-admin')
