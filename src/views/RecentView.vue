<template>
	<div class="opencase-recent">
		<h2>{{ pageTitle }}</h2>

		<NcLoadingIcon v-if="loading" :size="44" class="opencase-recent__loading" />

		<NcEmptyContent v-else-if="visibleCases.length === 0 && visibleDocuments.length === 0"
			:title="t('opencase', 'Ingen nylige aktiviteter')"
			:description="t('opencase', 'Sager og dokumenter du har åbnet vises her.')">
			<template #icon>
				<HistoryIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Cases -->
			<section v-if="visibleCases.length > 0" class="opencase-recent__section">
				<h3 class="opencase-recent__section-title">
					<FolderIcon :size="18" />
					{{ t('opencase', 'Sager') }}
				</h3>
				<table class="opencase-recent__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Sagsnummer') }}</th>
							<th>{{ t('opencase', 'Sagstype') }}</th>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Organisation') }}</th>
							<th>{{ t('opencase', 'Status') }}</th>
							<th>{{ t('opencase', 'Senest tilgået') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="c in visibleCases"
							:key="c.id"
							class="opencase-recent__row"
							@click="$router.push({ name: 'case-detail', params: { id: c.id } })">
							<td class="opencase-recent__mono">{{ c.case_number }}</td>
							<td class="opencase-recent__lighter">{{ c.casetype_name }}</td>
							<td class="opencase-recent__title">{{ c.title }}</td>
							<td class="opencase-recent__lighter">{{ c.organisation }}</td>
							<td>
								<CaseStatusBadge :status="c.status_class" :label="c.status_name" />
							</td>
							<td class="opencase-recent__lighter opencase-recent__nowrap">{{ formatDate(c.accessed_at) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- Documents -->
			<section v-if="visibleDocuments.length > 0" class="opencase-recent__section">
				<h3 class="opencase-recent__section-title">
					<FileDocumentOutlineIcon :size="18" />
					{{ t('opencase', 'Dokumenter') }}
				</h3>
				<table class="opencase-recent__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Dokumentnummer') }}</th>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Type') }}</th>
							<th>{{ t('opencase', 'Sag') }}</th>
							<th>{{ t('opencase', 'Senest tilgået') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="doc in visibleDocuments"
							:key="doc.id"
							class="opencase-recent__row"
							@click="$router.push({ name: 'document-detail', params: { id: String(doc.id) } })">
							<td class="opencase-recent__mono">{{ doc.document_number }}</td>
							<td class="opencase-recent__title">{{ doc.title }}</td>
							<td class="opencase-recent__lighter">{{ doc.document_type }}</td>
							<td class="opencase-recent__mono">{{ doc.case_number }}</td>
							<td class="opencase-recent__lighter opencase-recent__nowrap">{{ formatDate(doc.accessed_at) }}</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import HistoryIcon from 'vue-material-design-icons/History.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import api from '../services/api.js'

export default {
	name: 'RecentView',

	props: {
		entity: {
			type: String,
			default: null,
		},
	},

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		HistoryIcon,
		FolderIcon,
		FileDocumentOutlineIcon,
		CaseStatusBadge,
	},

	data() {
		return {
			loading: true,
			cases: [],
			documents: [],
		}
	},

	computed: {
		entityFilter() {
			return this.entity
		},
		visibleCases() {
			return this.entityFilter === 'document' ? [] : this.cases
		},
		visibleDocuments() {
			return this.entityFilter === 'case' ? [] : this.documents
		},
		pageTitle() {
			if (this.entityFilter === 'case') return t('opencase', 'Seneste sager')
			if (this.entityFilter === 'document') return t('opencase', 'Seneste dokumenter')
			return t('opencase', 'Seneste')
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			try {
				const recent = await api.getRecent()
				const caseEntries = recent.filter(e => e.entity === 'case')
				const docEntries  = recent.filter(e => e.entity === 'document')

				const [caseResults, docResults] = await Promise.all([
					Promise.allSettled(caseEntries.map(e => api.getCase(e.key, { audit: false }))),
					Promise.allSettled(docEntries.map(e => api.getDocument(e.key, { audit: false }))),
				])

				const caseAccessedAt = Object.fromEntries(caseEntries.map(e => [e.key, e.accessed_at]))
				const docAccessedAt  = Object.fromEntries(docEntries.map(e => [e.key, e.accessed_at]))

				this.cases = caseResults
					.filter(r => r.status === 'fulfilled')
					.map(r => ({ ...r.value, accessed_at: caseAccessedAt[r.value.id] }))
					.sort((a, b) => (b.accessed_at ?? '') > (a.accessed_at ?? '') ? 1 : -1)

				this.documents = docResults
					.filter(r => r.status === 'fulfilled')
					.map(r => ({ ...r.value, accessed_at: docAccessedAt[r.value.id] }))
					.sort((a, b) => (b.accessed_at ?? '') > (a.accessed_at ?? '') ? 1 : -1)
			} finally {
				this.loading = false
			}
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-recent {
	padding: 20px;
	max-width: 1000px;
}

.opencase-recent h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
}

.opencase-recent__loading {
	margin: 60px auto;
}

.opencase-recent__section {
	margin-bottom: 36px;
}

.opencase-recent__section-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin: 0 0 10px;
}

.opencase-recent__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-recent__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
}

.opencase-recent__row {
	cursor: pointer;
}

.opencase-recent__row:hover {
	background: var(--color-background-hover);
}

.opencase-recent__row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: middle;
}

.opencase-recent__mono {
	font-family: var(--font-monospace, monospace);
	font-size: 0.85em;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.opencase-recent__title {
	font-weight: 500;
}

.opencase-recent__lighter {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.opencase-recent__nowrap {
	white-space: nowrap;
}
</style>
