<template>
	<div class="oc-redaction">
		<div class="oc-redaction__header">
			<h4>{{ t('opencase', 'Maskeringer for: {title}', { title: item.title }) }}</h4>
			<NcButton size="small" @click="showForm = !showForm">
				<template #icon><PlusIcon :size="16" /></template>
				{{ t('opencase', 'Tilføj maskering') }}
			</NcButton>
		</div>

		<!-- Add form -->
		<div v-if="showForm" class="oc-redaction__form">
			<label class="oc-redaction__select-label">{{ t('opencase', 'Type') }}</label>
			<NcSelect v-model="form.redaction_type"
				:options="typeOptions"
				label="label"
				:placeholder="t('opencase', 'Vælg type...')"
				class="oc-redaction__field" />
			<NcTextField v-model="form.original_text"
				:label="t('opencase', 'Tekst der skal maskeres')"
				class="oc-redaction__field" />
			<NcTextField v-model="form.replacement_text"
				:label="t('opencase', 'Erstatning')"
				class="oc-redaction__field" />
			<NcTextField v-model="form.legal_reference"
				:label="t('opencase', 'Juridisk reference (fx §30)')"
				class="oc-redaction__field" />
			<NcTextArea v-model="form.reason"
				:label="t('opencase', 'Begrundelse')"
				class="oc-redaction__field" />
			<div class="oc-redaction__form-actions">
				<NcButton @click="showForm = false">{{ t('opencase', 'Annuller') }}</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ t('opencase', 'Gem maskering') }}
				</NcButton>
			</div>
		</div>

		<!-- List -->
		<NcEmptyContent v-if="redactions.length === 0 && !showForm"
			:title="t('opencase', 'Ingen maskeringer endnu')" />

		<table v-else-if="redactions.length > 0" class="oc-redaction__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Type') }}</th>
					<th>{{ t('opencase', 'Tekst') }}</th>
					<th>{{ t('opencase', 'Erstatning') }}</th>
					<th>{{ t('opencase', 'Reference') }}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="r in redactions" :key="r.id">
					<td>{{ typeLabel(r.redaction_type) }}</td>
					<td class="oc-redaction__original">{{ r.original_text ?? '—' }}</td>
					<td>{{ r.replacement_text }}</td>
					<td>{{ r.legal_reference ?? '—' }}</td>
					<td>
						<NcButton size="small" type="tertiary-no-background" @click="remove(r)">
							<template #icon><DeleteIcon :size="16" /></template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

const TYPE_LABELS = {
	manual:       'Manuel',
	cpr:          'CPR-nummer',
	name:         'Navn',
	address:      'Adresse',
	email:        'E-mail',
	phone:        'Telefon',
	health:       'Helbredsoplysning',
	confidential: 'Fortrolig',
	other:        'Andet',
}

export default {
	name: 'AccessRedactionList',
	components: { NcButton, NcTextField, NcTextArea, NcSelect, NcEmptyContent, PlusIcon, DeleteIcon },
	props: {
		item:       { type: Object, required: true },
		requestId:  { type: Number, required: true },
	},
	emits: ['updated'],
	data() {
		return {
			showForm: false,
			saving: false,
			redactions: [...(this.item.redactions ?? [])],
			form: {
				redaction_type: { id: 'manual', label: t('opencase', 'Manuel') },
				original_text: '',
				replacement_text: '████',
				legal_reference: '',
				reason: '',
			},
			typeOptions: Object.entries(TYPE_LABELS).map(([id, label]) => ({ id, label: t('opencase', label) })),
		}
	},
	methods: {
		typeLabel(type) { return t('opencase', TYPE_LABELS[type] ?? type) },

		async save() {
			this.saving = true
			try {
				const r = await api.addAccessRedaction(this.item.id, {
					redaction_type:   this.form.redaction_type?.id ?? 'manual',
					original_text:    this.form.original_text,
					replacement_text: this.form.replacement_text,
					legal_reference:  this.form.legal_reference,
					reason:           this.form.reason,
				})
				this.redactions.push(r)
				this.showForm = false
				this.form = { redaction_type: { id: 'manual', label: t('opencase', 'Manuel') }, original_text: '', replacement_text: '████', legal_reference: '', reason: '' }
				this.$emit('updated')
			} catch {
				showError(t('opencase', 'Maskering kunne ikke gemmes'))
			} finally {
				this.saving = false
			}
		},

		async remove(r) {
			try {
				await api.removeAccessRedaction(r.id)
				this.redactions = this.redactions.filter(x => x.id !== r.id)
				this.$emit('updated')
			} catch {
				showError(t('opencase', 'Sletning fejlede'))
			}
		},
	},
}
</script>

<style scoped>
.oc-redaction__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.oc-redaction__form { background: var(--color-background-hover); border-radius: 8px; padding: 16px; margin-bottom: 16px; }
.oc-redaction__select-label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 0.9em; }
.oc-redaction__field { margin-bottom: 10px; }
.oc-redaction__form-actions { display: flex; gap: 8px; justify-content: flex-end; }
.oc-redaction__table { width: 100%; border-collapse: collapse; }
.oc-redaction__table th,
.oc-redaction__table td { padding: 8px 12px; border-bottom: 1px solid var(--color-border); text-align: left; }
.oc-redaction__table th { font-weight: 600; background: var(--color-background-hover); }
.oc-redaction__original { font-family: monospace; color: var(--color-error); }
</style>
