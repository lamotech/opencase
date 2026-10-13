<template>
	<NcDialog :name="rule ? t('opencase', 'Rediger maskeringsregel') : t('opencase', 'Ny maskeringsregel')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="oc-rule">
			<NcTextField v-model="form.label"
				:label="t('opencase', 'Navn')"
				:placeholder="t('opencase', 'F.eks. Nummerplader')"
				:required="true" />

			<label class="oc-rule__label">{{ t('opencase', 'Type') }}</label>
			<NcSelect v-model="kindOption"
				:options="kindOptions"
				label="label"
				:clearable="false" />

			<template v-if="form.kind === 'terms'">
				<NcTextArea v-model="form.pattern"
					:label="t('opencase', 'Ord og udtryk – ét pr. linje')"
					:placeholder="'Jens Jensen\nmisbrug\nanbringelse'"
					resize="vertical"
					rows="6" />
				<p class="oc-rule__hint">
					{{ t('opencase', 'Hvert ord eller udtryk findes som hele ord, uanset store og små bogstaver.') }}
				</p>
			</template>
			<template v-else>
				<NcTextField v-model="form.pattern"
					:label="t('opencase', 'Mønster (regulært udtryk)')"
					class="oc-rule__pattern" />
				<p class="oc-rule__hint">
					{{ t('opencase', 'Python-syntaks, f.eks. \\b[A-Z]{2} ?\\d{2} ?\\d{3}\\b for nummerplader. Skriv (?i) først for at ignorere store og små bogstaver.') }}
				</p>

				<label class="oc-rule__label">{{ t('opencase', 'Ekstra kontrol') }}</label>
				<NcSelect v-model="validatorOption"
					:options="validatorOptions"
					label="label"
					:clearable="false" />
			</template>

			<NcTextField v-model="form.legal_reference"
				:label="t('opencase', 'Hjemmel (valgfri)')"
				:placeholder="t('opencase', 'F.eks. Offentlighedsloven § 30, nr. 1')" />

			<NcTextField v-model="form.sort_order"
				:label="t('opencase', 'Rækkefølge')"
				type="number" />
			<p class="oc-rule__hint">
				{{ t('opencase', 'Regler køres i stigende rækkefølge. Tekst, som en tidligere regel har fundet, bliver ikke fundet igen af en senere regel.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('opencase', 'Aktiv') }}
			</NcCheckboxRadioSwitch>

			<fieldset class="oc-rule__test">
				<legend>{{ t('opencase', 'Test reglen') }}</legend>
				<NcTextArea v-model="testText"
					:label="t('opencase', 'Eksempeltekst')"
					resize="vertical"
					rows="3" />
				<div class="oc-rule__test-row">
					<NcButton :disabled="testing || !form.pattern.trim() || !testText.trim()" @click="runTest">
						<template #icon>
							<NcLoadingIcon v-if="testing" :size="20" />
						</template>
						{{ t('opencase', 'Test') }}
					</NcButton>
					<span v-if="testResult !== null" class="oc-rule__test-result">
						<template v-if="testResult.length === 0">{{ t('opencase', 'Ingen fund') }}</template>
						<template v-else>
							{{ n('opencase', '{count} fund:', '{count} fund:', testResult.length, { count: testResult.length }) }}
							<code v-for="m in testResult" :key="m" class="oc-rule__match">{{ m }}</code>
						</template>
					</span>
				</div>
				<p v-if="!serviceConfigured" class="oc-rule__hint">
					{{ t('opencase', 'Maskeringstjenesten er ikke sat op, så reglen kan ikke testes eller kontrolleres.') }}
				</p>
			</fieldset>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !form.label.trim() || !form.pattern.trim()" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('opencase', 'Gem') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

const errorMessage = (e, fallback) => e?.response?.data?.ocs?.data?.error ?? fallback

export default {
	name: 'MaskRuleDialog',
	components: { NcDialog, NcButton, NcTextField, NcTextArea, NcSelect, NcCheckboxRadioSwitch, NcLoadingIcon },
	props: {
		// Rule to edit; null creates a new one
		rule: { type: Object, default: null },
		serviceConfigured: { type: Boolean, default: false },
	},
	emits: ['close', 'saved'],
	data() {
		const r = this.rule
		return {
			saving: false,
			testing: false,
			testText: '',
			testResult: null,
			form: {
				label: r?.label ?? '',
				kind: r?.kind ?? 'terms',
				pattern: r?.pattern ?? '',
				validator: r?.validator ?? null,
				legal_reference: r?.legal_reference ?? '',
				sort_order: r ? String(r.sort_order) : '',
				enabled: r?.enabled ?? true,
			},
			kindOptions: [
				{ id: 'terms', label: t('opencase', 'Ordliste') },
				{ id: 'regex', label: t('opencase', 'Mønster (regulært udtryk)') },
			],
			validatorOptions: [
				{ id: null, label: t('opencase', 'Ingen') },
				{ id: 'cpr_date', label: t('opencase', 'CPR – de første 6 cifre skal være en gyldig dato') },
				{ id: 'not_date', label: t('opencase', 'Spring 8 cifre over, der ligner en dato') },
			],
		}
	},
	computed: {
		kindOption: {
			get() { return this.kindOptions.find(o => o.id === this.form.kind) },
			set(o) { this.form.kind = o.id },
		},
		validatorOption: {
			get() { return this.validatorOptions.find(o => o.id === this.form.validator) ?? this.validatorOptions[0] },
			set(o) { this.form.validator = o.id },
		},
	},
	watch: {
		form: {
			deep: true,
			handler() { this.testResult = null },
		},
	},
	methods: {
		payload() {
			const p = {
				label: this.form.label.trim(),
				kind: this.form.kind,
				pattern: this.form.pattern,
				validator: this.form.kind === 'regex' ? this.form.validator : null,
				legal_reference: this.form.legal_reference.trim(),
				enabled: this.form.enabled,
			}
			if (String(this.form.sort_order).trim() !== '') {
				p.sort_order = parseInt(this.form.sort_order, 10) || 0
			}
			return p
		},
		async runTest() {
			this.testing = true
			try {
				this.testResult = await api.testAccessMaskRule({ ...this.payload(), text: this.testText })
			} catch (e) {
				showError(errorMessage(e, t('opencase', 'Testen fejlede')))
			} finally {
				this.testing = false
			}
		},
		async save() {
			this.saving = true
			try {
				const saved = this.rule
					? await api.updateAccessMaskRule(this.rule.id, this.payload())
					: await api.createAccessMaskRule(this.payload())
				this.$emit('saved', saved)
			} catch (e) {
				showError(errorMessage(e, t('opencase', 'Reglen kunne ikke gemmes')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.oc-rule { display: flex; flex-direction: column; gap: 10px; }
.oc-rule__label { font-weight: 500; font-size: 0.9em; margin-bottom: -6px; }
.oc-rule__pattern :deep(input) { font-family: var(--font-face-monospace, monospace); }
.oc-rule__hint { margin: -4px 0 0; font-size: 0.85em; color: var(--color-text-maxcontrast); }
.oc-rule__test { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 8px 12px 12px; display: flex; flex-direction: column; gap: 8px; }
.oc-rule__test legend { font-weight: 600; padding: 0 4px; }
.oc-rule__test-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.oc-rule__test-result { font-size: 0.9em; }
.oc-rule__match { margin-inline-start: 6px; padding: 1px 6px; border-radius: 6px; background: var(--color-background-dark); }
</style>
