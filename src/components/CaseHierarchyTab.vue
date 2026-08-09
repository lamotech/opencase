<template>
	<div class="case-hierarchy">
		<NcLoadingIcon v-if="loading" :size="32" class="case-hierarchy__loading" />

		<NcEmptyContent v-else-if="!tree"
			:title="t('opencase', 'Ikke del af et hierarki')"
			:description="t('opencase', 'Denne sag har ingen oversag eller undersager.')">
			<template #icon>
				<SitemapIcon :size="48" />
			</template>
		</NcEmptyContent>

		<div v-else class="case-hierarchy__tree">
			<CaseHierarchyNode :node="tree" />
		</div>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'

import CaseHierarchyNode from './CaseHierarchyNode.vue'
import api from '../services/api.js'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'CaseHierarchyTab',

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		SitemapIcon,
		CaseHierarchyNode,
	},

	props: {
		caseId: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			tree: null,
		}
	},

	watch: {
		caseId: {
			immediate: true,
			handler() { this.load() },
		},
	},

	methods: {
		async load() {
			this.loading = true
			this.tree = null
			try {
				this.tree = await api.getCaseHierarchy(this.caseId)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse sagshierarki'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.case-hierarchy {
	padding: 8px 0;
}

.case-hierarchy__loading {
	margin: 40px auto;
}

.case-hierarchy__tree {
	padding: 4px 0;
}
</style>
