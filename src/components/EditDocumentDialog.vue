<template>
	<NcDialog :name="t('opencase', 'Rediger dokument')"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-edit-doc">
			<div class="opencase-edit-doc__field">
				<label>{{ t('opencase', 'Titel') }}</label>
				<NcTextField v-model="form.title" />
			</div>
			<div class="opencase-edit-doc__field">
				<label>{{ t('opencase', 'Dokumenttype') }}</label>
				<NcSelect v-model="selectedType"
					:options="typeOptions"
					:clearable="true"
					@update:model-value="v => form.document_type = v?.id || null" />
			</div>
			<div class="opencase-edit-doc__field">
				<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
				<NcSelect v-model="selectedInsightLevel"
					:options="insightLevelOptions"
					:placeholder="t('opencase', 'Vælg indsigtsgrad')"
					:clearable="true"
					@update:model-value="v => form.insight_level_id = v?.id || null" />
			</div>
			<div class="opencase-edit-doc__field">
				<label>{{ t('opencase', 'Status') }}</label>
				<NcSelect v-model="selectedStatus"
					:options="statusOptions"
					@update:model-value="v => form.status = v?.id || null" />
			</div>
			<div class="opencase-edit-doc__field">
				<label>{{ t('opencase', 'Dokumentdato') }}</label>
				<input v-model="form.document_date" type="date" class="opencase-edit-doc__date-input" />
			</div>
			<div class="opencase-edit-doc__field">
				<label>{{ t('opencase', 'Modtaget dato') }}</label>
				<input v-model="form.received_date" type="date" class="opencase-edit-doc__date-input" />
			</div>
			<div class="opencase-edit-doc__field">
				<label>{{ t('opencase', 'Registreret dato') }}</label>
				<input v-model="form.registered_date" type="date" class="opencase-edit-doc__date-input" />
			</div>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Annuller') }}</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				{{ t('opencase', 'Gem') }}
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
import { showError, showSuccess } from '@nextcloud/dialogs'

function today() {
	return new Date().toISOString().split('T')[0]
}

export default {
	name: 'EditDocumentDialog',
	components: { NcDialog, NcButton, NcTextField, NcSelect },
	props: {
		document: { type: Object, required: true },
	},
	data() {
		return {
			form: {
				title: this.document.title,
				document_type: null,
				status: null,
				insight_level_id: this.document.insight_level_id || null,
				document_date: this.document.document_date || null,
				received_date: this.document.received_date || null,
				registered_date: this.document.registered_date || today(),
			},
			selectedType: this.document.document_type ? { id: this.document.document_type, label: this.document.document_type } : null,
			selectedStatus: { id: this.document.status, label: this.document.status_name },
			selectedInsightLevel: this.document.insight_level_id
				? { id: this.document.insight_level_id, label: this.document.insight_level_name || '' }
				: null,
			insightLevelOptions: [],
			saving: false,
			typeOptions: [
				{ id: 'afgørelse', label: 'Afgørelse' }, { id: 'notat', label: 'Notat' },
				{ id: 'brev', label: 'Brev' }, { id: 'bilag', label: 'Bilag' },
				{ id: 'ansøgning', label: 'Ansøgning' }, { id: 'referat', label: 'Referat' },
			],
			statusOptions: [],
		}
	},
	async created() {
		try {
			const statuses = await api.getDocumentStatuses()
			this.statusOptions = statuses.map(s => ({ id: s.id, label: s.name }))
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente statusliste'))
		}
		try {
			const levels = await api.getInsightLevels()
			this.insightLevelOptions = levels.map(l => ({ id: l.id, label: l.name }))
			if (this.form.insight_level_id) {
				const match = this.insightLevelOptions.find(o => o.id === this.form.insight_level_id)
				if (match) this.selectedInsightLevel = match
			}
		} catch (e) {
			// silently ignore
		}
	},
	methods: {
		async save() {
			this.saving = true
			try {
				const updated = await api.updateDocument(this.document.id, {
					...this.form,
					document_date: this.form.document_date || null,
					received_date: this.form.received_date || null,
					registered_date: this.form.registered_date || null,
				})
				showSuccess(t('opencase', 'Dokument opdateret'))
				this.$emit('saved', updated)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke opdatere'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-edit-doc { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-edit-doc__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-edit-doc__field label { font-weight: 600; font-size: 0.9em; }
.opencase-edit-doc__date-input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 3px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1em;
}
</style>
