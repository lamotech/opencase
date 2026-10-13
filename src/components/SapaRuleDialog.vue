<template>
	<NcDialog :name="rule ? t('opencase', 'Rediger regel') : t('opencase', 'Opret regel')"
		size="normal"
		@close="$emit('close')">
		<div class="opencase-sapa-rule">
			<div class="opencase-sapa-rule__field">
				<label>{{ t('opencase', 'Navn') }} *</label>
				<NcTextField v-model="form.name" />
			</div>

			<div class="opencase-sapa-rule__field">
				<label>{{ t('opencase', 'Sortering') }}</label>
				<NcTextField v-model="form.sort_order" type="number" />
			</div>

			<h4 class="opencase-sapa-rule__heading">
				{{ t('opencase', 'Afgrænsning (valgfri — uden afgrænsning gælder reglen alle sager)') }}
			</h4>

			<ul v-if="form.scopes.length" class="opencase-sapa-rule__scope-list">
				<li v-for="(scope, index) in form.scopes" :key="index">
					{{ scopeTypeLabel(scope.scope_type) }}: {{ scopeValueLabel(scope) }}
					<NcButton @click="removeScope(index)">{{ t('opencase', 'Fjern') }}</NcButton>
				</li>
			</ul>

			<div class="opencase-sapa-rule__field">
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

			<p v-if="error" class="opencase-sapa-rule__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="!form.name || saving" @click="save">
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
import api from '../services/api.js'

/**
 * Create (rule = null) or edit a rule deciding which cases are transferred
 * to SagDokumentIndeks (SAPA). Scopes work like custom field scopes.
 */
export default {
	name: 'SapaRuleDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect },

	props: {
		rule: { type: Object, default: null },
		caseTypes: { type: Array, default: () => [] },
		orgUnits: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: {
				name: this.rule?.name || '',
				sort_order: String(this.rule?.sort_order ?? 0),
				scopes: (this.rule?.scopes || []).map(s => ({ ...s })),
			},
			newScopeType: 'casetype',
			newScopeValue: '',
			saving: false,
			error: '',
		}
	},

	computed: {
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
		scopeValueLabel(scope) {
			if (scope.scope_type === 'casetype') {
				return this.caseTypes.find(c => String(c.id) === String(scope.scope_value))?.name || scope.scope_value
			}
			if (scope.scope_type === 'organisation') {
				return this.orgUnits.find(o => o.org_uuid === scope.scope_value)?.org_name || scope.scope_value
			}
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
		async save() {
			this.error = ''
			this.saving = true
			try {
				const payload = {
					name: this.form.name,
					sort_order: parseInt(this.form.sort_order, 10) || 0,
					scopes: this.form.scopes,
				}
				const saved = this.rule
					? await api.updateSapaRule(this.rule.id, payload)
					: await api.createSapaRule(payload)
				this.$emit('saved', saved)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke gemme reglen')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-sapa-rule { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-sapa-rule__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-sapa-rule__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-sapa-rule__heading { margin: 8px 0 0; font-size: 1em; font-weight: 600; }
.opencase-sapa-rule__error { color: #c62828; margin: 0; }

.opencase-sapa-rule__scope-list {
	margin: 0;
	padding-left: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.opencase-sapa-rule__scope-list li {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
