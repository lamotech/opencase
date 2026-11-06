<template>
	<div class="brevdesigner">
		<h2>{{ t('opencase', 'Administrer brevdesigner') }}</h2>

		<OverflowTabs :tabs="tabs" :value="activeTab" @input="selectTab" />

		<div class="brevdesigner__content">
			<PhraseCategoriesTab v-if="activeTab === 'categories'" />
			<PhrasesTab v-else-if="activeTab === 'phrases'" />
			<TemplatesTab v-else-if="activeTab === 'templates'" />
		</div>
	</div>
</template>

<script>
import OverflowTabs from '../components/OverflowTabs.vue'
import PhraseCategoriesTab from '../components/PhraseCategoriesTab.vue'
import PhrasesTab from '../components/PhrasesTab.vue'
import TemplatesTab from '../components/TemplatesTab.vue'

const TAB_IDS = ['categories', 'phrases', 'templates']

/**
 * Brevdesigner administration: phrase categories, phrases and templates.
 * The active tab is kept in the ?tab= query parameter so it survives reloads.
 */
export default {
	name: 'TemplateManagerView',

	components: { OverflowTabs, PhraseCategoriesTab, PhrasesTab, TemplatesTab },

	data() {
		return {
			tabs: [
				{ id: 'categories', label: t('opencase', 'Frasekategorier') },
				{ id: 'phrases', label: t('opencase', 'Fraser') },
				{ id: 'templates', label: t('opencase', 'Skabeloner') },
			],
		}
	},

	computed: {
		activeTab() {
			const tab = this.$route.query.tab
			return TAB_IDS.includes(tab) ? tab : TAB_IDS[0]
		},
	},

	methods: {
		selectTab(id) {
			if (id !== this.activeTab) {
				this.$router.replace({ query: { ...this.$route.query, tab: id } })
			}
		},
	},
}
</script>

<style scoped>
.brevdesigner {
	padding: 20px;
}

.brevdesigner__content {
	max-width: 1100px;
}
</style>
