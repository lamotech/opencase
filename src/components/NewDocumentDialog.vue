<template>
	<NcDialog :name="t('opencase', 'Opret nyt dokument')"
		size="large"
		@update:open="v => !v && $emit('close')">

		<OverflowTabs :tabs="tabs" :value="tab" @input="tab = $event" />

		<!-- Info tab -->
		<div v-show="tab === 'info'" class="ndd-content">
			<div class="ndd-field">
				<label>{{ t('opencase', 'Titel') }} *</label>
				<NcTextField v-model="title"
					:placeholder="t('opencase', 'Dokumenttitel')"
					:error="!!titleError"
					:helper-text="titleError"
					@keyup.enter="save" />
			</div>
			<div class="ndd-field">
				<label>{{ t('opencase', 'Dokumentkategori') }} *</label>
				<NcSelect v-model="selectedCategory"
					:options="categoryOptions"
					:placeholder="t('opencase', 'Vælg kategori')"
					:clearable="false" />
				<span v-if="categoryError" class="ndd-field__error">{{ categoryError }}</span>
			</div>
			<div class="ndd-field">
				<label>{{ t('opencase', 'Dokumenttype') }}</label>
				<NcSelect v-model="selectedType"
					:options="typeOptions"
					:placeholder="t('opencase', 'Vælg type (valgfri)')"
					:clearable="true" />
			</div>
			<div class="ndd-field">
				<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
				<NcSelect v-model="selectedInsightLevel"
					:options="insightLevelOptions"
					:placeholder="t('opencase', 'Vælg indsigtsgrad')"
					:clearable="true" />
			</div>
			<div class="ndd-field">
				<label>{{ t('opencase', 'Dokumentdato') }}</label>
				<input v-model="documentDate" type="date" class="ndd-date-input" />
			</div>
			<div class="ndd-field">
				<label>{{ t('opencase', 'Modtaget dato') }}</label>
				<input v-model="receivedDate" type="date" class="ndd-date-input" />
			</div>
			<div class="ndd-field">
				<label>{{ t('opencase', 'Registreret dato') }}</label>
				<input v-model="registeredDate" type="date" class="ndd-date-input" />
			</div>
		</div>

		<!-- Files tab -->
		<div v-show="tab === 'files'" class="ndd-content">
			<!-- Staged uploads -->
			<div class="ndd-files-section">
				<div class="ndd-files-section__header">
					<span class="ndd-files-section__title">{{ t('opencase', 'Upload filer') }}</span>
					<NcButton type="tertiary" @click="$refs.fileInput.click()">
						<template #icon><UploadIcon :size="16" /></template>
						{{ t('opencase', 'Tilføj filer') }}
					</NcButton>
					<input ref="fileInput" type="file" multiple style="display:none" @change="onFilesSelected" />
				</div>
				<ul v-if="pendingFiles.length > 0" class="ndd-staged-list">
					<li v-for="(file, i) in pendingFiles" :key="'f' + i" class="ndd-staged-list__item">
						<FileIcon :size="18" class="ndd-staged-list__icon" />
						<span class="ndd-staged-list__name">{{ file.name }}</span>
						<span class="ndd-staged-list__size">{{ formatSize(file.size) }}</span>
						<NcButton type="tertiary" :aria-label="t('opencase', 'Fjern')" @click="pendingFiles.splice(i, 1)">
							<template #icon><CloseIcon :size="16" /></template>
						</NcButton>
					</li>
				</ul>
				<p v-else class="ndd-files-section__empty">{{ t('opencase', 'Ingen filer tilføjet') }}</p>
			</div>

			<!-- Staged templates -->
			<div class="ndd-files-section">
				<div class="ndd-files-section__header">
					<span class="ndd-files-section__title">{{ t('opencase', 'Fra skabelon') }}</span>
				</div>
				<NcLoadingIcon v-if="templatesLoading" :size="24" />
				<p v-else-if="templates.length === 0" class="ndd-files-section__empty">
					{{ t('opencase', 'Ingen skabeloner tilgængelige') }}
				</p>
				<ul v-else class="ndd-template-list">
					<li v-for="tpl in templates"
						:key="tpl.id"
						class="ndd-template-list__item"
						:class="{ 'ndd-template-list__item--selected': isTemplateSelected(tpl.id) }"
						@click="toggleTemplate(tpl)">
						<component :is="mimeIcon(tpl.mime_type)" :size="20" class="ndd-template-list__icon" />
						<span class="ndd-template-list__name">{{ tpl.name }}</span>
						<CheckIcon v-if="isTemplateSelected(tpl.id)" :size="18" class="ndd-template-list__check" />
					</li>
				</ul>
			</div>
		</div>

		<!-- Contacts tab -->
		<div v-show="tab === 'contacts'" class="ndd-content">
			<DocumentContactList ref="contactList" :document-id="null" :can-write="true" @count-changed="contactsCount = $event" />
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon><NcLoadingIcon :size="16" /></template>
				{{ saving ? t('opencase', 'Opretter…') : t('opencase', 'Opret') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FileWordIcon from 'vue-material-design-icons/FileWord.vue'
import FileExcelIcon from 'vue-material-design-icons/FileExcel.vue'
import FilePdfIcon from 'vue-material-design-icons/FilePdfBox.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import OverflowTabs from './OverflowTabs.vue'
import DocumentContactList from './DocumentContactList.vue'
import api from '../services/api.js'

const MIME_ICONS = {
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': FileWordIcon,
	'application/msword': FileWordIcon,
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': FileExcelIcon,
	'application/vnd.ms-excel': FileExcelIcon,
	'application/pdf': FilePdfIcon,
}

function today() {
	return new Date().toISOString().split('T')[0]
}

export default {
	name: 'NewDocumentDialog',
	components: {
		NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon,
		UploadIcon, CloseIcon, CheckIcon, FileIcon, FileWordIcon, FileExcelIcon, FilePdfIcon,
		OverflowTabs, DocumentContactList,
	},
	props: {
		caseId: { type: Number, required: true },
	},
	data() {
		return {
			tab: 'info',

			// Info fields
			title: '',
			selectedCategory: null,
			selectedType: null,
			selectedInsightLevel: null,
			insightLevelInitialized: false,
			documentDate: today(),
			receivedDate: '',
			registeredDate: today(),

			// Validation
			titleError: '',
			categoryError: '',

			// Saving state
			saving: false,

			// Options
			categoryOptions: [],
			typeOptions: [],

			// Files tab
			pendingFiles: [],
			pendingTemplates: [],
			templates: [],
			templatesLoading: true,

			// Contacts tab
			contactsCount: null,
		}
	},
	computed: {
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({ id: l.id, label: l.name }))
		},
		tabs() {
			return [
				{ id: 'info', label: t('opencase', 'Info'), icon: InformationOutlineIcon },
				{ id: 'files', label: t('opencase', 'Filer'), count: (this.pendingFiles.length + this.pendingTemplates.length) || null, icon: FolderOutlineIcon },
				{ id: 'contacts', label: t('opencase', 'Afsendere/Modtagere'), count: this.contactsCount, icon: EmailOutlineIcon },
			]
		},
	},
	watch: {
		insightLevelOptions(options) {
			if (!this.insightLevelInitialized && options.length > 0) {
				this.initInsightLevel(options)
				this.insightLevelInitialized = true
			}
		},
	},
	async mounted() {
		// Load categories
		try {
			const categories = await api.getDocumentCategories()
			this.categoryOptions = categories.map(c => ({ id: c.id, label: c.name }))
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente kategorier'))
		}

		// Load document types (user-selectable ones only)
		try {
			const types = await api.getDocumentTypes()
			this.typeOptions = types.map(ty => ({ id: ty.name, label: ty.name }))
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente dokumenttyper'))
		}

		// Load templates
		try {
			this.templates = await api.getTemplates()
		} catch (e) {
			// silently ignore — show empty state
		} finally {
			this.templatesLoading = false
		}

		// Load insight levels
		this.$store.dispatch('fetchInsightLevels').then(() => {
			if (!this.insightLevelInitialized) {
				this.initInsightLevel(this.insightLevelOptions)
				this.insightLevelInitialized = true
			}
		})
	},
	methods: {
		initInsightLevel(options) {
			const caseInsightLevelId = this.$store.state.currentCase?.insight_level_id
			if (caseInsightLevelId) {
				this.selectedInsightLevel = options.find(o => o.id === caseInsightLevelId) || null
			}
		},

		onFilesSelected(event) {
			const files = Array.from(event.target.files)
			this.pendingFiles.push(...files)
			event.target.value = ''
		},

		isTemplateSelected(id) {
			return this.pendingTemplates.some(t => t.id === id)
		},

		toggleTemplate(tpl) {
			const idx = this.pendingTemplates.findIndex(t => t.id === tpl.id)
			if (idx >= 0) {
				this.pendingTemplates.splice(idx, 1)
			} else {
				this.pendingTemplates.push(tpl)
			}
		},

		async save() {
			this.titleError = ''
			this.categoryError = ''

			if (!this.title.trim()) {
				this.titleError = t('opencase', 'Titel er påkrævet')
				this.tab = 'info'
				return
			}
			if (!this.selectedCategory) {
				this.categoryError = t('opencase', 'Dokumentkategori er påkrævet')
				this.tab = 'info'
				return
			}

			this.saving = true
			try {
				// 1. Create the document
				const doc = await this.$store.dispatch('createDocument', {
					caseId: this.caseId,
					payload: {
						title: this.title,
						document_type: this.selectedType?.id || null,
						insight_level_id: this.selectedInsightLevel?.id || null,
						document_date: this.documentDate || null,
						received_date: this.receivedDate || null,
						registered_date: this.registeredDate || null,
						document_category_id: this.selectedCategory.id,
					},
				})

				// 2. Upload staged local files
				for (const file of this.pendingFiles) {
					try {
						await this.$store.dispatch('uploadFile', {
							documentId: doc.id,
							file,
							onProgress: () => {},
						})
					} catch (e) {
						showError(t('opencase', 'Kunne ikke uploade {name}', { name: file.name }))
					}
				}

				// 3. Create staged template files
				for (const tpl of this.pendingTemplates) {
					try {
						await api.createFileFromTemplate(doc.id, tpl.id)
					} catch (e) {
						showError(t('opencase', 'Kunne ikke oprette fil fra skabelon {name}', { name: tpl.name }))
					}
				}

				// 4. Attach staged senders/receivers
				const pendingContacts = this.$refs.contactList?.contacts || []
				for (const c of pendingContacts) {
					const { id, role_name, ...payload } = c
					try {
						await api.createDocumentContact(doc.id, payload)
					} catch (e) {
						showError(t('opencase', 'Dokument oprettet, men en afsender/modtager kunne ikke tilføjes'))
					}
				}

				this.$emit('created', doc)
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke oprette dokument'))
			} finally {
				this.saving = false
			}
		},

		mimeIcon(mimeType) {
			for (const [prefix, icon] of Object.entries(MIME_ICONS)) {
				if (mimeType && mimeType.startsWith(prefix)) return icon
			}
			return FileIcon
		},

		formatSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / 1048576).toFixed(1) + ' MB'
		},
	},
}
</script>

