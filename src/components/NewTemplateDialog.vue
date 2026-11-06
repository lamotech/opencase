<template>
	<NcDialog :name="t('opencase', 'Ny skabelon')"
		size="large"
		@closing="$emit('close')">
		<div class="opencase-tpl-dialog">
			<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Tab 1: Generelt -->
			<div v-show="activeTab === 'general'" class="opencase-tpl-dialog__panel">
				<div class="opencase-tpl-dialog__field">
					<label>{{ t('opencase', 'Navn') }}</label>
					<NcTextField v-model="name"
						:placeholder="mode === 'upload' ? t('opencase', 'Vises som filnavn hvis tomt') : t('opencase', 'Ny skabelon')" />
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

				<div class="opencase-tpl-dialog__field">
					<label>{{ t('opencase', 'Fil') }}</label>
					<label class="opencase-tpl-dialog__radio">
						<input v-model="mode" type="radio" value="blank">
						{{ t('opencase', 'Ny fil') }}
					</label>
					<p v-if="mode === 'blank'" class="opencase-tpl-dialog__hint">
						{{ t('opencase', 'Der oprettes et tomt dokument, som åbnes til redigering i Nextcloud Office.') }}
					</p>
					<label class="opencase-tpl-dialog__radio">
						<input v-model="mode" type="radio" value="upload">
						{{ t('opencase', 'Upload fil') }}
					</label>
					<div v-if="mode === 'upload'" class="opencase-tpl-dialog__upload">
						<input ref="fileInput"
							type="file"
							accept=".docx,.odt,.xlsx,.ods"
							class="opencase-tpl-dialog__file-input"
							@change="onFileSelected">
						<NcButton @click="$refs.fileInput.click()">
							<template #icon>
								<UploadIcon :size="16" />
							</template>
							{{ selectedFile ? selectedFile.name : t('opencase', 'Vælg fil') }}
						</NcButton>
						<span class="opencase-tpl-dialog__hint">
							{{ t('opencase', 'Word (.docx), ODF tekst (.odt), Excel (.xlsx) eller ODF regneark (.ods). Fraser og logo kan kun indsættes i .docx og .odt.') }}
						</span>
						<div v-if="saving && uploadProgress > 0" class="opencase-tpl-dialog__progress">
							<progress :value="uploadProgress" max="100" />
							<span>{{ uploadProgress }}%</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Tab 2: Fraser -->
			<div v-show="activeTab === 'phrases'" class="opencase-tpl-dialog__panel">
				<TemplatePhrasePicker v-model="phrases"
					:org-uuids="orgUuids"
					:orgs="orgs"
					:template-format="mode === 'upload' && selectedFile ? extensionOf(selectedFile.name) : null"
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
			<NcButton type="primary" :disabled="!canSave" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="16" />
					<PlusIcon v-else :size="16" />
				</template>
				{{ mode === 'blank' ? t('opencase', 'Opret og rediger') : t('opencase', 'Opret') }}
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
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import OverflowTabs from './OverflowTabs.vue'
import TemplatePhrasePicker from './TemplatePhrasePicker.vue'
import api from '../services/api.js'
import { orgOptions } from '../utils/phrases.js'

/**
 * Create a template — a new blank file (opened in Nextcloud Office) or an
 * uploaded file — and attach phrases to it.
 *
 * Emits "created" with the new template (including phrase_count).
 */
export default {
	name: 'NewTemplateDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcNoteCard,
		NcLoadingIcon,
		NcSelect,
		UploadIcon,
		PlusIcon,
		OverflowTabs,
		TemplatePhrasePicker,
	},

	emits: ['close', 'created'],

	data() {
		return {
			activeTab: 'general',
			name: '',
			mode: 'blank',
			selectedFile: null,
			phrases: [],
			orgs: [],
			orgUuids: [],
			mismatchCount: 0,
			saving: false,
			uploadProgress: 0,
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
		canSave() {
			return !this.saving && !this.mismatchCount && (this.mode === 'blank' || this.selectedFile !== null)
		},
	},

	async mounted() {
		try {
			this.orgs = await api.getOrgTree()
		} catch (e) {
			// organisation picker just stays empty
		}
	},

	methods: {
		extensionOf(filename) {
			const dot = filename.lastIndexOf('.')
			return dot > 0 ? filename.slice(dot + 1).toLowerCase() : ''
		},

		onFileSelected(event) {
			this.selectedFile = event.target.files[0] ?? null
			this.error = ''
		},

		async save() {
			this.error = ''
			this.saving = true
			this.uploadProgress = 0
			// File, organisations and phrases in one request — the server keeps
			// nothing if any part is invalid.
			const setup = { org_uuids: this.orgUuids, phrases: this.phrases }
			try {
				const template = this.mode === 'blank'
					? await api.createBlankTemplate(this.name.trim() || t('opencase', 'Ny skabelon'), setup)
					: await api.uploadTemplate(this.selectedFile, this.name.trim() || null, (e) => {
						if (e.total) this.uploadProgress = Math.round((e.loaded / e.total) * 100)
					}, setup)
				this.$emit('created', template)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error
					?? (this.mode === 'blank' ? t('opencase', 'Kunne ikke oprette skabelon') : t('opencase', 'Upload mislykkedes'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-tpl-dialog { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; min-height: 320px; }
.opencase-tpl-dialog__hint { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.opencase-tpl-dialog__panel { display: flex; flex-direction: column; gap: 14px; }
.opencase-tpl-dialog__field { display: flex; flex-direction: column; gap: 6px; }
.opencase-tpl-dialog__field > label:first-child { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-tpl-dialog__radio { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.opencase-tpl-dialog__hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 0 0 0 24px; }
.opencase-tpl-dialog__upload { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; margin-left: 24px; }
.opencase-tpl-dialog__file-input { display: none; }
.opencase-tpl-dialog__progress { display: flex; align-items: center; gap: 8px; width: 100%; }
.opencase-tpl-dialog__progress progress { flex: 1; height: 6px; }
</style>
