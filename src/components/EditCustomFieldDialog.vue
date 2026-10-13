<template>
	<NcDialog :name="t('opencase', 'Rediger felt')"
		size="normal"
		@close="$emit('close')">
		<div class="opencase-edit-cf">
			<div class="opencase-edit-cf__field">
				<label>{{ t('opencase', 'Navn') }} *</label>
				<NcTextField v-model="form.label" />
			</div>

			<div class="opencase-edit-cf__field">
				<label>{{ t('opencase', 'Type') }}</label>
				<span class="opencase-edit-cf__static">{{ fieldTypeLabel }}</span>
			</div>

			<div v-if="field.field_type === 'dropdown'" class="opencase-edit-cf__field">
				<label>{{ t('opencase', 'Valgmuligheder') }}</label>
				<ul v-if="options.length" class="opencase-edit-cf__option-list">
					<li v-for="opt in options" :key="opt">{{ opt }}</li>
				</ul>
				<div class="opencase-edit-cf__add-option">
					<NcTextField v-model="newOption" :placeholder="t('opencase', 'Ny valgmulighed')" />
					<NcButton @click="addOption">{{ t('opencase', 'Tilføj') }}</NcButton>
				</div>
			</div>

			<div class="opencase-edit-cf__field">
				<label>{{ t('opencase', 'Påkrævet') }}</label>
				<NcCheckboxRadioSwitch v-model="form.required" type="switch" />
			</div>

			<h4 class="opencase-edit-cf__heading">
				{{ t('opencase', 'Afgrænsning (valgfri — uden afgrænsning gælder feltet alle sager)') }}
			</h4>

			<ul v-if="form.scopes.length" class="opencase-edit-cf__scope-list">
				<li v-for="(scope, index) in form.scopes" :key="index">
					{{ scopeTypeLabel(scope.scope_type) }}: {{ scopeValueLabel(scope) }}
					<NcButton @click="removeScope(index)">{{ t('opencase', 'Fjern') }}</NcButton>
				</li>
			</ul>

			<div class="opencase-edit-cf__field">
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
				<NcTextField v-else
					v-model="newScopeValue"
					:placeholder="t('opencase', 'F.eks. 27.69.00 eller 27.% for hele gruppen')" />

				<NcButton @click="addScope">{{ t('opencase', 'Tilføj afgrænsning') }}</NcButton>
			</div>

			<p v-if="error" class="opencase-edit-cf__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="!form.label || saving" @click="save">
				{{ saving ? t('opencase', 'Gemmer…') : t('opencase', 'Gem') }}
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

const FIELD_TYPE_LABELS = {
	text: 'Tekst',
	dropdown: 'Valgliste',
	checkbox: 'Afkrydsningsfelt',
	date: 'Dato',
}

export default {
	name: 'EditCustomFieldDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch },

	props: {
		field: { type: Object, required: true },
		caseTypes: { type: Array, default: () => [] },
		orgUnits: { type: Array, default: () => [] },
	},

	emits: ['close', 'updated'],

	data() {
		return {
			form: {
				label: this.field.label,
				required: this.field.required,
				scopes: this.field.scopes.map(s => ({ ...s })),
			},
			options: [...this.field.options],
			newOption: '',
			newScopeType: 'casetype',
			newScopeValue: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		fieldTypeLabel() {
			return t('opencase', FIELD_TYPE_LABELS[this.field.field_type] || this.field.field_type)
		},
		scopeTypeOptions() {
			return [
				{ id: 'casetype', label: t('opencase', 'Sagstype') },
				{ id: 'organisation', label: t('opencase', 'Organisation') },
				{ id: 'kle', label: t('opencase', 'KLE-nummer') },
			]
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

	methods: {
		caseTypeName(id) {
			return this.caseTypes.find((c) => String(c.id) === String(id))?.name || id
		},
		orgName(uuid) {
			return this.orgUnits.find((o) => o.org_uuid === uuid)?.org_name || uuid
		},
		scopeValueLabel(scope) {
			if (scope.scope_type === 'casetype') return this.caseTypeName(scope.scope_value)
			if (scope.scope_type === 'organisation') return this.orgName(scope.scope_value)
			return scope.scope_value
		},
		scopeTypeLabel(type) {
			return {
				casetype: t('opencase', 'Sagstype'),
				organisation: t('opencase', 'Organisation'),
				kle: t('opencase', 'KLE-nummer'),
			}[type] || type
		},
		addScope() {
			if (!this.newScopeValue) return
			this.form.scopes.push({ scope_type: this.newScopeType, scope_value: this.newScopeValue })
			this.newScopeValue = ''
		},
		removeScope(index) {
			this.form.scopes.splice(index, 1)
		},
		addOption() {
			const value = this.newOption.trim()
			if (!value || this.options.includes(value)) return
			this.options.push(value)
			this.newOption = ''
		},
		async save() {
			this.error = ''
			this.saving = true
			try {
				const payload = {
					label: this.form.label,
					required: this.form.required,
					scopes: this.form.scopes,
				}
				if (this.field.field_type === 'dropdown') {
					payload.options = this.options
				}
				const updated = await api.updateCustomFieldDef(this.field.id, payload)
				this.$emit('updated', updated)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke gemme feltet')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-edit-cf { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-edit-cf__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-edit-cf__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-edit-cf__static { color: var(--color-text-lighter); }
.opencase-edit-cf__heading { margin: 8px 0 0; font-size: 1em; font-weight: 600; }
.opencase-edit-cf__error { color: #c62828; margin: 0; }

.opencase-edit-cf__option-list {
	margin: 0;
	padding-left: 18px;
}

.opencase-edit-cf__add-option {
	display: flex;
	gap: 8px;
	align-items: center;
}

.opencase-edit-cf__scope-list {
	margin: 0;
	padding-left: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.opencase-edit-cf__scope-list li {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