<style scoped>
/* Shared content wrapper */
.ndd-content {
	display: flex;
	flex-direction: column;
	gap: 14px;
	min-height: 300px;
}

/* Info tab fields */
.ndd-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ndd-field label {
	font-weight: 600;
	font-size: 0.9em;
}

.ndd-field__error {
	color: var(--color-error);
	font-size: 0.85em;
}

.ndd-date-input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 3px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 1em;
}

/* Files tab sections */
.ndd-files-section {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 14px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ndd-files-section__header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ndd-files-section__title {
	font-weight: 600;
	font-size: 0.9em;
	flex: 1;
}

.ndd-files-section__empty {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	margin: 0;
}

/* Staged file list */
.ndd-staged-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ndd-staged-list__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 6px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.ndd-staged-list__icon {
	flex-shrink: 0;
	color: var(--color-text-lighter);
}

.ndd-staged-list__name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 0.9em;
}

.ndd-staged-list__size {
	font-size: 0.8em;
	color: var(--color-text-lighter);
	white-space: nowrap;
	flex-shrink: 0;
}

/* Template list */
.ndd-template-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ndd-template-list__item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 10px;
	border-radius: var(--border-radius-large);
	cursor: pointer;
	border: 2px solid transparent;
	transition: background 0.15s, border-color 0.15s;
}

.ndd-template-list__item:hover {
	background: var(--color-background-hover);
}

.ndd-template-list__item--selected {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

.ndd-template-list__icon {
	flex-shrink: 0;
	color: var(--color-text-lighter);
}

.ndd-template-list__item--selected .ndd-template-list__icon {
	color: var(--color-primary-element);
}

.ndd-template-list__name {
	flex: 1;
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ndd-template-list__check {
	flex-shrink: 0;
	color: var(--color-primary-element);
}
</style>
