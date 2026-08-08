<template>
	<div class="opencase-case-field-search">
		<h2>{{ t('opencase', 'Søg i sager') }}</h2>

		<!-- Filter form -->
		<div class="ocfs-cases__form">
			<div class="ocfs-cases__form-grid">
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Søgetekst') }}</label>
					<NcTextField v-model="form.search"
						:placeholder="t('opencase', 'Sagsnummer eller titel…')"
						@keyup.enter="doSearch" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="form.organisation"
						:placeholder="t('opencase', 'Alle organisationer')"
						:options="organisationOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'År') }}</label>
					<NcTextField v-model="form.year"
						:placeholder="t('opencase', 'f.eks. 2024')"
						@keyup.enter="doSearch" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Status') }}</label>
					<NcSelect v-model="form.status"
						:placeholder="t('opencase', 'Alle statusser')"
						:options="statusOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Sagstype') }}</label>
					<NcSelect v-model="form.casetype"
						:placeholder="t('opencase', 'Alle sagstyper')"
						:options="casetypeOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'KLE-nummer') }}</label>
					<NcTextField v-model="form.classificationCode"
						:placeholder="t('opencase', 'f.eks. 27.69.00')"
						@keyup.enter="doSearch" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Følsomhed') }}</label>
					<NcSelect v-model="form.sensitivity"
						:placeholder="t('opencase', 'Alle følsomhedsniveauer')"
						:options="sensitivityOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Handlingsfacet') }}</label>
					<NcSelect v-model="form.classificationFacet"
						:placeholder="t('opencase', 'Alle handlingsfacetter')"
						:options="facetOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="form.insightLevel"
						:placeholder="t('opencase', 'Alle indsigtsgrader')"
						:options="insightLevelOptions"
						:clearable="true" />
				</div>
				<div class="ocfs-cases__field">
					<label class="ocfs-cases__label">{{ t('opencase', 'Ansvarlig') }}</label>
					<NcSelect v-model="form.responsible"
						:placeholder="t('opencase', 'Søg bruger…')"
						:options="userOptions"
						:filterable="false"
						:loading="userSearchLoading"
						:clearable="true"
						@search="onUserSearch" />
				</div>
			</div>
			<div class="ocfs-cases__form-actions">
				<NcButton type="primary" @click="doSearch">
					<template #icon>
						<MagnifyIcon :size="20" />
					</template>
					{{ t('opencase', 'Søg') }}
				</NcButton>
				<NcButton v-if="hasActiveFilters" @click="resetForm">
					{{ t('opencase', 'Nulstil') }}
				</NcButton>
			</div>
		</div>

		<!-- Loading -->
		<NcLoadingIcon v-if="loading" :size="44" class="ocfs-cases__loading" />

		<!-- Empty state after search -->
		<NcEmptyContent v-else-if="searched && cases.length === 0"
			:title="t('opencase', 'Ingen sager fundet')"
			:description="t('opencase', 'Prøv at ændre søgekriterierne')">
			<template #icon>
				<MagnifyIcon :size="48" />
			</template>
		</NcEmptyContent>

		<!-- Results -->
		<template v-else-if="cases.length > 0">
			<div class="ocfs-cases__result-meta">
				{{ t('opencase', '{start}–{end} af {total} sager', {
					start: offset + 1,
					end: Math.min(offset + limit, effectiveTotal),
					total: effectiveTotal,
				}) }}
			</div>
			<table class="ocfs-cases__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Sagsnummer') }}</th>
						<th>{{ t('opencase', 'Sagstype') }}</th>
						<th>{{ t('opencase', 'Titel') }}</th>
						<th>{{ t('opencase', 'Organisation') }}</th>
						<th>{{ t('opencase', 'KLE') }}</th>
						<th>{{ t('opencase', 'Status') }}</th>
						<th>{{ t('opencase', 'Opdateret') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="c in cases"
						:key="c.id"
						class="ocfs-cases__row"
						@click="$router.push({ name: 'case-detail', params: { id: c.id } })">
						<td class="ocfs-cases__number">{{ c.case_number }}</td>
						<td>{{ c.casetype_name }}</td>
						<td class="ocfs-cases__title">{{ c.title }}</td>
						<td>{{ c.organisation }}</td>
						<td class="ocfs-cases__kle">{{ c.classification_code }}</td>
						<td><CaseStatusBadge :status="c.status_class" :label="c.status_name" /></td>
						<td class="ocfs-cases__date">{{ formatDate(c.updated_at) }}</td>
					</tr>
				</tbody>
			</table>

			<!-- Pagination -->
			<div v-if="effectiveTotal > limit" class="ocfs-cases__pagination">
				<NcButton :disabled="offset === 0" @click="prevPage">
					{{ t('opencase', 'Forrige') }}
				</NcButton>
				<span class="ocfs-cases__page-info">
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

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import api from '../services/api.js'

export default {
	name: 'CaseFieldSearchView',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		MagnifyIcon,
		CaseStatusBadge,
	},

	data() {
		return {
			form: {
				search: '',
				organisation: null,
				year: '',
				status: null,
				casetype: null,
				classificationCode: '',
				sensitivity: null,
				classificationFacet: null,
				insightLevel: null,
				responsible: null,
			},
			userOptions: [],
			userSearchLoading: false,
			cases: [],
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
			return this.$store.state.caseStatuses.map(s => ({ id: s.id, label: s.name }))
		},
		casetypeOptions() {
			return this.$store.state.caseTypes.map(t => ({ id: t.id, label: t.name }))
		},
		sensitivityOptions() {
			return this.$store.state.sensitivities.map(s => ({ id: s.key, label: s.title || s.key }))
		},
		facetOptions() {
			return this.$store.state.classificationFacets.map(f => ({
				id: f.uuid,
				label: f.code ? `${f.code} – ${f.title}` : f.title,
			}))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({ id: l.id, label: l.name }))
		},
		hasActiveFilters() {
			return this.form.search.trim() !== ''
				|| this.form.organisation !== null
				|| this.form.year.trim() !== ''
				|| this.form.status !== null
				|| this.form.casetype !== null
				|| this.form.classificationCode.trim() !== ''
				|| this.form.sensitivity !== null
				|| this.form.classificationFacet !== null
				|| this.form.insightLevel !== null
				|| this.form.responsible !== null
		},
	},

	mounted() {
		this.$store.dispatch('fetchCaseStatuses').catch(() => {})
		this.$store.dispatch('fetchCaseTypes').catch(() => {})
		this.$store.dispatch('fetchSensitivities').catch(() => {})
		this.$store.dispatch('fetchClassificationFacets').catch(() => {})
		this.$store.dispatch('fetchInsightLevels').catch(() => {})
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
				if (this.form.search.trim()) params.search = this.form.search.trim()
				if (this.form.organisation) params.organisation = this.form.organisation.id
				if (this.form.year.trim()) params.year = this.form.year.trim()
				if (this.form.status) params.status_id = this.form.status.id
				if (this.form.casetype) params.casetype_id = this.form.casetype.id
				if (this.form.classificationCode.trim()) params.classification_code = this.form.classificationCode.trim()
				if (this.form.sensitivity) params.sensitivity_key = this.form.sensitivity.id
				if (this.form.classificationFacet) params.classification_facet_uuid = this.form.classificationFacet.id
				if (this.form.insightLevel) params.insight_level_id = this.form.insightLevel.id
				if (this.form.responsible) params.responsible_user_id = this.form.responsible.id

				const result = await api.getCases(params)
				this.cases = result.cases || []
				this.total = result.total || 0
			} finally {
				this.loading = false
			}
		},

		async onUserSearch(query) {
			if (!query || query.trim().length < 2) {
				this.userOptions = []
				return
			}
			this.userSearchLoading = true
			try {
				const users = await api.searchCaseworkerUsers(query)
				this.userOptions = users.map(u => ({ id: u.id, label: u.label || u.id }))
			} catch {
				this.userOptions = []
			} finally {
				this.userSearchLoading = false
			}
		},

		resetForm() {
			this.form = {
				search: '',
				organisation: null,
				year: '',
				status: null,
				casetype: null,
				classificationCode: '',
				sensitivity: null,
				classificationFacet: null,
				insightLevel: null,
				responsible: null,
			}
			this.cases = []
			this.total = 0
			this.searched = false
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
.opencase-case-field-search {
	padding: 20px;
	max-width: 1200px;
}

.opencase-case-field-search h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
	font-weight: 700;
}

.ocfs-cases__form {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ocfs-cases__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
}

.ocfs-cases__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocfs-cases__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocfs-cases__form-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ocfs-cases__loading {
	margin: 60px auto;
}

.ocfs-cases__result-meta {
	font-size: 0.9em;
	color: var(--color-text-lighter);
	margin-bottom: 10px;
}

.ocfs-cases__table {
	width: 100%;
	border-collapse: collapse;
}

.ocfs-cases__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ocfs-cases__row {
	cursor: pointer;
	transition: background 0.15s;
}

.ocfs-cases__row:hover {
	background: var(--color-background-hover);
}

.ocfs-cases__row td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.ocfs-cases__number {
	font-family: var(--font-monospace, monospace);
	font-weight: 600;
	white-space: nowrap;
	color: var(--color-primary-element);
}

.ocfs-cases__title {
	max-width: 360px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ocfs-cases__kle {
	font-family: var(--font-monospace, monospace);
	font-size: 0.85em;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
}

.ocfs-cases__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
}

.ocfs-cases__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 20px;
}

.ocfs-cases__page-info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}
</style>
