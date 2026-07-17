<template>
	<div class="opencase-doc-field-search">
		<h2>{{ t('opencase', 'Søg i dokumenter') }}</h2>

		<!-- Filter form -->
		<div class="ocfs-docs__form">
			<div class="ocfs-docs__form-grid">
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Titel') }}</label>
					<NcTextField v-model="form.title"
						:placeholder="t('opencase', 'Dokumenttitel…')"
						@keyup.enter="doSearch" />
				</div>
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Dokumenttype') }}</label>
					<NcSelect v-model="form.documentType"
						:placeholder="t('opencase', 'Alle typer')"
						:options="documentTypeOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Status') }}</label>
					<NcSelect v-model="form.status"
						:placeholder="t('opencase', 'Alle statusser')"
						:options="statusOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="form.organisation"
						:placeholder="t('opencase', 'Alle organisationer')"
						:options="organisationOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Dokumentdato fra') }}</label>
					<input v-model="form.dateFrom"
						type="date"
						class="ocfs-docs__date-input"
						@keyup.enter="doSearch">
				</div>
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Dokumentdato til') }}</label>
					<input v-model="form.dateTo"
						type="date"
						class="ocfs-docs__date-input"
						@keyup.enter="doSearch">
				</div>
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Dokumentkategori') }}</label>
					<NcSelect v-model="form.documentCategory"
						:placeholder="t('opencase', 'Alle kategorier')"
						:options="documentCategoryOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-docs__field">
					<label class="ocfs-docs__label">{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="form.insightLevel"
						:placeholder="t('opencase', 'Alle indsigtsgrader')"
						:options="insightLevelOptions"
						:clearable="true" />
				</div>
			</div>
			<NcButton type="primary" @click="doSearch">
				<template #icon>
					<MagnifyIcon :size="20" />
				</template>
				{{ t('opencase', 'Søg') }}
			</NcButton>
		</div>

		<!-- Loading -->
		<NcLoadingIcon v-if="loading" :size="44" class="ocfs-docs__loading" />

		<!-- Empty state after search -->
		<NcEmptyContent v-else-if="searched && documents.length === 0"
			:title="t('opencase', 'Ingen dokumenter fundet')"
			:description="t('opencase', 'Prøv at ændre søgekriterierne')">
			<template #icon>
				<MagnifyIcon :size="48" />
			</template>
		</NcEmptyContent>

		<!-- Results -->
		<template v-else-if="documents.length > 0">
			<div class="ocfs-docs__result-meta">
				{{ t('opencase', '{start}–{end} af {total} dokumenter', {
					start: offset + 1,
					end: Math.min(offset + limit, effectiveTotal),
					total: effectiveTotal,
				}) }}
			</div>
			<table class="ocfs-docs__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Titel') }}</th>
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'Sag') }}</th>
						<th>{{ t('opencase', 'Organisation') }}</th>
						<th>{{ t('opencase', 'Dokumentdato') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="doc in documents"
						:key="doc.id"
						class="ocfs-docs__row"
						@click="$router.push({ name: 'document-detail', params: { id: String(doc.id) } })">
						<td class="ocfs-docs__title">{{ doc.title }}</td>
						<td>
							<span v-if="doc.document_type" class="ocfs-docs__type-badge">
								{{ doc.document_type }}
							</span>
						</td>
						<td class="ocfs-docs__case">
							<span class="ocfs-docs__case-number">{{ doc.case_number }}</span>
							<span class="ocfs-docs__case-title">{{ doc.case_title }}</span>
						</td>
						<td>{{ doc.org_name }}</td>
						<td class="ocfs-docs__date">{{ formatDate(doc.document_date) }}</td>
					</tr>
				</tbody>
			</table>

			<!-- Pagination -->
			<div v-if="effectiveTotal > limit" class="ocfs-docs__pagination">
				<NcButton :disabled="offset === 0" @click="prevPage">
					{{ t('opencase', 'Forrige') }}
				</NcButton>
				<span class="ocfs-docs__page-info">
					{{ t('opencase', 'Side {page}', { page: Math.floor(offset / limit) + 1 }) }}
				</span>
				<NcButton :disabled="offset + limit >= effectiveTotal" @click="nextPage">
					{{ t('opencase', 'Næste') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

import api from '../services/api.js'

export default {
	name: 'DocumentFieldSearchView',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		MagnifyIcon,
	},

	data() {
		return {
			form: {
				title: '',
				documentType: null,
				status: null,
				organisation: null,
				dateFrom: '',
				dateTo: '',
				documentCategory: null,
				insightLevel: null,
			},
			documentCategories: [],
			documents: [],
			total: 0,
			offset: 0,
			loading: false,
			searched: false,
		}
	},

	computed: {
		limit() {
			return this.$store.state.searchPageSize
		},
		effectiveTotal() {
			return Math.min(this.total, this.$store.state.searchMaxResultCount)
		},
		organisationOptions() {
			return this.$store.state.organisations.map(o => ({ id: o, label: o }))
		},
		statusOptions() {
			return this.$store.state.documentStatuses.map(s => ({ id: s.id, label: s.name }))
		},
		documentTypeOptions() {
			return [
				{ id: 'afgørelse', label: t('opencase', 'Afgørelse') },
				{ id: 'notat', label: t('opencase', 'Notat') },
				{ id: 'brev', label: t('opencase', 'Brev') },
				{ id: 'bilag', label: t('opencase', 'Bilag') },
			]
		},
		documentCategoryOptions() {
			return this.documentCategories.map(c => ({ id: c.id, label: c.name }))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({ id: l.id, label: l.name }))
		},
	},

	mounted() {
		this.$store.dispatch('fetchDocumentStatuses').catch(() => {})
		this.$store.dispatch('fetchInsightLevels').catch(() => {})
		api.getDocumentCategories().then(categories => {
			this.documentCategories = categories || []
		}).catch(() => {})
	},

	methods: {
		async doSearch() {
			this.offset = 0
			await this.fetchResults()
		},

		async fetchResults() {
			this.loading = true
			this.searched = true
			try {
				const params = { limit: this.limit, offset: this.offset }
				if (this.form.title.trim()) params.title = this.form.title.trim()
				if (this.form.documentType) params.document_type = this.form.documentType.id
				if (this.form.status) params.status = this.form.status.id
				if (this.form.organisation) params.organisation = this.form.organisation.id
				if (this.form.dateFrom) params.date_from = this.form.dateFrom
				if (this.form.dateTo) params.date_to = this.form.dateTo
				if (this.form.documentCategory) params.document_category_id = this.form.documentCategory.id
				if (this.form.insightLevel) params.insight_level_id = this.form.insightLevel.id

				const result = await api.searchDocuments(params)
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
.opencase-doc-field-search {
	padding: 20px;
	max-width: 1200px;
}

.opencase-doc-field-search h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
	font-weight: 700;
}

.ocfs-docs__form {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ocfs-docs__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
}

.ocfs-docs__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocfs-docs__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocfs-docs__date-input {
	height: 34px;
	padding: 0 8px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	width: 100%;
	box-sizing: border-box;
}

.ocfs-docs__date-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.ocfs-docs__loading {
	margin: 60px auto;
}

.ocfs-docs__result-meta {
	font-size: 0.9em;
	color: var(--color-text-lighter);
	margin-bottom: 10px;
}

.ocfs-docs__table {
	width: 100%;
	border-collapse: collapse;
}

.ocfs-docs__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ocfs-docs__row {
	cursor: pointer;
	transition: background 0.15s;
}

.ocfs-docs__row:hover {
	background: var(--color-background-hover);
}

.ocfs-docs__row td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: top;
}

.ocfs-docs__title {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 500;
}

.ocfs-docs__type-badge {
	font-size: 0.78em;
	font-weight: 600;
	letter-spacing: 0.03em;
	text-transform: uppercase;
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
}

.ocfs-docs__case {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ocfs-docs__case-number {
	font-family: var(--font-monospace, monospace);
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-primary-element);
}

.ocfs-docs__case-title {
	font-size: 0.82em;
	color: var(--color-text-lighter);
}

.ocfs-docs__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
}

.ocfs-docs__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 20px;
}

.ocfs-docs__page-info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}
</style>
