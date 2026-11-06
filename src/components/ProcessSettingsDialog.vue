<template>
	<NcDialog :name="process ? t('opencase', 'Procesindstillinger') : t('opencase', 'Ny proces')"
		size="normal"
		@close="$emit('close')">
		<div class="opencase-process-settings">
			<div class="opencase-process-settings__field">
				<label>{{ t('opencase', 'Navn') }} *</label>
				<NcTextField v-model="form.name" />
			</div>

			<div class="opencase-process-settings__field">
				<label>{{ t('opencase', 'Procesnøgle') }}</label>
				<NcTextField v-if="!process"
					v-model="form.process_key"
					:placeholder="t('opencase', 'Dannes ud fra navnet, hvis feltet er tomt')" />
				<code v-else>{{ process.process_key }}</code>
				<span class="opencase-process-settings__hint">
					{{ t('opencase', 'Teknisk id for processen i procesmotoren. Kan ikke ændres senere.') }}
				</span>
			</div>

			<div class="opencase-process-settings__field">
				<label>{{ t('opencase', 'Beskrivelse') }}</label>
				<textarea v-model="form.description" rows="3" />
			</div>

			<NcCheckboxRadioSwitch v-if="process" v-model="form.active" type="switch">
				{{ t('opencase', 'Aktiv – kan startes på sager') }}
			</NcCheckboxRadioSwitch>

			<div v-if="!process" class="opencase-process-settings__field">
				<label>{{ t('opencase', 'Importér BPMN-fil (valgfri)') }}</label>
				<input ref="fileInput"
					type="file"
					accept=".bpmn,.xml,.bpmn20.xml"
					class="opencase-process-settings__file-input"
					@change="onFileSelected">
				<div class="opencase-process-settings__file-row">
					<NcButton @click="$refs.fileInput.click()">
						{{ importFileName || t('opencase', 'Vælg fil') }}
					</NcButton>
					<NcButton v-if="importFileName" type="tertiary" @click="clearFile">
						{{ t('opencase', 'Fjern') }}
					</NcButton>
				</div>
			</div>

			<h4 class="opencase-process-settings__heading">
				{{ t('opencase', 'Afgrænsning (valgfri — uden afgrænsning kan processen startes på alle sager)') }}
			</h4>

			<ul v-if="form.scopes.length" class="opencase-process-settings__scope-list">
				<li v-for="(scope, index) in form.scopes" :key="index">
					{{ scopeTypeLabel(scope.scope_type) }}: {{ scopeValueLabel(scope) }}
					<NcButton @click="removeScope(index)">{{ t('opencase', 'Fjern') }}</NcButton>
				</li>
			</ul>

			<div class="opencase-process-settings__field">
				<label>{{ t('opencase', 'Afgræns til') }}</label>
				<NcSelect v-model="newScopeTypeOption" :options="scopeTypeOptions" :clearable="false" />

				<NcSelect v-if="newScopeType === 'casetype'"
					v-model="newScopeValueOption"
					:options="caseTypeOptions"
					:placeholder="t('opencase', 'Vælg sagstype')" />
				<NcSelect v-else-if="newScopeType === 'organisation'"
					v-model="newScopeValueOption"
					:options="orgOptions"
					:placeholder="t('opencase', 'Vælg organisation')" />
				<ClassificationPlanSelect v-else-if="newScopeType === 'class_plan'"
					v-model="newScopeValue" />
				<NcTextField v-else
					v-model="newScopeValue"
					:placeholder="t('opencase', 'F.eks. 27.69.00 eller 27.% for hele gruppen')" />

				<NcButton @click="addScope">{{ t('opencase', 'Tilføj afgrænsning') }}</NcButton>
			</div>

			<p class="opencase-process-settings__hint">
				{{ t('opencase', 'Afgrænsninger af samme type er alternativer (eller); forskellige typer skal alle være opfyldt (og). En organisation omfatter også dens underenheder.') }}
			</p>

			<p v-if="error" class="opencase-process-settings__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="!form.name.trim() || saving" @click="save">
				{{ saving ? t('opencase', 'Gemmer…') : (process ? t('opencase', 'Gem') : t('opencase', 'Opret')) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import api from '../services/api.js'
import ClassificationPlanSelect from './ClassificationPlanSelect.vue'
import { caseScopeTypeLabel, classPlanScopeLabel, ensureClassificationPlanItems, scopeTypeOptions } from '../utils/caseScopes.js'

/**
 * Create (process = null) or edit a process: name, description, active and
 * the scopes deciding which cases it can be started on. Scopes work like
 * custom field scopes.
 */
export default {
	name: 'ProcessSettingsDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, ClassificationPlanSelect },

	props: {
		process: { type: Object, default: null },
		caseTypes: { type: Array, default: () => [] },
		orgUnits: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: {
				name: this.process?.name || '',
				process_key: '',
				description: this.process?.description || '',
				active: this.process?.active ?? true,
				scopes: (this.process?.scopes || []).map(s => ({ ...s })),
			},
			importXml: null,
			importFileName: '',
			newScopeType: 'casetype',
			newScopeValue: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		scopeTypeOptions() {
			return scopeTypeOptions(this.$store.state)
		},
		newScopeTypeOption: {
			get() {
				return this.scopeTypeOptions.find(o => o.id === this.newScopeType) || null
			},
			set(v) {
				this.newScopeType = v?.id || 'casetype'
				this.newScopeValue = ''
			},
		},
		caseTypeOptions() {
			return this.caseTypes.map(ct => ({ id: String(ct.id), label: ct.name }))
		},
		orgOptions() {
			return this.orgUnits.map(o => ({ id: o.org_uuid, label: o.org_name }))
		},
		newScopeValueOption: {
			get() {
				const options = this.newScopeType === 'casetype' ? this.caseTypeOptions : this.orgOptions
				return options.find(o => o.id === this.newScopeValue) || null
			},
			set(v) {
				this.newScopeValue = v?.id || ''
			},
		},
	},

	mounted() {
		ensureClassificationPlanItems(this.$store)
	},

	methods: {
		scopeValueLabel(scope) {
			if (scope.scope_type === 'casetype') {
				return this.caseTypes.find(c => String(c.id) === String(scope.scope_value))?.name || scope.scope_value
			}
			if (scope.scope_type === 'organisation') {
				return this.orgUnits.find(o => o.org_uuid === scope.scope_value)?.org_name || scope.scope_value
			}
			if (scope.scope_type === 'class_plan') return classPlanScopeLabel(this.$store.state, scope.scope_value)
			return scope.scope_value
		},
		scopeTypeLabel(type) {
			return caseScopeTypeLabel(type)
		},
		addScope() {
			if (!this.newScopeValue) return
			const exists = this.form.scopes.some(s => s.scope_type === this.newScopeType && s.scope_value === this.newScopeValue)
			if (!exists) {
				this.form.scopes.push({ scope_type: this.newScopeType, scope_value: this.newScopeValue })
			}
			this.newScopeValue = ''
		},
		removeScope(index) {
			this.form.scopes.splice(index, 1)
		},
		async onFileSelected(event) {
			const file = event.target.files?.[0]
			if (!file) return
			this.importXml = await file.text()
			this.importFileName = file.name
		},
		clearFile() {
			this.importXml = null
			this.importFileName = ''
			this.$refs.fileInput.value = ''
		},
		async save() {
			this.error = ''
			this.saving = true
			try {
				const payload = {
					name: this.form.name,
					description: this.form.description,
					scopes: this.form.scopes,
				}
				let saved
				if (this.process) {
					saved = await api.updateProcess(this.process.id, { ...payload, active: this.form.active })
				} else {
					saved = await api.createProcess({
						...payload,
						process_key: this.form.process_key,
						bpmn_xml: this.importXml,
					})
				}
				this.$emit('saved', saved)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke gemme processen')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-process-settings { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-process-settings__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-process-settings__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-process-settings__field textarea { width: 100%; resize: vertical; }
.opencase-process-settings__hint { font-size: 0.85em; color: var(--color-text-maxcontrast); margin: 0; }
.opencase-process-settings__heading { margin: 8px 0 0; font-size: 1em; font-weight: 600; }
.opencase-process-settings__error { color: #c62828; margin: 0; }
.opencase-process-settings__file-input { display: none; }
.opencase-process-settings__file-row { display: flex; gap: 8px; align-items: center; }

.opencase-process-settings__scope-list {
	margin: 0;
	padding-left: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.opencase-process-settings__scope-list li {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
