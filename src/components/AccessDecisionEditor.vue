<template>
	<div class="oc-decision">
		<div class="oc-decision__templates">
			<p class="oc-decision__label">{{ t('opencase', 'Standardtekster') }}</p>
			<div class="oc-decision__template-chips">
				<NcButton v-for="tpl in templates" :key="tpl.key"
					size="small"
					type="secondary"
					@click="applyTemplate(tpl)">
					{{ tpl.label }}
				</NcButton>
			</div>
		</div>

		<label class="oc-decision__select-label">{{ t('opencase', 'Afgørelse') }}</label>
		<NcSelect v-model="form.decision"
			:options="decisionOptions"
			:placeholder="t('opencase', 'Vælg afgørelse...')"
			class="oc-decision__field" />

		<div class="oc-decision__field">
			<NcTextArea v-model="form.decision_text"
				:label="t('opencase', 'Afgørelsestekst')"
				:rows="8" />
		</div>

		<div class="oc-decision__field">
			<NcTextArea v-model="form.complaint_guidance"
				:label="t('opencase', 'Klagevejledning')"
				:rows="3" />
		</div>

		<div class="oc-decision__actions">
			<NcButton type="primary" :disabled="saving || !form.decision" @click="save">
				<template #icon><NcLoadingIcon v-if="saving" :size="20" /></template>
				{{ t('opencase', 'Gem afgørelse') }}
			</NcButton>
			<NcButton v-if="decision && !decision.approved_at"
				type="secondary"
				:disabled="approving"
				@click="approve">
				<template #icon><CheckIcon :size="20" /></template>
				{{ t('opencase', 'Godkend') }}
			</NcButton>
			<span v-if="decision && decision.approved_at" class="oc-decision__approved">
				<CheckCircleIcon :size="18" /> {{ t('opencase', 'Godkendt af {user}', { user: decision.approved_by }) }}
			</span>
		</div>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'AccessDecisionEditor',
	components: { NcSelect, NcTextArea, NcButton, NcLoadingIcon, CheckIcon, CheckCircleIcon },
	props: {
		requestId: { type: Number, required: true },
		decision:  { type: Object, default: null },
	},
	emits: ['saved'],
	data() {
		const dec = this.decision
		return {
			saving: false,
			approving: false,
			templates: [],
			form: {
				decision: dec ? { id: dec.decision, label: this.labelFor(dec.decision) } : null,
				decision_text:    dec?.decision_text ?? '',
				complaint_guidance: dec?.complaint_guidance ?? '',
			},
			decisionOptions: [
				{ id: 'full_access',     label: t('opencase', 'Fuld aktindsigt') },
				{ id: 'partial_access',  label: t('opencase', 'Delvis aktindsigt') },
				{ id: 'rejected',        label: t('opencase', 'Afslag') },
				{ id: 'withdrawn',       label: t('opencase', 'Tilbagetrukket') },
			],
		}
	},
	mounted() {
		api.getAccessDecisionTemplates().then(r => { this.templates = r.templates ?? [] }).catch(() => {})
	},
	methods: {
		labelFor(id) {
			const m = { full_access: 'Fuld aktindsigt', partial_access: 'Delvis aktindsigt', rejected: 'Afslag', withdrawn: 'Tilbagetrukket' }
			return t('opencase', m[id] ?? id)
		},
		applyTemplate(tpl) {
			this.form.decision      = { id: tpl.decision, label: this.labelFor(tpl.decision) }
			this.form.decision_text = tpl.text
		},
		async save() {
			this.saving = true
			try {
				const d = await api.saveAccessDecision(this.requestId, {
					decision:           this.form.decision?.id,
					decision_text:      this.form.decision_text,
					complaint_guidance: this.form.complaint_guidance,
				})
				showSuccess(t('opencase', 'Afgørelse gemt'))
				this.$emit('saved', d)
			} catch {
				showError(t('opencase', 'Kunne ikke gemme afgørelse'))
			} finally {
				this.saving = false
			}
		},
		async approve() {
			this.approving = true
			try {
				const d = await api.approveAccessDecision(this.requestId)
				showSuccess(t('opencase', 'Afgørelse godkendt'))
				this.$emit('saved', d)
			} catch {
				showError(t('opencase', 'Godkendelse fejlede'))
			} finally {
				this.approving = false
			}
		},
	},
}
</script>

<style scoped>
.oc-decision { display: flex; flex-direction: column; }
.oc-decision__label { font-weight: 600; margin-bottom: 6px; font-size: 0.9em; }
.oc-decision__template-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
.oc-decision__select-label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 0.9em; }
.oc-decision__field { margin-bottom: 12px; }
.oc-decision__field :deep(.textarea__main-wrapper) { height: auto; }
.oc-decision__actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.oc-decision__approved { display: flex; align-items: center; gap: 4px; color: #2ea043; font-size: 0.9em; }
</style>
