<template>
	<div class="process-list">
		<div class="process-list__header">
			<h2>{{ t('opencase', 'Administrer processer') }}</h2>
			<p class="process-list__subtitle">
				{{ t('opencase', 'Design processer for sagsbehandlingen og bestem, hvilke sager de kan startes på. Ændringer laves i en kladde og træder først i kraft, når kladden udgives.') }}
			</p>
			<div class="process-list__header-actions">
				<NcButton type="primary" @click="showCreateDialog = true">
					<template #icon>
						<PlusIcon :size="16" />
					</template>
					{{ t('opencase', 'Ny proces') }}
				</NcButton>
			</div>
		</div>

		<div class="process-list__panel">
			<NcTextField v-if="processes.length > 0"
				v-model="nameFilter"
				class="process-list__filter"
				:label="t('opencase', 'Filtrer efter navn')"
				trailing-button-icon="close"
				:show-trailing-button="nameFilter !== ''"
				@trailing-button-click="nameFilter = ''" />

			<NcLoadingIcon v-if="loading" :size="32" class="process-list__loading" />

			<NcEmptyContent v-else-if="processes.length === 0"
				:name="t('opencase', 'Ingen processer')">
				<template #icon>
					<SitemapOutlineIcon :size="64" />
				</template>
				<template #description>
					{{ t('opencase', 'Opret en proces for at komme i gang') }}
				</template>
			</NcEmptyContent>

			<table v-else class="process-list__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Navn') }}</th>
						<th>{{ t('opencase', 'Afgrænsning') }}</th>
						<th>{{ t('opencase', 'Udgivet version') }}</th>
						<th>{{ t('opencase', 'Kører') }}</th>
						<th>{{ t('opencase', 'Kladde') }}</th>
						<th>{{ t('opencase', 'Status') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="process in filteredProcesses"
						:key="process.id"
						class="process-list__row"
						@click="openProcess(process)">
						<td>
							<div class="process-list__name">
								{{ process.name }}
							</div>
							<code class="process-list__key">{{ process.process_key }}</code>
						</td>
						<td class="process-list__scopes">
							{{ scopeSummary(process) }}
						</td>
						<td>{{ process.published_version_no ? 'v' + process.published_version_no : '–' }}</td>
						<td>{{ process.running_count || '–' }}</td>
						<td>
							<span v-if="process.draft_version_id" class="process-list__badge process-list__badge--draft">
								{{ t('opencase', 'Kladde') }}
							</span>
						</td>
						<td>
							<span class="process-list__badge"
								:class="process.active ? 'process-list__badge--active' : 'process-list__badge--inactive'">
								{{ process.active ? t('opencase', 'Aktiv') : t('opencase', 'Inaktiv') }}
							</span>
						</td>
						<td class="process-list__actions">
							<NcButton type="tertiary"
								:title="t('opencase', 'Åbn i procesdesigner')"
								@click.stop="openProcess(process)">
								<template #icon>
									<PencilIcon :size="16" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<ProcessSettingsDialog v-if="showCreateDialog"
			:case-types="caseTypes"
			:org-units="orgUnits"
			@close="showCreateDialog = false"
			@saved="onCreated" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { showError } from '@nextcloud/dialogs'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import SitemapOutlineIcon from 'vue-material-design-icons/SitemapOutline.vue'

import ProcessSettingsDialog from '../components/ProcessSettingsDialog.vue'
import api from '../services/api.js'
import { classPlanScopeLabel, ensureClassificationPlanItems } from '../utils/caseScopes.js'

export default {
	name: 'ProcessListView',

	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		PlusIcon,
		PencilIcon,
		SitemapOutlineIcon,
		ProcessSettingsDialog,
	},

	data() {
		return {
			loading: true,
			processes: [],
			caseTypes: [],
			orgUnits: [],
			nameFilter: '',
			showCreateDialog: false,
		}
	},

	computed: {
		filteredProcesses() {
			const query = this.nameFilter.trim().toLowerCase()
			if (!query) return this.processes
			return this.processes.filter(p => p.name.toLowerCase().includes(query)
				|| p.process_key.toLowerCase().includes(query))
		},
	},

	async mounted() {
		ensureClassificationPlanItems(this.$store)
		await Promise.all([this.loadProcesses(), this.loadReferenceData()])
	},

	methods: {
		async loadProcesses() {
			this.loading = true
			try {
				this.processes = await api.getProcesses()
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente processer'))
			} finally {
				this.loading = false
			}
		},
		async loadReferenceData() {
			try {
				const [caseTypes, orgs] = await Promise.all([api.getCaseTypes(), api.getOrgTree()])
				this.caseTypes = caseTypes
				this.orgUnits = orgs
			} catch (e) {
				// scope labels fall back to the raw values
			}
		},
		scopeSummary(process) {
			if (!process.scopes.length) {
				return t('opencase', 'Alle sager')
			}
			return process.scopes.map(scope => {
				if (scope.scope_type === 'casetype') {
					return this.caseTypes.find(c => String(c.id) === String(scope.scope_value))?.name || scope.scope_value
				}
				if (scope.scope_type === 'organisation') {
					return this.orgUnits.find(o => o.org_uuid === scope.scope_value)?.org_name || scope.scope_value
				}
				if (scope.scope_type === 'class_plan') {
					return classPlanScopeLabel(this.$store.state, scope.scope_value)
				}
				return 'KLE ' + scope.scope_value
			}).join(', ')
		},
		openProcess(process) {
			this.$router.push({ name: 'process-designer', params: { id: process.id } })
		},
		onCreated(process) {
			this.showCreateDialog = false
			this.openProcess(process)
		},
	},
}
</script>

<style scoped>
.process-list {
	padding: 20px;
	max-width: 1100px;
}

.process-list__header {
	margin-bottom: 24px;
}

.process-list__header-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.process-list__header h2 {
	font-size: 1.4em;
	font-weight: 600;
	margin: 0 0 4px;
}

.process-list__subtitle {
	color: var(--color-text-lighter);
	margin: 0;
	max-width: 700px;
}

.process-list__panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
}

.process-list__filter {
	width: 260px;
	margin-bottom: 16px;
}

.process-list__loading {
	display: block;
	margin: 24px auto;
}

.process-list__table {
	width: 100%;
	border-collapse: collapse;
}

.process-list__table th,
.process-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.process-list__table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.process-list__row {
	cursor: pointer;
}

.process-list__row:hover {
	background: var(--color-background-hover);
}

.process-list__name {
	font-weight: 600;
}

.process-list__key {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.process-list__scopes {
	max-width: 320px;
	color: var(--color-text-lighter);
}

.process-list__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
	white-space: nowrap;
}

.process-list__badge--draft {
	background: var(--color-warning);
	color: var(--color-warning-text, #000);
}

.process-list__badge--active {
	background: var(--color-success);
	color: var(--color-primary-element-text, #fff);
}

.process-list__badge--inactive {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.process-list__actions {
	text-align: right;
	width: 1%;
}
</style>
