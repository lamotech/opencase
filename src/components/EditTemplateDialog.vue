<template>
	<NcDialog :name="t('opencase', 'Rediger skabelon')"
		size="large"
		@closing="$emit('close')">
		<div class="opencase-tpl-dialog">
			<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Tab 1: Generelt -->
			<div v-show="activeTab === 'general'" class="opencase-tpl-dialog__panel">
				<div class="opencase-tpl-dialog__field">
					<label>{{ t('opencase', 'Navn') }} *</label>
					<NcTextField v-model="name" />
				</div>

				<div class="opencase-tpl-dialog__field">
					<label>{{ t('opencase', 'Organisationer') }}</label>
					<NcSelect v-model="orgSelection"
						:options="orgChoices"
						:multiple="true"
						:close-on-select="false"
						:placeholder="t('opencase', 'Alle organisationer')" />
					<span class="opencase-tpl-dialog__hint">
						{{ t('opencase', 'Skabelonen kan kun bruges i sager under de valgte organisationer. Uden valg kan den bruges i alle sager.') }}
					</span>
				</div>

				<dl class="opencase-tpl-dialog__info">
					<dt>{{ t('opencase', 'Filnavn') }}</dt>
					<dd>{{ template.original_filename }}</dd>
					<dt>{{ t('opencase', 'Størrelse') }}</dt>
					<dd>{{ formatSize(template.size) }}</dd>
					<dt>{{ t('opencase', 'Uploadet af') }}</dt>
					<dd>{{ template.uploaded_by }}</dd>
					<dt>{{ t('opencase', 'Dato') }}</dt>
					<dd>{{ formatDate(template.created_at) }}</dd>
				</dl>
			</div>

			<!-- Tab 2: Fraser -->
			<div v-show="activeTab === 'phrases'" class="opencase-tpl-dialog__panel">
				<NcLoadingIcon v-if="loadingPhrases" :size="32" />
				<TemplatePhrasePicker v-else
					v-model="phrases"
					:org-uuids="orgUuids"
					:orgs="orgs"
					:template-format="(template.original_filename || '').split('.').pop().toLowerCase()"
					@mismatch="mismatchCount = $event" />
			</div>

			<NcNoteCard v-if="mismatchCount" type="warning">
				{{ n('opencase', '{count} frase matcher ikke skabelonens organisationer. Fjern den under Fraser, eller ret organisationerne.', '{count} fraser matcher ikke skabelonens organisationer. Fjern dem under Fraser, eller ret organisationerne.', mismatchCount, { count: mismatchCount }) }}
			</NcNoteCard>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || loadingPhrases || !name.trim() || mismatchCount > 0" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="16" />
				</template>
				{{ saving ? t('opencase', 'Gemmer…') : t('opencase', 'Gem') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import OverflowTabs from './OverflowTabs.vue'
import TemplatePhrasePicker from './TemplatePhrasePicker.vue'
import api from '../services/api.js'
import { orgOptions } from '../utils/phrases.js'

/**
 * Edit a template's info (name) and attached phrases.
 *
 * Emits "saved" with the updated template (including phrase_count).
 */
export default {
	name: 'EditTemplateDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcNoteCard,
		NcLoadingIcon,
		NcSelect,
		OverflowTabs,
		TemplatePhrasePicker,
	},

	props: {
		template: { type: Object, required: true },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			activeTab: 'general',
			name: this.template.name,
			phrases: [],
			orgs: [],
			orgUuids: [...(this.template.org_uuids || [])],
			mismatchCount: 0,
			loadingPhrases: true,
			saving: false,
			error: '',
		}
	},

	computed: {
		orgChoices() {
			return orgOptions(this.orgs)
		},
		orgSelection: {
			get() {
				return this.orgChoices.filter(o => this.orgUuids.includes(o.id))
			},
			set(options) {
				this.orgUuids = (options || []).map(o => o.id)
			},
		},
		tabs() {
			return [
				{ id: 'general', label: t('opencase', 'Generelt') },
				{ id: 'phrases', label: t('opencase', 'Fraser'), count: this.phrases.length || null },
			]
		},
	},

	async mounted() {
		api.getOrgTree().then(orgs => { this.orgs = orgs }).catch(() => {})
		try {
			const rows = await api.getTemplatePhrases(this.template.id)
			this.phrases = rows.map(r => ({
				phrase_id: r.phrase_id,
				is_required: r.is_required,
				is_default: r.is_default,
			}))
		} catch (e) {
			this.error = t('opencase', 'Kunne ikke hente skabelonens fraser')
		} finally {
			this.loadingPhrases = false
		}
	},

	methods: {
		async save() {
			this.error = ''
			this.saving = true
			try {
				const template = await api.updateTemplate(this.template.id, {
					name: this.name.trim(),
					org_uuids: this.orgUuids,
				})
				template.phrase_count = (await api.setTemplatePhrases(this.template.id, this.phrases)).length
				this.$emit('saved', template)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke gemme skabelonen')
			} finally {
				this.saving = false
			}
		},

		formatSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
		},

		formatDate(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.opencase-tpl-dialog { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; min-height: 320px; }
.opencase-tpl-dialog__hint { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.opencase-tpl-dialog__panel { display: flex; flex-direction: column; gap: 14px; }
.opencase-tpl-dialog__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-tpl-dialog__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-tpl-dialog__info {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0;
}
.opencase-tpl-dialog__info dt { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-tpl-dialog__info dd { margin: 0; overflow-wrap: anywhere; }
</style>
