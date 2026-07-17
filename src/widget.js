import { createApp } from 'vue'
import MyCasesWidget from './components/MyCasesWidget.vue'
import InboundDocumentsWidget from './components/InboundDocumentsWidget.vue'
import FavoritesWidget from './components/FavoritesWidget.vue'

function mountWidget(component, el) {
	const app = createApp(component)
	app.mixin({ methods: { t, n } })
	app.mount(el)
}

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('opencase_my_cases', (el) => {
		mountWidget(MyCasesWidget, el)
	})

	OCA.Dashboard.register('opencase_inbound_documents', (el) => {
		mountWidget(InboundDocumentsWidget, el)
	})

	OCA.Dashboard.register('opencase_favorites', (el) => {
		mountWidget(FavoritesWidget, el)
	})
})
