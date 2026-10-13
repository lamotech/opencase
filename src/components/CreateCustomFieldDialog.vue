<template>
	<NcDialog :name="t('opencase', 'Opret nyt felt')"
		size="normal"
		@close="$emit('close')">
		<div class="opencase-create-cf">
			<div class="opencase-create-cf__field">
				<label>{{ t('opencase', 'Navn') }} *</label>
				<NcTextField v-model="form.label"
					:placeholder="t('opencase', 'F.eks. Byggesagsnummer')"
					@update:model-value="onLabelInput" />
			</div>

			<div class="opencase-create-cf__field">
				<label>{{ t('opencase', 'Type') }}</label>
				<NcSelect v-model="fieldTypeOption" :options="fieldTypeOptions" :clearable="false" />
			</div>

			<div v-if="form.field_type === 'dropdown'" class="opencase-create-cf__field">
				<label>{{ t('opencase', 'Valgmuligheder') }}</label>
				<NcTextField v-model="form.options"
					:placeholder="t('opencase', 'Kommasepareret, f.eks. Rød,Gul,Grøn')" />
			</div>

			<div class="opencase-create-cf__field">
				<label>{{ t('opencase', 'Påkrævet') }}</label>
				<NcCheckboxRadioSwitch v-model="form.required" type="switch" />
			</div>

			<h4 class="opencase-create-cf__heading">
				{{ t('opencase', 'Afgrænsning (valgfri — uden afgrænsning gælder feltet alle sager)') }}
			</h4>

			<ul v-if="form.scopes.length" class="opencase-create-cf__scope-list">
				<li v-for="(scope, index) in form.scopes" :key="index">
					{{ scopeTypeLabel(scope.scope_type) }}: {{ scopeValueLabel(scope) }}
					<NcButton @click="removeScope(index)">{{ t('opencase', 'Fjern') }}</NcButton>
				</li>
			</ul>

			<div class="opencase-create-cf__field">
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

			<p v-if="error" class="opencase-create-cf__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!form.field_key || !form.label || saving"
				@click="save">
				{{ saving ? t('opencase', 'Opretter…') : t('opencase', 'Opret felt') }}
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

export default {
	name: 'CreateCustomFieldDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch },

	props: {
		caseTypes: { type: Array, default: () => [] },
		orgUnits: { type: Array, default: () => [] },
	},

	emits: ['close', 'created'],

	data() {
		return {
			form: { field_key: '', label: '', field_type: 'text', options: '', required: false, scopes: [] },
			newScopeType: 'casetype',
			newScopeValue: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		fieldTypeOptions() {
			return [
				{ id: 'text', label: t('opencase', 'Tekst') },
				{ id: 'dropdown', label: t('opencase', 'Valgliste') },
				{ id: 'checkbox', label: t('opencase', 'Afkrydsningsfelt') },
				{ id: 'date', label: t('opencase', 'Dato') },
			]
		},
		fieldTypeOption: {
			get() {
				return this.fieldTypeOptions.find(o => o.id === this.form.field_type) || null
			},
			set(v) {
				this.form.field_type = v?.id || 'text'
			},
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
		slugifyFieldKey(text) {
			return text
				.trim()
				.replace(/[æÆ]/g, 'ae')
				.replace(/[øØ]/g, 'oe')
				.replace(/[åÅ]/g, 'aa')
				.toLowerCase()
				.replace(/[^a-z0-9_]+/g, '_')
				.replace(/^_+|_+$/g, '')
				.slice(0, 64)
		},
		onLabelInput() {
			this.form.field_key = this.slugifyFieldKey(this.form.label)
		},
		async save() {
			this.error = ''
			this.saving = true
			try {
				const options = this.form.field_type === 'dropdown'
					? this.form.options.split(',').map((o) => o.trim()).filter((o) => o !== '')
					: []
				const field = await api.createCustomFieldDef({
					field_key: this.form.field_key,
					label: this.form.label,
					field_type: this.form.field_type,
					options,
					required: this.form.required,
					scopes: this.form.scopes,
				})
				this.$emit('created', field)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke oprette feltet')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-create-cf { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-create-cf__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-create-cf__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-create-cf__heading { margin: 8px 0 0; font-size: 1em; font-weight: 600; }
.opencase-create-cf__error { color: #c62828; margin: 0; }

.opencase-create-cf__scope-list {
	margin: 0;
	padding-left: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.opencase-create-cf__scope-list li {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
