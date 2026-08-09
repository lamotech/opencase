<template>
	<div class="opencase-my-documents">
		<h2>{{ t('opencase', 'Mine dokumenter') }}</h2>

		<NcLoadingIcon v-if="loading" :size="44" class="opencase-my-documents__loading" />

		<NcEmptyContent v-else-if="documents.length === 0"
			:title="t('opencase', 'Ingen dokumenter endnu')"
			:description="t('opencase', 'Dokumenter du har oprettet vises her.')">
			<template #icon>
				<FileDocumentOutlineIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="opencase-my-documents__meta">
				{{ t('opencase', '{start}–{end} af {total} dokumenter', {
					start: offset + 1,
					end: Math.min(offset + limit, total),
					total,
				}) }}
			</div>
			<table class="opencase-my-documents__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Dokumentnummer') }}</th>
						<th>{{ t('opencase', 'Titel') }}</th>
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'Sag') }}</th>
						<th>{{ t('opencase', 'Opdateret') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="doc in documents"
						:key="doc.id"
						class="opencase-my-documents__row"
						@click="$router.push({ name: 'document-detail', params: { id: String(doc.id) } })">
						<td class="opencase-my-documents__mono">{{ doc.document_number }}</td>
						<td class="opencase-my-documents__title">{{ doc.title }}</td>
						<td class="opencase-my-documents__lighter">{{ doc.document_type }}</td>
						<td class="opencase-my-documents__mono">{{ doc.case_number }}</td>
						<td class="opencase-my-documents__lighter opencase-my-documents__nowrap">{{ formatDate(doc.updated_at) }}</td>
					</tr>
				</tbody>
			</table>

			<div v-if="total > limit" class="opencase-my-documents__pagination">
				<NcButton :disabled="offset === 0" @click="prevPage">
					{{ t('opencase', 'Forrige') }}
				</NcButton>
				<span class="opencase-my-documents__page-info">
					{{ t('opencase', 'Side {page}', { page: Math.floor(offset / limit) + 1 }) }}
				</span>
				<NcButton :disabled="offset + limit >= total" @click="nextPage">
					{{ t('opencase', 'Næste') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

import api from '../services/api.js'

export default {
	name: 'MyDocumentsView',

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		FileDocumentOutlineIcon,
	},

	data() {
		return {
			documents: [],
			total: 0,
			limit: 50,
			offset: 0,
			loading: true,
		}
	},

	async mounted() {
		await this.fetchResults()
	},

	methods: {
		async fetchResults() {
			this.loading = true
			try {
				const result = await api.searchDocuments({
					created_by_me: 1,
					limit: this.limit,
					offset: this.offset,
				})
				this.documents = result.documents || []
				this.total = result.total || 0
			} finally {
				this.loading = false
			}
		},

		prevPage() {
			this.offset = Math.max(0, this.offset - this.limit)
			this.fetchResults()
		},

		nextPage() {
			this.offset += this.limit
			this.fetchResults()
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
.opencase-my-documents {
	padding: 20px;
	max-width: 1000px;
}

.opencase-my-documents h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
}

.opencase-my-documents__loading {
	margin: 60px auto;
}

.opencase-my-documents__meta {
	font-size: 0.9em;
	color: var(--color-text-lighter);
	margin-bottom: 10px;
}

.opencase-my-documents__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-my-documents__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
}

.opencase-my-documents__row {
	cursor: pointer;
}

.opencase-my-documents__row:hover {
	background: var(--color-background-hover);
}

.opencase-my-documents__row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: middle;
}

.opencase-my-documents__mono {
	font-family: var(--font-monospace, monospace);
	font-size: 0.85em;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.opencase-my-documents__title {
	font-weight: 500;
}

.opencase-my-documents__lighter {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.opencase-my-documents__nowrap {
	white-space: nowrap;
}

.opencase-my-documents__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 20px;
}

.opencase-my-documents__page-info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}
</style>
