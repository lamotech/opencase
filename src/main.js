import { createApp } from 'vue'
import App from './App.vue'
import router from './router/index.js'
import store from './store/index.js'

const app = createApp(App)
app.mixin({ methods: { t, n } })
app.use(router)
app.use(store)
app.mount('#content')
