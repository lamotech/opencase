<template>
	<div class="oc-bu-create">
		<div class="oc-bu-create__header">
			<NcTextField v-model="title"
				class="oc-bu-create__title"
				:placeholder="t('opencase', 'Titel på masseredigering')" />
			<NcButton type="primary" @click="onSaveClick">
				{{ t('opencase', 'Gem') }}
			</NcButton>
		</div>

		<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

		<!-- Search criteria tab -->
		<div v-if="activeTab === 'search'" class="oc-bu-create__panel">
			<div class="oc-bu-create__form-grid">
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Søgetekst') }}</label>
					<NcTextField v-model="form.search"
						:placeholder="t('opencase', 'Sagsnummer eller titel…')"
						@keyup.enter="doSearch" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="form.organisation"
						:placeholder="t('opencase', 'Alle organisationer')"
						:options="organisationOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'År') }}</label>
					<NcTextField v-model="form.year" :placeholder="t('opencase', 'f.eks. 2024')" @keyup.enter="doSearch" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Status') }}</label>
					<NcSelect v-model="form.status"
						:placeholder="t('opencase', 'Alle statusser')"
						:options="statusOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Sagstype') }}</label>
					<NcSelect v-model="form.casetype"
						:placeholder="t('opencase', 'Alle sagstyper')"
						:options="casetypeOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'KLE-nummer') }}</label>
					<NcTextField v-model="form.classificationCode"
						:placeholder="t('opencase', 'f.eks. 27.69.00')"
						@keyup.enter="doSearch" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Følsomhed') }}</label>
					<NcSelect v-model="form.sensitivity"
						:placeholder="t('opencase', 'Alle følsomhedsniveauer')"
						:options="sensitivityOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Handlingsfacet') }}</label>
					<NcSelect v-model="form.classificationFacet"
						:placeholder="t('opencase', 'Alle handlingsfacetter')"
						:options="facetOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="form.insightLevel"
						:placeholder="t('opencase', 'Alle indsigtsgrader')"
						:options="insightLevelOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Ansvarlig') }}</label>
					<NcSelect v-model="form.responsible"
						:placeholder="t('opencase', 'Søg bruger…')"
						:options="userOptions"
						:filterable="false"
						:loading="userSearchLoading"
						:clearable="true"
						@search="onUserSearch" />
				</div>
			</div>

			<div class="oc-bu-create__form-actions">
				<NcButton type="primary" @click="doSearch">
					<template #icon>
						<MagnifyIcon :size="20" />
					</template>
					{{ t('opencase', 'Søg') }}
				</NcButton>
			</div>
		</div>

		<!-- Edit fields tab -->
		<div v-if="activeTab === 'edit'" class="oc-bu-create__panel">
			<p class="oc-bu-create__hint">
				{{ t('opencase', 'Slå de felter til, som skal opdateres på alle sager, der matcher søgekriterierne.') }}
			</p>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.title" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Titel') }}</label>
					<NcTextField v-model="editForm.title" :disabled="!editEnabled.title" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.organisation" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="editForm.organisation"
						:disabled="!editEnabled.organisation"
						:options="organisationOptions"
						:placeholder="t('opencase', 'Vælg organisation')" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.classification_code" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'KLE-nummer') }}</label>
					<NcSelect v-model="editForm.classification_code"
						:disabled="!editEnabled.classification_code"
						:options="classificationOptions"
						:placeholder="t('opencase', 'Vælg KLE-emneord')" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.sensitivity" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Følsomhed') }}</label>
					<NcSelect v-model="editForm.sensitivity"
						:disabled="!editEnabled.sensitivity"
						:options="sensitivityOptions"
						:placeholder="t('opencase', 'Vælg følsomhed')" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.classification_facet_uuid" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Handlingsfacet') }}</label>
					<NcSelect v-model="editForm.classification_facet_uuid"
						:disabled="!editEnabled.classification_facet_uuid"
						:options="facetOptions"
						:placeholder="t('opencase', 'Vælg handlingsfacet')" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.insight_level_id" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="editForm.insight_level_id"
						:disabled="!editEnabled.insight_level_id"
						:options="insightLevelOptions"
						:placeholder="t('opencase', 'Vælg indsigtsgrad')" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.responsible_user_id" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Ansvarlig') }}</label>
					<NcSelect v-model="editForm.responsible_user_id"
						:disabled="!editEnabled.responsible_user_id"
						:options="userOptions"
						:filterable="false"
						:loading="userSearchLoading"
						:placeholder="t('opencase', 'Søg efter bruger')"
						@search="onUserSearch" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.summary" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Sagsresume') }}</label>
					<NcTextArea v-model="editForm.summary" :disabled="!editEnabled.summary" />
				</div>
			</div>
		</div>

		<!-- Results -->
		<NcLoadingIcon v-if="loading" :size="44" class="oc-bu-create__loading" />

		<NcEmptyContent v-else-if="searched && cases.length === 0"
			:title="t('opencase', 'Ingen sager fundet')"
			:description="t('opencase', 'Prøv at ændre søgekriterierne')">
			<template #icon>
				<MagnifyIcon :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else-if="cases.length > 0">
			<div class="oc-bu-create__result-meta">
				{{ t('opencase', '{count} sager matcher søgekriterierne', { count: total }) }}
			</div>
			<table class="oc-bu-create__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Sagsnummer') }}</th>
						<th>{{ t('opencase', 'Titel') }}</th>
						<th>{{ t('opencase', 'Organisation') }}</th>
						<th>{{ t('opencase', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="c in cases" :key="c.id">
						<td class="oc-bu-create__number">{{ c.case_number }}</td>
						<td class="oc-bu-create__title-cell">{{ c.title }}</td>
						<td>{{ c.organisation }}</td>
						<td><CaseStatusBadge :status="c.status_class" :label="c.status_name" /></td>
					</tr>
				</tbody>
			</table>
		</template>

		<!-- Confirm dialog -->
		<NcDialog v-if="showConfirm"
			:name="t('opencase', 'Bekræft masseredigering')"
			@closing="showConfirm = false">
			<p><strong>{{ t('opencase', 'Titel') }}:</strong> {{ title }}</p>
			<p><strong>{{ t('opencase', 'Antal sager der opdateres') }}:</strong> {{ total }}</p>
			<p>{{ t('opencase', 'Felter der opdateres:') }}</p>
			<ul>
				<li v-for="f in enabledFields" :key="f.key">
					<strong>{{ f.label }}:</strong> {{ f.value }}
				</li>
			</ul>
			<template #actions>
				<NcButton type="tertiary" @click="showConfirm = false">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="confirmSave">
					{{ t('opencase', 'OK') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

import OverflowTabs from '../components/OverflowTabs.vue'
import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

const EDIT_FIELD_LABELS = {
	title: 'Titel',
	organisation: 'Organisation',
	classification_code: 'KLE-nummer',
	sensitivity: 'Følsomhed',
	classification_facet_uuid: 'Handlingsfacet',
	insight_level_id: 'Indsigtsgrad',
	responsible_user_id: 'Ansvarlig',
	summary: 'Sagsresume',
}

export default {
	name: 'BulkUpdateCaseCreateView',

	components: {
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcDialog,
		MagnifyIcon,
		OverflowTabs,
		CaseStatusBadge,
	},

	data() {
		return {
			title: '',
			activeTab: 'search',
			tabs: [
				{ id: 'search', label: t('opencase', 'Søgekriterier') },
				{ id: 'edit', label: t('opencase', 'Felter der skal opdateres') },
			],
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
			editEnabled: {
				title: false,
				organisation: false,
				classification_code: false,
				sensitivity: false,
				classification_facet_uuid: false,
				insight_level_id: false,
				responsible_user_id: false,
				summary: false,
			},
			editForm: {
				title: '',
				organisation: null,
				classification_code: null,
				sensitivity: null,
				classification_facet_uuid: null,
				insight_level_id: null,
				responsible_user_id: null,
				summary: '',
			},
			userOptions: [],
			userSearchLoading: false,
			cases: [],
			total: 0,
			loading: false,
			searched: false,
			showConfirm: false,
			saving: false,
		}
	},

	computed: {
		limit() {
			return this.$store.state.searchPageSize
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
		classificationOptions() {
			return this.$store.state.classificationSubjects
				.filter(s => /^\d{2}\.\d{2}\.\d{2}$/.test(s.code))
				.map(s => ({ id: s.code, label: `${s.code} – ${s.title}` }))
		},
		/**
		 * Enabled edit-tab fields as { key, label, value } — value is the
		 * option's label for select-backed fields, or the raw text otherwise.
		 */
		enabledFields() {
			const selectKeys = [
				'organisation', 'classification_code', 'sensitivity',
				'classification_facet_uuid', 'insight_level_id', 'responsible_user_id',
			]
			return Object.keys(this.editEnabled)
				.filter(key => this.editEnabled[key])
				.map(key => {
					const raw = this.editForm[key]
					return {
						key,
						label: t('opencase', EDIT_FIELD_LABELS[key]),
						value: selectKeys.includes(key) ? (raw?.label ?? '') : raw,
					}
				})
		},
	},

	mounted() {
		this.$store.dispatch('fetchCaseStatuses').catch(() => {})
		this.$store.dispatch('fetchCaseTypes').catch(() => {})
		this.$store.dispatch('fetchSensitivities').catch(() => {})
		this.$store.dispatch('fetchClassificationFacets').catch(() => {})
		this.$store.dispatch('fetchInsightLevels').catch(() => {})
		this.$store.dispatch('fetchClassificationSubjects').catch(() => {})
		this.$store.dispatch('fetchOrganisations').catch(() => {})
	},

	methods: {
		buildSearchParams() {
			const params = {}
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
			return params
		},

		async doSearch() {
			await this.fetchResults()
		},

		async fetchResults() {
			this.loading = true
			this.searched = true
			try {
				const params = { ...this.buildSearchParams(), limit: this.limit, offset: 0 }
				const result = await api.getCases(params)
				this.cases = result.cases || []
				this.total = result.total || 0
			} catch (e) {
				showError(t('opencase', 'Kunne ikke søge efter sager'))
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

		updatesPayload() {
			const out = {}
			if (this.editEnabled.title) out.title = this.editForm.title
			if (this.editEnabled.organisation) out.organisation = this.editForm.organisation?.id || ''
			if (this.editEnabled.classification_code) out.classification_code = this.editForm.classification_code?.id || ''
			if (this.editEnabled.sensitivity) out.sensitivity = this.editForm.sensitivity?.id || null
			if (this.editEnabled.classification_facet_uuid) out.classification_facet_uuid = this.editForm.classification_facet_uuid?.id || ''
			if (this.editEnabled.insight_level_id) out.insight_level_id = this.editForm.insight_level_id?.id || null
			if (this.editEnabled.responsible_user_id) out.responsible_user_id = this.editForm.responsible_user_id?.id || null
			if (this.editEnabled.summary) out.summary = this.editForm.summary
			return out
		},

		onSaveClick() {
			if (!this.title.trim()) {
				showError(t('opencase', 'Angiv en titel'))
				return
			}
			if (!this.searched) {
				showError(t('opencase', 'Søg efter sager først'))
				return
			}
			if (this.enabledFields.length === 0) {
				showError(t('opencase', 'Vælg mindst ét felt der skal opdateres'))
				return
			}
			this.showConfirm = true
		},

		async confirmSave() {
			this.saving = true
			try {
				await api.createBulkUpdate({
					title: this.title.trim(),
					entity: 'case',
					criterias: this.buildSearchParams(),
					updates: this.updatesPayload(),
				})
				this.showConfirm = false
				showSuccess(t('opencase', 'Masseredigering gemt'))
				this.$router.push({ name: 'bulkupdate-cases' })
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme masseredigering'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.oc-bu-create {
	padding: 20px;
	max-width: 1200px;
}

.oc-bu-create__header {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 20px;
}

.oc-bu-create__title {
	flex: 1;
	max-width: 400px;
}

.oc-bu-create__panel {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.oc-bu-create__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
}

.oc-bu-create__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.oc-bu-create__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.oc-bu-create__form-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.oc-bu-create__hint {
	margin: 0 0 4px;
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.oc-bu-create__edit-field {
	display: flex;
	align-items: center;
	gap: 12px;
}

.oc-bu-create__edit-control {
	flex: 1;
	max-width: 400px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.oc-bu-create__edit-control label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.oc-bu-create__loading {
	margin: 40px auto;
}

.oc-bu-create__result-meta {
	font-size: 0.9em;
	color: var(--color-text-lighter);
	margin-bottom: 10px;
}

.oc-bu-create__table {
	width: 100%;
	border-collapse: collapse;
}

.oc-bu-create__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.oc-bu-create__table td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.oc-bu-create__number {
	font-family: var(--font-monospace, monospace);
	font-weight: 600;
	white-space: nowrap;
	color: var(--color-primary-element);
}

.oc-bu-create__title-cell {
	max-width: 360px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
