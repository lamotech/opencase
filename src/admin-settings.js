import { createApp } from 'vue'
import AdminSettings from './AdminSettings.vue'

const app = createApp(AdminSettings)
app.mixin({ methods: { t, n } })
app.mount('#opencase-admin-settings')
