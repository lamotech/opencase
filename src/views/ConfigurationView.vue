<template>
	<div class="opencase-configuration">
		<h2>{{ t('opencase', 'Konfiguration') }}</h2>

		<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

		<!-- Settings tab -->
		<div v-if="activeTab === 'settings'" class="opencase-configuration__content">
			<NcLoadingIcon v-if="loadingSettings" :size="32" />

			<div v-else class="opencase-configuration__settings">
				<div v-for="entry in settingsEntries"
					:key="entry.key"
					class="opencase-configuration__entry">
					<label :for="'config-' + entry.key" class="opencase-configuration__label">
						{{ entry.name || entry.key }}
					</label>
					<p v-if="entry.description" class="opencase-configuration__description">
						{{ entry.description }}
					</p>
					<NcTextField :id="'config-' + entry.key"
						v-model="settingsDraft[entry.key]"
						:placeholder="entry.key" />
				</div>

				<NcButton type="primary"
					:disabled="savingSettings"
					@click="saveSettings">
					{{ t('opencase', 'Gem') }}
				</NcButton>
			</div>
		</div>

		<!-- Code lists tab -->
		<div v-if="activeTab === 'codelists'" class="opencase-configuration__content">
			<ul class="opencase-configuration__codelists">
				<li v-for="cl in codeLists" :key="cl.list">
					<button class="opencase-configuration__codelist-btn" @click="openCodeList(cl)">
						{{ cl.label }}
					</button>
				</li>
			</ul>
		</div>

		<!-- Incoming documents tab -->
		<div v-if="activeTab === 'incoming'" class="opencase-configuration__content">
			<NcLoadingIcon v-if="incomingLoading" :size="32" />

			<div v-else class="opencase-configuration__settings">
				<h3 class="opencase-configuration__heading">
					{{ t('opencase', 'Vælg standardværdier for indkommende dokumenter') }}
				</h3>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="selectedIncomingOrg"
						:options="incomingOrgOptions"
						:loading="incomingOrgSearchLoading"
						:filterable="false"
						:clearable="true"
						:placeholder="t('opencase', 'Søg efter organisation')"
						@search="onIncomingOrgSearch" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Følsomhed') }}</label>
					<NcSelect v-model="selectedIncomingSensitivity"
						:options="sensitivityOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg følsomhed')" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'KLE-nummer') }}</label>
					<NcSelect v-model="selectedIncomingKle"
						:options="kleOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg KLE-emneord')" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Handlingsfacet') }}</label>
					<NcSelect v-model="selectedIncomingFacet"
						:options="facetOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg handlingsfacet')" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="selectedIncomingInsightLevel"
						:options="insightLevelOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg indsigtsgrad')" />
				</div>

				<NcButton type="primary"
					:disabled="incomingSaving"
					@click="saveIncoming">
					{{ t('opencase', 'Gem') }}
				</NcButton>
			</div>
		</div>

		<!-- Separation sheets tab -->
		<div v-if="activeTab === 'separationsheets'" class="opencase-configuration__content opencase-configuration__content--wide">
			<div class="opencase-configuration__toolbar">
				<NcButton type="primary" @click="openCreateSeparationSheet = true">
					{{ t('opencase', 'Opret separationsark') }}
				</NcButton>
				<NcButton :disabled="creatingAttachmentSheet" @click="createAttachmentSheet">
					<template v-if="creatingAttachmentSheet" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('opencase', 'Generér og udskriv bilagsark (QR)') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="separationSheetsLoading" :size="32" />

			<p v-else-if="separationSheets.length === 0" class="opencase-configuration__description">
				{{ t('opencase', 'Ingen separationsark oprettet endnu.') }}
			</p>

			<table v-else class="opencase-configuration__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Navn') }}</th>
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'Sag / titel') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="sheet in separationSheets" :key="sheet.id">
						<td>{{ sheet.name }}</td>
						<td>{{ separationSheetTypeLabel(sheet.type) }}</td>
						<td>
							<span v-if="sheet.case_number">{{ sheet.case_number }} – </span>{{ sheet.title }}
						</td>
						<td>
							<div class="opencase-configuration__row-actions">
								<NcButton :disabled="printingSheetId === sheet.id" @click="printSeparationSheet(sheet)">
									<template v-if="printingSheetId === sheet.id" #icon>
										<NcLoadingIcon :size="20" />
									</template>
									{{ t('opencase', 'Generér og udskriv PDF') }}
								</NcButton>
								<NcButton v-if="isEditableSeparationSheet(sheet)" @click="editSeparationSheet(sheet)">
									{{ t('opencase', 'Rediger') }}
								</NcButton>
								<NcButton type="error"
									:disabled="deletingSheetId === sheet.id"
									@click="deleteSeparationSheet(sheet)">
									<template v-if="deletingSheetId === sheet.id" #icon>
										<NcLoadingIcon :size="20" />
									</template>
									{{ t('opencase', 'Slet') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>

			<CreateSeparationSheetDialog v-if="openCreateSeparationSheet || editingSheet"
				:key="editingSheet ? `edit-${editingSheet.id}` : 'create'"
				:sheet="editingSheet"
				@close="closeSeparationSheetDialog"
				@created="onSeparationSheetCreated"
				@updated="onSeparationSheetUpdated" />
		</div>

		<!-- Custom fields tab -->
		<div v-if="activeTab === 'customfields'" class="opencase-configuration__content opencase-configuration__content--wide">
			<div class="opencase-configuration__toolbar">
				<NcButton type="primary" @click="showCreateCustomFieldDialog = true">
					{{ t('opencase', 'Opret nyt felt') }}
				</NcButton>
			</div>

			<p v-if="customFields.length === 0 && !customFieldsLoading" class="opencase-configuration__description">
				{{ t('opencase', 'Ingen tillægsfelter er oprettet endnu.') }}
			</p>

			<table v-else class="opencase-configuration__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Nøgle') }}</th>
						<th>{{ t('opencase', 'Navn') }}</th>
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'Påkrævet') }}</th>
						<th>{{ t('opencase', 'Afgrænsning') }}</th>
						<th>{{ t('opencase', 'Aktiv') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="field in customFields" :key="field.id">
						<td><code>{{ field.field_key }}</code></td>
						<td>{{ field.label }}</td>
						<td>{{ cfFieldTypeLabel(field.field_type) }}</td>
						<td>{{ field.required ? t('opencase', 'Ja') : t('opencase', 'Nej') }}</td>
						<td>{{ cfScopeSummary(field) }}</td>
						<td>
							<NcCheckboxRadioSwitch :model-value="field.active"
								type="switch"
								@update:model-value="setCustomFieldActive(field, $event)" />
						</td>
						<td>
							<div class="opencase-configuration__row-actions">
								<NcButton @click="editingCustomField = field">
									{{ t('opencase', 'Rediger') }}
								</NcButton>
								<NcButton type="error" @click="deleteCustomField(field)">
									{{ t('opencase', 'Slet') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>

			<p v-if="customFieldsError" class="opencase-configuration__error opencase-configuration__description">
				{{ customFieldsError }}
			</p>

			<CreateCustomFieldDialog v-if="showCreateCustomFieldDialog"
				:case-types="cfCaseTypes"
				:org-units="cfOrgUnits"
				@close="showCreateCustomFieldDialog = false"
				@created="onCustomFieldCreated" />

			<EditCustomFieldDialog v-if="editingCustomField"
				:field="editingCustomField"
				:case-types="cfCaseTypes"
				:org-units="cfOrgUnits"
				@close="editingCustomField = null"
				@updated="onCustomFieldUpdated" />
		</div>

		<!-- SAPA (SagDokumentIndeks) transfer rules tab -->
		<div v-if="activeTab === 'sapa'" class="opencase-configuration__content opencase-configuration__content--wide">
			<p class="opencase-configuration__description">
				{{ t('opencase', 'Reglerne bestemmer, hvilke sager der overføres til Sags- og Dokumentindekset (SAPA). En sag overføres, hvis den matcher mindst én aktiv regel. En regel uden afgrænsning gælder alle sager.') }}
			</p>
			<div class="opencase-configuration__toolbar">
				<NcButton type="primary" @click="editingSapaRule = {}">
					{{ t('opencase', 'Opret regel') }}
				</NcButton>
			</div>

			<p v-if="sapaRules.length === 0 && !sapaRulesLoading" class="opencase-configuration__description">
				{{ t('opencase', 'Ingen regler er oprettet endnu, så ingen sager overføres.') }}
			</p>

			<table v-else class="opencase-configuration__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Navn') }}</th>
						<th>{{ t('opencase', 'Afgrænsning') }}</th>
						<th>{{ t('opencase', 'Sager') }}</th>
						<th>{{ t('opencase', 'Overført') }}</th>
						<th>{{ t('opencase', 'Aktiv') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="rule in sapaRules" :key="rule.id">
						<td>{{ rule.name }}</td>
						<td>{{ cfScopeSummary(rule) }}</td>
						<td>
							<NcLoadingIcon v-if="!sapaRuleCounts[rule.id]" :size="16" />
							<span v-else>{{ sapaRuleCounts[rule.id].error ? '–' : sapaRuleCounts[rule.id].matching.toLocaleString('da-DK') }}</span>
						</td>
						<td>
							<NcLoadingIcon v-if="!sapaRuleCounts[rule.id]" :size="16" />
							<span v-else>{{ sapaRuleCounts[rule.id].error ? '–' : sapaRuleCounts[rule.id].sent.toLocaleString('da-DK') }}</span>
						</td>
						<td>
							<NcCheckboxRadioSwitch :model-value="rule.active"
								type="switch"
								@update:model-value="setSapaRuleActive(rule, $event)" />
						</td>
						<td>
							<div class="opencase-configuration__row-actions">
								<NcButton @click="editingSapaRule = rule">
									{{ t('opencase', 'Rediger') }}
								</NcButton>
								<NcButton type="error" @click="deleteSapaRule(rule)">
									{{ t('opencase', 'Slet') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>

			<SapaRuleDialog v-if="editingSapaRule"
				:rule="editingSapaRule.id ? editingSapaRule : null"
				:case-types="cfCaseTypes"
				:org-units="cfOrgUnits"
				@close="editingSapaRule = null"
				@saved="onSapaRuleSaved" />
		</div>

		<!-- Masking rules tab -->
		<div v-if="activeTab === 'maskrules'" class="opencase-configuration__content opencase-configuration__content--wide">
			<p class="opencase-configuration__description">
				{{ t('opencase', 'Reglerne bestemmer, hvad "Foreslå maskering" finder i filer under aktindsigt. Fund vises som forslag, som sagsbehandleren gennemgår, før noget maskeres. Ændringer gælder fra næste analyse.') }}
			</p>
			<p v-if="!maskRulesLoading && !maskServiceConfigured" class="opencase-configuration__description opencase-configuration__error">
				{{ t('opencase', 'Maskeringstjenesten er ikke sat op (Nextcloud-indstillinger → OpenCase → Maskering), så reglerne bruges ikke endnu.') }}
			</p>

			<div class="opencase-configuration__toolbar">
				<NcButton type="primary" @click="editingMaskRule = {}">
					{{ t('opencase', 'Opret regel') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="maskRulesLoading" :size="32" />
			<p v-else-if="maskRules.length === 0" class="opencase-configuration__description">
				{{ t('opencase', 'Der er ingen maskeringsregler. "Foreslå maskering" finder derfor kun tekst, sagsbehandleren selv tilføjer.') }}
			</p>
			<table v-else class="opencase-configuration__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Rækkefølge') }}</th>
						<th>{{ t('opencase', 'Navn') }}</th>
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'Regel') }}</th>
						<th>{{ t('opencase', 'Hjemmel') }}</th>
						<th>{{ t('opencase', 'Aktiv') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="rule in maskRules" :key="rule.id">
						<td>{{ rule.sort_order }}</td>
						<td>{{ rule.label }}</td>
						<td>{{ rule.kind === 'terms' ? t('opencase', 'Ordliste') : t('opencase', 'Mønster') }}</td>
						<td class="opencase-configuration__rule-pattern">
							<template v-if="rule.kind === 'terms'">
								{{ n('opencase', '{count} ord/udtryk', '{count} ord/udtryk', rule.pattern.split('\n').length, { count: rule.pattern.split('\n').length }) }}
							</template>
							<code v-else :title="rule.pattern">{{ rule.pattern }}</code>
						</td>
						<td>{{ rule.legal_reference || '—' }}</td>
						<td>
							<NcCheckboxRadioSwitch :model-value="rule.enabled"
								type="switch"
								@update:model-value="setMaskRuleEnabled(rule, $event)" />
						</td>
						<td>
							<div class="opencase-configuration__row-actions">
								<NcButton @click="editingMaskRule = rule">
									{{ t('opencase', 'Rediger') }}
								</NcButton>
								<NcButton type="error" @click="deleteMaskRule(rule)">
									{{ t('opencase', 'Slet') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>

			<MaskRuleDialog v-if="editingMaskRule"
				:rule="editingMaskRule.id ? editingMaskRule : null"
				:service-configured="maskServiceConfigured"
				@close="editingMaskRule = null"
				@saved="onMaskRuleSaved" />
		</div>

		<CodeListDialog v-if="openList"
			:list="openList.list"
			:title="openList.label"
			@close="openList = null" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

import { showError, showSuccess } from '@nextcloud/dialogs'
import OverflowTabs from '../components/OverflowTabs.vue'
import CodeListDialog from '../components/CodeListDialog.vue'
import CreateSeparationSheetDialog from '../components/CreateSeparationSheetDialog.vue'
import CreateCustomFieldDialog from '../components/CreateCustomFieldDialog.vue'
import EditCustomFieldDialog from '../components/EditCustomFieldDialog.vue'
import MaskRuleDialog from '../components/MaskRuleDialog.vue'
import SapaRuleDialog from '../components/SapaRuleDialog.vue'
import { generateAndPrintSeparationSheetPdf } from '../utils/generateSeparationSheetPdf.js'
import api from '../services/api.js'

const SEPARATION_SHEET_TYPE_LABELS = {
	'existing case': 'Eksisterende sag',
	'new case': 'Ny sag',
	'inbox case': 'Indbakkesag',
	attachment: 'Bilag',
}

// "attachment" sheets carry no values, so there is nothing to edit after printing
const EDITABLE_SEPARATION_SHEET_TYPES = ['existing case', 'new case', 'inbox case']

const SETTINGS_KEYS = ['case_number_mask', 'search_max_result_count', 'search_page_size', 'history_count']

export default {
	name: 'ConfigurationView',

	components: { NcButton, NcTextField, NcLoadingIcon, NcSelect, NcCheckboxRadioSwitch, OverflowTabs, CodeListDialog, CreateSeparationSheetDialog, CreateCustomFieldDialog, EditCustomFieldDialog, MaskRuleDialog, SapaRuleDialog },

	data() {
		return {
			activeTab: 'settings',
			allTabs: [
				{ id: 'settings', label: t('opencase', 'Indstillinger') },
				{ id: 'codelists', label: t('opencase', 'Værdilister') },
				{ id: 'incoming', label: t('opencase', 'Indkommende dokumenter') },
				{ id: 'separationsheets', label: t('opencase', 'Separationsark') },
				{ id: 'customfields', label: t('opencase', 'Tillægsfelter') },
				// SagDokumentIndeks integration is Enterprise only
				{ id: 'sapa', label: t('opencase', 'SAPA'), enterprise: true },
				// Rules drive the redaction service, which is Enterprise only
				{ id: 'maskrules', label: t('opencase', 'Maskeringsregler'), enterprise: true },
			],

			// Settings tab
			loadingSettings: true,
			savingSettings: false,
			settingsEntries: [],
			settingsDraft: {},

			// Code lists tab
			codeLists: [
				{ list: 'casestatus', label: t('opencase', 'Sagsstatus') },
				{ list: 'casetype', label: t('opencase', 'Sagstype') },
				{ list: 'contactroles', label: t('opencase', 'Kontakt roller') },
				{ list: 'documentcategory', label: t('opencase', 'Dokumentkategorier') },
				{ list: 'documentstatus', label: t('opencase', 'Dokumentstatus') },
				{ list: 'insightlevel', label: t('opencase', 'Indsigtsgrad') },
				{ list: 'participantroles', label: t('opencase', 'Parts roller') },
			],
			openList: null,

			// Incoming documents tab
			incomingLoading: true,
			incomingSaving: false,
			incomingOrgOptions: [],
			incomingOrgSearchLoading: false,
			incomingOrgSearchTimeout: null,
			selectedIncomingOrg: null,
			selectedIncomingSensitivity: null,
			selectedIncomingKle: null,
			selectedIncomingFacet: null,
			selectedIncomingInsightLevel: null,

			// Separation sheets tab
			separationSheetsLoading: true,
			separationSheets: [],
			openCreateSeparationSheet: false,
			editingSheet: null,
			printingSheetId: null,
			deletingSheetId: null,
			creatingAttachmentSheet: false,

			// Custom fields tab
			customFields: [],
			customFieldsLoading: true,
			customFieldsError: '',
			cfCaseTypes: [],
			cfOrgUnits: [],
			showCreateCustomFieldDialog: false,
			editingCustomField: null,

			// SAPA rules tab
			sapaRules: [],
			sapaRulesLoading: true,
			sapaRuleCounts: {},
			editingSapaRule: null,

			// Masking rules tab
			maskRules: [],
			maskRulesLoading: true,
			maskServiceConfigured: false,
			editingMaskRule: null,
		}
	},

	computed: {
		tabs() {
			const enterprise = this.$store.state.enterpriseVersionEnabled
			return this.allTabs.filter(tab => !tab.enterprise || enterprise)
		},
		sensitivityOptions() {
			return this.$store.state.sensitivities.map(s => ({
				id: s.uuid,
				label: s.title,
			}))
		},
		kleOptions() {
			return this.$store.state.classificationSubjects
				.filter(s => /^\d{2}\.\d{2}\.\d{2}$/.test(s.code))
				.map(s => ({
					id: s.code,
					label: `${s.code} – ${s.title}`,
				}))
		},
		facetOptions() {
			return this.$store.state.classificationFacets
				.filter(f => /^[A-Za-z]\d{2}$/.test(f.code))
				.map(f => ({
					id: f.uuid,
					label: `${f.code} – ${f.title}`,
				}))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({
				id: l.id,
				label: l.name,
			}))
		},

	},

	async mounted() {
		try {
			const [allEntries] = await Promise.all([
				api.getConfig(),
				this.$store.dispatch('fetchSensitivities'),
				this.$store.dispatch('fetchInsightLevels'),
				this.$store.dispatch('fetchClassificationFacets'),
				this.$store.dispatch('fetchClassificationSubjects'),
			])

			this.settingsEntries = allEntries.filter(e => SETTINGS_KEYS.includes(e.key))
			this.settingsDraft = Object.fromEntries(this.settingsEntries.map(e => [e.key, e.value]))

			const cfg = Object.fromEntries(allEntries.map(e => [e.key, e.value]))
			this.initIncomingSelections(cfg)

			try {
				const orgs = await api.searchOrganisations('')
				this.incomingOrgOptions = orgs.map(o => ({ id: o.uuid, label: o.label }))
				// Resolve label for the pre-selected org (we only had UUID before)
				if (this.selectedIncomingOrg) {
					const match = this.incomingOrgOptions.find(o => o.id === this.selectedIncomingOrg.id)
					if (match) {
						this.selectedIncomingOrg = match
					} else {
						// Not in first 20 — keep UUID stub so the selection isn't lost
						this.incomingOrgOptions = [this.selectedIncomingOrg, ...this.incomingOrgOptions]
					}
				}
			} catch (e) {
				// silently ignore
			}
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente konfiguration'))
		} finally {
			this.loadingSettings = false
			this.incomingLoading = false
		}

		this.fetchSeparationSheets()
		this.fetchCustomFields()
		this.fetchCustomFieldReferenceData()
		this.fetchMaskRules()
		if (this.$store.state.enterpriseVersionEnabled) {
			this.fetchSapaRules()
		}
	},

	methods: {
		// ── Separation sheets tab ───────────────────────────────────────
		async fetchSeparationSheets() {
			this.separationSheetsLoading = true
			try {
				this.separationSheets = await api.getSeparationSheets()
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente separationsark'))
			} finally {
				this.separationSheetsLoading = false
			}
		},

		separationSheetTypeLabel(type) {
			return t('opencase', SEPARATION_SHEET_TYPE_LABELS[type] || type)
		},

		isEditableSeparationSheet(sheet) {
			return EDITABLE_SEPARATION_SHEET_TYPES.includes(sheet.type)
		},

		editSeparationSheet(sheet) {
			this.editingSheet = sheet
		},

		closeSeparationSheetDialog() {
			this.openCreateSeparationSheet = false
			this.editingSheet = null
		},

		onSeparationSheetCreated(sheet) {
			this.separationSheets.unshift(sheet)
			this.closeSeparationSheetDialog()
		},

		onSeparationSheetUpdated(sheet) {
			const index = this.separationSheets.findIndex(s => s.id === sheet.id)
			if (index !== -1) {
				this.separationSheets.splice(index, 1, sheet)
			}
			this.closeSeparationSheetDialog()
			showSuccess(t('opencase', 'Separationsark gemt'))
		},

		async deleteSeparationSheet(sheet) {
			if (!window.confirm(t('opencase', 'Er du sikker på, at du vil slette separationsarket "{name}"?', { name: sheet.name }))) {
				return
			}

			this.deletingSheetId = sheet.id
			try {
				await api.deleteSeparationSheet(sheet.id)
				this.separationSheets = this.separationSheets.filter(s => s.id !== sheet.id)
				showSuccess(t('opencase', 'Separationsark slettet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke slette separationsark'))
			} finally {
				this.deletingSheetId = null
			}
		},

		async printSeparationSheet(sheet) {
			this.printingSheetId = sheet.id
			try {
				await generateAndPrintSeparationSheetPdf(sheet)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke generere PDF'))
			} finally {
				this.printingSheetId = null
			}
		},

		async createAttachmentSheet() {
			this.creatingAttachmentSheet = true
			try {
				const sheet = await api.createSeparationSheet({ type: 'attachment' })
				this.separationSheets.unshift(sheet)
				await generateAndPrintSeparationSheetPdf(sheet)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke oprette bilagsark'))
			} finally {
				this.creatingAttachmentSheet = false
			}
		},

		// ── Settings tab ──────────────────────────────────────────────
		async saveSettings() {
			this.savingSettings = true
			try {
				const changed = this.settingsEntries.filter(e => this.settingsDraft[e.key] !== e.value)
				for (const entry of changed) {
					await api.updateConfigValue(entry.key, this.settingsDraft[entry.key])
					entry.value = this.settingsDraft[entry.key]

					// searchPageSize/searchMaxResultCount are cached in the Vuex
					// store from page-load initial state — apply a changed value
					// live so already-open search views pick it up without a reload.
					if (entry.key === 'search_page_size') {
						this.$store.commit('SET_SEARCH_PAGE_SIZE', parseInt(entry.value, 10) || 50)
					} else if (entry.key === 'search_max_result_count') {
						this.$store.commit('SET_SEARCH_MAX_RESULT_COUNT', parseInt(entry.value, 10) || 500)
					}
				}
				showSuccess(t('opencase', 'Konfiguration gemt'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme konfiguration'))
			} finally {
				this.savingSettings = false
			}
		},

		// ── Code lists tab ────────────────────────────────────────────
		openCodeList(cl) {
			this.openList = cl
		},

		// ── Incoming documents tab ────────────────────────────────────
		initIncomingSelections(cfg) {
			const orgUuid = cfg['incoming_organisation'] || ''
			if (orgUuid) {
				// Pre-select with UUID; label will be populated once org options load
				this.selectedIncomingOrg = { id: orgUuid, label: orgUuid }
			}

			const sensUuid = cfg['incoming_sensitivity'] || ''
			if (sensUuid) {
				const s = this.$store.state.sensitivities.find(s => s.uuid === sensUuid)
				if (s) this.selectedIncomingSensitivity = { id: s.uuid, label: s.title }
			}

			const kleCode = cfg['incoming_kle'] || ''
			if (kleCode) {
				const s = this.$store.state.classificationSubjects.find(s => s.code === kleCode)
				if (s) this.selectedIncomingKle = { id: s.code, label: `${s.code} – ${s.title}` }
			}

			const facetUuid = cfg['incoming_facet'] || ''
			if (facetUuid) {
				const f = this.$store.state.classificationFacets.find(f => f.uuid === facetUuid)
				if (f) this.selectedIncomingFacet = { id: f.uuid, label: `${f.code} – ${f.title}` }
			}

			const insightRaw = cfg['incoming_insight_level']
			if (insightRaw) {
				const insightId = parseInt(insightRaw)
				const l = this.$store.state.insightLevels.find(l => l.id === insightId)
				if (l) this.selectedIncomingInsightLevel = { id: l.id, label: l.name }
			}
		},

		onIncomingOrgSearch(query) {
			clearTimeout(this.incomingOrgSearchTimeout)
			this.incomingOrgSearchLoading = true
			this.incomingOrgSearchTimeout = setTimeout(async () => {
				try {
					const orgs = await api.searchOrganisations(query)
					this.incomingOrgOptions = orgs.map(o => ({ id: o.uuid, label: o.label }))
				} catch (e) {
					// silently ignore search errors
				} finally {
					this.incomingOrgSearchLoading = false
				}
			}, 300)
		},

		async saveIncoming() {
			this.incomingSaving = true
			try {
				const insightId = this.selectedIncomingInsightLevel?.id
				await Promise.all([
					api.updateConfigValue('incoming_organisation', this.selectedIncomingOrg?.id || ''),
					api.updateConfigValue('incoming_sensitivity', this.selectedIncomingSensitivity?.id || ''),
					api.updateConfigValue('incoming_kle', this.selectedIncomingKle?.id || ''),
					api.updateConfigValue('incoming_facet', this.selectedIncomingFacet?.id || ''),
					api.updateConfigValue('incoming_insight_level', insightId != null ? String(insightId) : ''),
				])
				showSuccess(t('opencase', 'Konfiguration gemt'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme konfiguration'))
			} finally {
				this.incomingSaving = false
			}
		},

		// ── Custom fields tab ──────────────────────────────────────────
		async fetchCustomFields() {
			this.customFieldsLoading = true
			try {
				this.customFields = await api.getCustomFieldDefs()
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente tillægsfelter'))
			} finally {
				this.customFieldsLoading = false
			}
		},
		async fetchCustomFieldReferenceData() {
			try {
				const [caseTypes, orgs] = await Promise.all([
					api.getCaseTypes(),
					api.getOrgTree(),
				])
				this.cfCaseTypes = caseTypes
				this.cfOrgUnits = orgs
			} catch (e) {
				// silently ignore — scope picker options just stay empty
			}
		},
		cfCaseTypeName(id) {
			return this.cfCaseTypes.find((c) => String(c.id) === String(id))?.name || id
		},
		cfOrgName(uuid) {
			return this.cfOrgUnits.find((o) => o.org_uuid === uuid)?.org_name || uuid
		},
		cfScopeValueLabel(scope) {
			if (scope.scope_type === 'casetype') {
				return this.cfCaseTypeName(scope.scope_value)
			}
			if (scope.scope_type === 'organisation') {
				return this.cfOrgName(scope.scope_value)
			}
			return scope.scope_value
		},
		cfScopeTypeLabel(type) {
			return {
				casetype: t('opencase', 'Sagstype'),
				organisation: t('opencase', 'Organisation'),
				kle: t('opencase', 'KLE-nummer'),
			}[type] || type
		},
		cfFieldTypeLabel(type) {
			return {
				text: t('opencase', 'Tekst'),
				dropdown: t('opencase', 'Valgliste'),
				checkbox: t('opencase', 'Afkrydsningsfelt'),
				date: t('opencase', 'Dato'),
			}[type] || type
		},
		cfScopeSummary(field) {
			if (!field.scopes || field.scopes.length === 0) {
				return t('opencase', 'Alle sager')
			}
			return field.scopes.map((s) => `${this.cfScopeTypeLabel(s.scope_type)}: ${this.cfScopeValueLabel(s)}`).join(', ')
		},
		onCustomFieldCreated() {
			this.showCreateCustomFieldDialog = false
			this.fetchCustomFields()
		},
		async deleteCustomField(field) {
			if (!window.confirm(t('opencase', 'Er du sikker på at du vil slette feltet "{label}"? Alle gemte værdier for feltet slettes også.', { label: field.label }))) {
				return
			}
			try {
				await api.deleteCustomFieldDef(field.id)
				await this.fetchCustomFields()
			} catch (e) {
				this.customFieldsError = t('opencase', 'Kunne ikke slette feltet')
			}
		},
		// ── SAPA rules tab ─────────────────────────────────────────────
		async fetchSapaRules() {
			this.sapaRulesLoading = true
			try {
				this.sapaRules = await api.getSapaRules()
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente SAPA-regler'))
			} finally {
				this.sapaRulesLoading = false
			}
			this.fetchSapaRuleCounts()
		},
		/**
		 * Case counts are fetched per rule after the table is shown, since
		 * they can take a while on large installations.
		 */
		fetchSapaRuleCounts() {
			this.sapaRuleCounts = {}
			for (const rule of this.sapaRules) {
				api.getSapaRuleCounts(rule.id)
					.then(counts => { this.sapaRuleCounts[rule.id] = counts })
					.catch(() => { this.sapaRuleCounts[rule.id] = { error: true } })
			}
		},
		async onSapaRuleSaved() {
			this.editingSapaRule = null
			showSuccess(t('opencase', 'Reglen er gemt'))
			await this.fetchSapaRules()
		},
		async setSapaRuleActive(rule, active) {
			try {
				await api.updateSapaRule(rule.id, { active })
				rule.active = active
			} catch (e) {
				showError(t('opencase', 'Opdatering fejlede'))
			}
		},
		async deleteSapaRule(rule) {
			if (!window.confirm(t('opencase', 'Slet reglen "{name}"?', { name: rule.name }))) {
				return
			}
			try {
				await api.deleteSapaRule(rule.id)
				await this.fetchSapaRules()
			} catch (e) {
				showError(t('opencase', 'Reglen kunne ikke slettes'))
			}
		},
		// ── Masking rules tab ──────────────────────────────────────────
		async fetchMaskRules() {
			this.maskRulesLoading = true
			try {
				const result = await api.getAccessMaskRules()
				this.maskRules = result.rules ?? []
				this.maskServiceConfigured = !!result.service_configured
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente maskeringsregler'))
			} finally {
				this.maskRulesLoading = false
			}
		},
		async onMaskRuleSaved() {
			this.editingMaskRule = null
			showSuccess(t('opencase', 'Maskeringsreglen er gemt'))
			await this.fetchMaskRules()
		},
		async setMaskRuleEnabled(rule, enabled) {
			try {
				await api.updateAccessMaskRule(rule.id, { enabled })
				rule.enabled = enabled
			} catch (e) {
				showError(e?.response?.data?.ocs?.data?.error ?? t('opencase', 'Opdatering fejlede'))
			}
		},
		async deleteMaskRule(rule) {
			if (!window.confirm(t('opencase', 'Slet maskeringsreglen "{label}"?', { label: rule.label }))) {
				return
			}
			try {
				await api.deleteAccessMaskRule(rule.id)
				await this.fetchMaskRules()
			} catch (e) {
				showError(t('opencase', 'Reglen kunne ikke slettes'))
			}
		},
		async setCustomFieldActive(field, active) {
			try {
				await api.updateCustomFieldDef(field.id, { active })
				await this.fetchCustomFields()
			} catch (e) {
				this.customFieldsError = t('opencase', 'Kunne ikke opdatere feltet')
			}
		},
		onCustomFieldUpdated() {
			this.editingCustomField = null
			this.fetchCustomFields()
		},
	},
}
</script>

<style scoped>
.opencase-configuration {
	padding: 20px;
}

.opencase-configuration__content {
	max-width: 600px;
}

.opencase-configuration__content--wide {
	max-width: 900px;
}

.opencase-configuration__rule-pattern code {
	display: inline-block;
	max-width: 360px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	vertical-align: bottom;
}

.opencase-configuration__toolbar {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.opencase-configuration__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-configuration__table th,
.opencase-configuration__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.opencase-configuration__row-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.opencase-configuration__settings {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.opencase-configuration__entry {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-configuration__label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-configuration__heading {
	margin: 0 0 8px;
	font-size: 1.1em;
	font-weight: 600;
}

.opencase-configuration__description {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	margin: 0 0 2px;
	line-height: 1.4;
}

.opencase-configuration__codelists {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-configuration__codelist-btn {
	display: block;
	width: 100%;
	text-align: left;
	padding: 12px 16px;
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	font-size: var(--default-font-size);
	color: var(--color-main-text);
}

.opencase-configuration__codelist-btn:hover,
.opencase-configuration__codelist-btn:focus-visible {
	background-color: var(--color-background-hover);
	outline: none;
}

.opencase-configuration__error {
	color: #c62828;
}
</style>
