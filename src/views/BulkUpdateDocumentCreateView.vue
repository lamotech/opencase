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
					<NcTextField v-model="form.title"
						:placeholder="t('opencase', 'Dokumentnummer eller titel…')"
						@keyup.enter="doSearch" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Dokumenttype') }}</label>
					<NcSelect v-model="form.documentType"
						:placeholder="t('opencase', 'Alle typer')"
						:options="documentTypeOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Status') }}</label>
					<NcSelect v-model="form.status"
						:placeholder="t('opencase', 'Alle statusser')"
						:options="statusOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="form.organisation"
						:placeholder="t('opencase', 'Alle organisationer')"
						:options="organisationOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Dokumentdato fra') }}</label>
					<input v-model="form.dateFrom" type="date" class="oc-bu-create__date-input" @keyup.enter="doSearch">
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Dokumentdato til') }}</label>
					<input v-model="form.dateTo" type="date" class="oc-bu-create__date-input" @keyup.enter="doSearch">
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Dokumentkategori') }}</label>
					<NcSelect v-model="form.documentCategory"
						:placeholder="t('opencase', 'Alle kategorier')"
						:options="documentCategoryOptions"
						:clearable="true" />
				</div>
				<div class="oc-bu-create__field">
					<label class="oc-bu-create__label">{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="form.insightLevel"
						:placeholder="t('opencase', 'Alle indsigtsgrader')"
						:options="insightLevelOptions"
						:clearable="true" />
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
				{{ t('opencase', 'Slå de felter til, som skal opdateres på alle dokumenter, der matcher søgekriterierne.') }}
			</p>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.title" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Titel') }}</label>
					<NcTextField v-model="editForm.title" :disabled="!editEnabled.title" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.document_type" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Dokumenttype') }}</label>
					<NcSelect v-model="editForm.document_type"
						:disabled="!editEnabled.document_type"
						:options="editDocumentTypeOptions"
						:placeholder="t('opencase', 'Vælg dokumenttype')" />
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.status" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Status') }}</label>
					<NcSelect v-model="editForm.status"
						:disabled="!editEnabled.status"
						:options="statusOptions"
						:placeholder="t('opencase', 'Vælg status')" />
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
				<NcCheckboxRadioSwitch v-model="editEnabled.document_date" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Dokumentdato') }}</label>
					<input v-model="editForm.document_date"
						type="date"
						class="oc-bu-create__date-input"
						:disabled="!editEnabled.document_date">
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.received_date" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Modtaget dato') }}</label>
					<input v-model="editForm.received_date"
						type="date"
						class="oc-bu-create__date-input"
						:disabled="!editEnabled.received_date">
				</div>
			</div>

			<div class="oc-bu-create__edit-field">
				<NcCheckboxRadioSwitch v-model="editEnabled.registered_date" type="switch" />
				<div class="oc-bu-create__edit-control">
					<label>{{ t('opencase', 'Registreret dato') }}</label>
					<input v-model="editForm.registered_date"
						type="date"
						class="oc-bu-create__date-input"
						:disabled="!editEnabled.registered_date">
				</div>
			</div>
		</div>

		<!-- Results -->
		<NcLoadingIcon v-if="loading" :size="44" class="oc-bu-create__loading" />

		<NcEmptyContent v-else-if="searched && documents.length === 0"
			:title="t('opencase', 'Ingen dokumenter fundet')"
			:description="t('opencase', 'Prøv at ændre søgekriterierne')">
			<template #icon>
				<MagnifyIcon :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else-if="documents.length > 0">
			<div class="oc-bu-create__result-meta">
				{{ t('opencase', '{count} dokumenter matcher søgekriterierne', { count: total }) }}
			</div>
			<table class="oc-bu-create__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Titel') }}</th>
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'Sag') }}</th>
						<th>{{ t('opencase', 'Organisation') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="doc in documents" :key="doc.id">
						<td class="oc-bu-create__title-cell">{{ doc.title }}</td>
						<td>{{ doc.document_type }}</td>
						<td class="oc-bu-create__number">{{ doc.case_number }}</td>
						<td>{{ doc.org_name }}</td>
					</tr>
				</tbody>
			</table>
		</template>

		<!-- Confirm dialog -->
		<NcDialog v-if="showConfirm"
			:name="t('opencase', 'Bekræft masseredigering')"
			@closing="showConfirm = false">
			<p><strong>{{ t('opencase', 'Titel') }}:</strong> {{ title }}</p>
			<p><strong>{{ t('opencase', 'Antal dokumenter der opdateres') }}:</strong> {{ total }}</p>
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

import OverflowTabs from '../components/OverflowTabs.vue'
import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

const EDIT_FIELD_LABELS = {
	title: 'Titel',
	document_type: 'Dokumenttype',
	status: 'Status',
	insight_level_id: 'Indsigtsgrad',
	document_date: 'Dokumentdato',
	received_date: 'Modtaget dato',
	registered_date: 'Registreret dato',
}

// Document type isn't a reference-data table — it's a free-text column with
// a fixed set of conventional values, same list EditDocumentDialog.vue offers.
const DOCUMENT_TYPES = ['afgørelse', 'notat', 'brev', 'bilag', 'ansøgning', 'referat']

export default {
	name: 'BulkUpdateDocumentCreateView',

	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcDialog,
		MagnifyIcon,
		OverflowTabs,
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
				title: '',
				documentType: null,
				status: null,
				organisation: null,
				dateFrom: '',
				dateTo: '',
				documentCategory: null,
				insightLevel: null,
			},
			editEnabled: {
				title: false,
				document_type: false,
				status: false,
				insight_level_id: false,
				document_date: false,
				received_date: false,
				registered_date: false,
			},
			editForm: {
				title: '',
				document_type: null,
				status: null,
				insight_level_id: null,
				document_date: '',
				received_date: '',
				registered_date: '',
			},
			documentCategories: [],
			documentTypes: [],
			documents: [],
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
			return this.$store.state.documentStatuses.map(s => ({ id: s.id, label: s.name }))
		},
		documentTypeOptions() {
			return this.documentTypes.map(ty => ({ id: ty.name, label: ty.name }))
		},
		editDocumentTypeOptions() {
			return DOCUMENT_TYPES.map(ty => ({ id: ty, label: ty }))
		},
		documentCategoryOptions() {
			return this.documentCategories.map(c => ({ id: c.id, label: c.name }))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({ id: l.id, label: l.name }))
		},
		/**
		 * Enabled edit-tab fields as { key, label, value } — value is the
		 * option's label for select-backed fields, or the raw text/date
		 * otherwise.
		 */
		enabledFields() {
			const selectKeys = ['document_type', 'status', 'insight_level_id']
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
		this.$store.dispatch('fetchDocumentStatuses').catch(() => {})
		this.$store.dispatch('fetchInsightLevels').catch(() => {})
		this.$store.dispatch('fetchOrganisations').catch(() => {})
		api.getDocumentCategories().then(categories => {
			this.documentCategories = categories || []
		}).catch(() => {})
		api.getDocumentTypes().then(types => {
			this.documentTypes = types || []
		}).catch(() => {})
	},

	methods: {
		buildSearchParams() {
			const params = {}
			if (this.form.title.trim()) params.title = this.form.title.trim()
			if (this.form.documentType) params.document_type = this.form.documentType.id
			if (this.form.status) params.status = this.form.status.id
			if (this.form.organisation) params.organisation = this.form.organisation.id
			if (this.form.dateFrom) params.date_from = this.form.dateFrom
			if (this.form.dateTo) params.date_to = this.form.dateTo
			if (this.form.documentCategory) params.document_category_id = this.form.documentCategory.id
			if (this.form.insightLevel) params.insight_level_id = this.form.insightLevel.id
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
				const result = await api.searchDocuments(params)
				this.documents = result.documents || []
				this.total = result.total || 0
			} catch (e) {
				showError(t('opencase', 'Kunne ikke søge efter dokumenter'))
			} finally {
				this.loading = false
			}
		},

		updatesPayload() {
			const out = {}
			if (this.editEnabled.title) out.title = this.editForm.title
			if (this.editEnabled.document_type) out.document_type = this.editForm.document_type?.id || null
			if (this.editEnabled.status) out.status = this.editForm.status?.id || null
			if (this.editEnabled.insight_level_id) out.insight_level_id = this.editForm.insight_level_id?.id || null
			if (this.editEnabled.document_date) out.document_date = this.editForm.document_date || null
			if (this.editEnabled.received_date) out.received_date = this.editForm.received_date || null
			if (this.editEnabled.registered_date) out.registered_date = this.editForm.registered_date || null
			return out
		},

		onSaveClick() {
			if (!this.title.trim()) {
				showError(t('opencase', 'Angiv en titel'))
				return
			}
			if (!this.searched) {
				showError(t('opencase', 'Søg efter dokumenter først'))
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
					entity: 'document',
					criterias: this.buildSearchParams(),
					updates: this.updatesPayload(),
				})
				this.showConfirm = false
				showSuccess(t('opencase', 'Masseredigering gemt'))
				this.$router.push({ name: 'bulkupdate-documents' })
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

.oc-bu-create__date-input {
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

.oc-bu-create__date-input:focus {
	border-color: var(--color-primary-element);
	outline: none;
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
