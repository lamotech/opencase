<template>
	<div class="opencase-case-list">
		<!-- Header bar -->
		<div class="opencase-case-list__header">
			<h2>{{ pageTitle }}</h2>
			<NcButton type="primary" @click="showNewCaseDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Opret sag') }}
			</NcButton>
		</div>

		<!-- Filter bar -->
		<div class="opencase-case-list__filters">
			<NcSelect v-model="selectedOrg"
				:placeholder="t('opencase', 'Organisation')"
				:options="organisations"
				:clearable="true"
				@update:model-value="applyFilters" />
			<NcSelect v-model="selectedYear"
				:placeholder="t('opencase', 'År')"
				:options="availableYears"
				:clearable="true"
				@update:model-value="applyFilters" />
			<NcSelect v-model="selectedStatus"
				:placeholder="t('opencase', 'Status')"
				:options="statusOptions"
				:clearable="true"
				@update:model-value="applyFilters" />
			<div class="opencase-case-list__search">
				<NcTextField v-model="searchQuery"
					:placeholder="t('opencase', 'Søg i sagsnummer eller titel…')"
					trailing-button-icon="close"
					:show-trailing-button="searchQuery !== ''"
					@trailing-button-click="clearSearch"
					@keyup.enter="applyFilters" />
			</div>
		</div>

		<!-- Loading state -->
		<NcLoadingIcon v-if="loading" :size="44" class="opencase-case-list__loading" />

		<!-- Empty state -->
		<NcEmptyContent v-else-if="cases.length === 0"
			:title="t('opencase', 'Ingen sager fundet')"
			:description="t('opencase', 'Prøv at ændre dine filtre eller opret en ny sag.')">
			<template #icon>
				<FolderIcon :size="64" />
			</template>
			<template #action>
				<NcButton type="primary" @click="showNewCaseDialog = true">
					{{ t('opencase', 'Opret sag') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- Case table -->
		<table v-else class="opencase-case-list__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Sagsnummer') }}</th>
					<th>{{ t('opencase', 'Titel') }}</th>
					<th>{{ t('opencase', 'Organisation') }}</th>
					<th>{{ t('opencase', 'KLE-nummer') }}</th>
					<th>{{ t('opencase', 'Status') }}</th>
					<th>{{ t('opencase', 'Opdateret') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="c in cases"
					:key="c.id"
					class="opencase-case-list__row"
					@click="openCase(c.id)">
					<td class="opencase-case-list__number">
						{{ c.case_number }}
					</td>
					<td class="opencase-case-list__title">
						{{ c.title }}
					</td>
					<td>{{ c.organisation }}</td>
					<td class="opencase-case-list__classification">
						{{ c.classification_code }}
					</td>
					<td>
						<CaseStatusBadge :status="c.status_class" :label="c.status_name" />
					</td>
					<td class="opencase-case-list__date">
						{{ formatDate(c.updated_at) }}
					</td>
				</tr>
			</tbody>
		</table>

		<!-- New case dialog -->
		<NewCaseDialog v-if="showNewCaseDialog" @close="showNewCaseDialog = false" />

		<!-- Pagination -->
		<div v-if="effectiveTotal > limit" class="opencase-case-list__pagination">
			<NcButton :disabled="offset === 0"
				@click="prevPage">
				{{ t('opencase', 'Forrige') }}
			</NcButton>
			<span class="opencase-case-list__page-info">
				{{ t('opencase', '{start}–{end} af {total}', {
					start: offset + 1,
					end: Math.min(offset + limit, effectiveTotal),
					total: effectiveTotal,
				}) }}
			</span>
			<NcButton :disabled="offset + limit >= effectiveTotal"
				@click="nextPage">
				{{ t('opencase', 'Næste') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import NewCaseDialog from '../components/NewCaseDialog.vue'

export default {
	name: 'CaseListView',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		PlusIcon,
		FolderIcon,
		CaseStatusBadge,
		NewCaseDialog,
	},

	data() {
		return {
			showNewCaseDialog: false,
			selectedOrg: null,
			selectedYear: null,
			selectedStatus: null,
			searchQuery: '',
			offset: 0,
		}
	},

	computed: {
		limit() {
			return this.$store.state.searchPageSize
		},
		effectiveTotal() {
			return Math.min(this.$store.state.casesTotal, this.$store.state.searchMaxResultCount)
		},
		cases() {
			return this.$store.state.cases
		},
		casesTotal() {
			return this.$store.state.casesTotal
		},
		loading() {
			return this.$store.state.casesLoading
		},
		organisations() {
			return this.$store.state.organisations.map(o => ({ id: o, label: o }))
		},
		statusOptions() {
			return this.$store.state.caseStatuses.map(s => ({ id: s.id, label: s.name }))
		},
		availableYears() {
			const years = new Set()
			const stats = this.$store.state.stats
			for (const org of Object.values(stats)) {
				for (const year of Object.keys(org)) {
					years.add(year)
				}
			}
			return Array.from(years).sort().reverse().map(y => ({ id: y, label: y }))
		},
		pageTitle() {
			if (this.selectedOrg) {
				return this.selectedOrg.label
			}
			return t('opencase', 'Alle sager')
		},
	},

	mounted() {
		this.$store.dispatch('fetchCaseStatuses')
	},

	watch: {
		'$route.query': {
			immediate: true,
			handler(query) {
				if (query.organisation) {
					this.selectedOrg = { id: query.organisation, label: query.organisation }
				}
				this.applyFilters()
			},
		},
	},

	methods: {
		applyFilters() {
			this.offset = 0
			this.$store.dispatch('setFilters', {
				organisation: this.selectedOrg?.id || null,
				year: this.selectedYear?.id || null,
				status_id: this.selectedStatus?.id || null,
				search: this.searchQuery || null,
			})
		},

		clearSearch() {
			this.searchQuery = ''
			this.applyFilters()
		},

		openCase(id) {
			this.$router.push({ name: 'case-detail', params: { id } })
		},

		prevPage() {
			this.offset = Math.max(0, this.offset - this.limit)
			this.$store.dispatch('fetchCases', { limit: this.limit, offset: this.offset })
		},

		nextPage() {
			this.offset += this.limit
			this.$store.dispatch('fetchCases', { limit: this.limit, offset: this.offset })
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			const d = new Date(dateStr)
			return d.toLocaleDateString('da-DK', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-case-list {
	padding: 20px;
	max-width: 1200px;
}

.opencase-case-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
	/* Reserve space so the "Opret sag" button never slides under the magic-wand (44px + 16px margin + 8px gap). */
	padding-inline-end: 68px;
}

.opencase-case-list__header h2 {
	margin: 0;
	font-size: 1.4em;
	font-weight: 700;
}

.opencase-case-list__filters {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
	flex-wrap: wrap;
	align-items: center;
}

.opencase-case-list__filters .v-select {
	min-width: 160px;
}

.opencase-case-list__search {
	flex: 1;
	min-width: 200px;
}

.opencase-case-list__loading {
	margin: 60px auto;
}

.opencase-case-list__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-case-list__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.opencase-case-list__row {
	cursor: pointer;
	transition: background 0.15s;
}

.opencase-case-list__row:hover {
	background: var(--color-background-hover);
}

.opencase-case-list__row td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.opencase-case-list__number {
	font-family: var(--font-monospace, monospace);
	font-weight: 600;
	white-space: nowrap;
	color: var(--color-primary-element);
}

.opencase-case-list__title {
	max-width: 400px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.opencase-case-list__classification {
	font-family: var(--font-monospace, monospace);
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
}

.opencase-case-list__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
}

.opencase-case-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 20px;
	padding: 12px 0;
}

.opencase-case-list__page-info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}
</style>
