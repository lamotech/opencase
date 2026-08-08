<template>
	<div class="template-manager">
		<div class="template-manager__header">
			<div class="template-manager__header-row">
				<div>
					<h2>{{ t('opencase', 'Administrer skabeloner') }}</h2>
					<p class="template-manager__subtitle">
						{{ t('opencase', 'Upload og administrer dokumentskabeloner') }}
					</p>
					<div class="template-manager__header-actions">
						<NcButton type="secondary" @click="openFieldsPage">
							<template #icon>
								<FormatListBulletedIcon :size="16" />
							</template>
							{{ t('opencase', 'Skabelonfelter') }}
						</NcButton>
						<NcButton type="primary" :disabled="creatingBlank" @click="openNewTemplateDialog">
							<template #icon>
								<NcLoadingIcon v-if="creatingBlank" :size="16" />
								<PlusIcon v-else :size="16" />
							</template>
							{{ t('opencase', 'Ny skabelon') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- New blank template dialog -->
		<NcDialog v-if="showNewTemplateDialog"
			:name="t('opencase', 'Ny skabelon')"
			@closing="showNewTemplateDialog = false">
			<p style="margin-bottom: 12px">
				{{ t('opencase', 'Angiv et navn til den nye skabelon. Filen oprettes i din Nextcloud-mappe OpenCase/Skabeloner og åbnes til redigering.') }}
			</p>
			<NcTextField
				:label="t('opencase', 'Navn')"
				v-model="newTemplateName"
				:placeholder="t('opencase', 'Ny skabelon')"
				@keyup.enter="createBlankTemplate" />
			<NcNoteCard v-if="createBlankError" type="error">
				{{ createBlankError }}
			</NcNoteCard>
			<template #actions>
				<NcButton type="tertiary" @click="showNewTemplateDialog = false">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="primary" :disabled="creatingBlank" @click="createBlankTemplate">
					<template #icon>
						<NcLoadingIcon v-if="creatingBlank" :size="16" />
						<PlusIcon v-else :size="16" />
					</template>
					{{ t('opencase', 'Opret og rediger') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Upload area -->
		<div class="template-manager__upload">
			<h3>{{ t('opencase', 'Upload skabelon') }}</h3>
			<div class="upload-form">
				<NcTextField
					:label="t('opencase', 'Navn (valgfrit)')"
					v-model="uploadName"
					:placeholder="t('opencase', 'Vises som filnavn hvis tomt')" />

				<div class="upload-form__file-row">
					<input
						ref="fileInput"
						type="file"
						class="upload-form__file-input"
						@change="onFileSelected" />
					<NcButton
						type="secondary"
						@click="$refs.fileInput.click()">
						<template #icon>
							<UploadIcon :size="16" />
						</template>
						{{ selectedFile ? selectedFile.name : t('opencase', 'Vælg fil') }}
					</NcButton>

					<NcButton
						type="primary"
						:disabled="!selectedFile || uploading"
						@click="uploadTemplate">
						<template #icon>
							<NcLoadingIcon v-if="uploading" :size="16" />
							<UploadIcon v-else :size="16" />
						</template>
						{{ uploading ? t('opencase', 'Uploader…') : t('opencase', 'Upload') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="uploadError" type="error">
					{{ uploadError }}
				</NcNoteCard>

				<div v-if="uploading" class="upload-form__progress">
					<progress :value="uploadProgress" max="100" />
					<span>{{ uploadProgress }}%</span>
				</div>
			</div>
		</div>

		<!-- Template list -->
		<div class="template-manager__list">
			<div class="template-manager__list-header">
				<h3>{{ t('opencase', 'Skabeloner') }}</h3>
				<NcTextField
					class="template-manager__filter"
					:label="t('opencase', 'Filtrer efter navn')"
					v-model="nameFilter"
					:placeholder="t('opencase', 'Filtrer efter navn…')"
					trailing-button-icon="close"
					:show-trailing-button="nameFilter !== ''"
					@trailing-button-click="nameFilter = ''" />
			</div>

			<NcEmptyContent v-if="!loading && templates.length === 0"
				:name="t('opencase', 'Ingen skabeloner')">
				<template #icon>
					<FileDocumentOutlineIcon :size="64" />
				</template>
				<template #description>
					{{ t('opencase', 'Upload en skabelon for at komme i gang') }}
				</template>
			</NcEmptyContent>

			<NcEmptyContent v-else-if="!loading && filteredTemplates.length === 0"
				:name="t('opencase', 'Ingen skabeloner matcher filteret')">
				<template #icon>
					<FileDocumentOutlineIcon :size="64" />
				</template>
			</NcEmptyContent>

			<NcLoadingIcon v-if="loading" :size="32" class="template-manager__loading" />

			<table v-if="!loading && pagedTemplates.length > 0" class="template-list">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Navn') }}</th>
						<th>{{ t('opencase', 'Filnavn') }}</th>
						<th>{{ t('opencase', 'Størrelse') }}</th>
						<th>{{ t('opencase', 'Uploadet af') }}</th>
						<th>{{ t('opencase', 'Dato') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="template in pagedTemplates" :key="template.id">
						<td>{{ template.name }}</td>
						<td>{{ template.original_filename }}</td>
						<td>{{ formatSize(template.size) }}</td>
						<td>{{ template.uploaded_by }}</td>
						<td>{{ formatDate(template.created_at) }}</td>
						<td class="template-list__actions">
							<NcButton
								type="tertiary"
								:title="t('opencase', 'Rediger i Nextcloud Office')"
								:disabled="template._opening"
								@click="editTemplate(template)">
								<template #icon>
									<NcLoadingIcon v-if="template._opening" :size="16" />
									<PencilIcon v-else :size="16" />
								</template>
							</NcButton>
							<NcButton
								type="tertiary"
								:title="t('opencase', 'Download')"
								@click="downloadTemplate(template)">
								<template #icon>
									<DownloadIcon :size="16" />
								</template>
							</NcButton>
							<NcButton
								type="tertiary-no-background"
								:title="t('opencase', 'Slet')"
								@click="confirmDelete(template)">
								<template #icon>
									<DeleteIcon :size="16" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Pagination -->
			<div v-if="filteredTemplates.length > pageSize" class="template-manager__pagination">
				<NcButton :disabled="currentPage === 1"
					@click="currentPage--">
					{{ t('opencase', 'Forrige') }}
				</NcButton>
				<span class="template-manager__page-info">
					{{ t('opencase', '{start}–{end} af {total}', {
						start: (currentPage - 1) * pageSize + 1,
						end: Math.min(currentPage * pageSize, filteredTemplates.length),
						total: filteredTemplates.length,
					}) }}
				</span>
				<NcButton :disabled="currentPage >= totalPages"
					@click="currentPage++">
					{{ t('opencase', 'Næste') }}
				</NcButton>
			</div>
		</div>

		<!-- Delete confirmation dialog -->
		<NcDialog v-if="templateToDelete"
			:name="t('opencase', 'Slet skabelon')"
			@closing="templateToDelete = null">
			<p>
				{{ t('opencase', 'Er du sikker på, at du vil slette "{name}"?', { name: templateToDelete.name }) }}
			</p>
			<template #actions>
				<NcButton type="tertiary" @click="templateToDelete = null">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="error" @click="deleteTemplate">
					{{ t('opencase', 'Slet') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { generateUrl } from '@nextcloud/router'

import UploadIcon from 'vue-material-design-icons/Upload.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'

import api from '../services/api.js'

export default {
	name: 'TemplateManagerView',

	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		NcNoteCard,
		NcDialog,
		UploadIcon,
		DownloadIcon,
		DeleteIcon,
		FileDocumentOutlineIcon,
		PlusIcon,
		PencilIcon,
		FormatListBulletedIcon,
	},

	data() {
		return {
			loading: false,
			templates: [],
			// Filter & pagination state
			nameFilter: '',
			currentPage: 1,
			pageSize: 20,
			// Upload state
			selectedFile: null,
			uploadName: '',
			uploading: false,
			uploadProgress: 0,
			uploadError: null,
			// Delete state
			templateToDelete: null,
			// New blank template state
			showNewTemplateDialog: false,
			newTemplateName: '',
			creatingBlank: false,
			createBlankError: null,
		}
	},

	async mounted() {
		await this.loadTemplates()
	},

	computed: {
		filteredTemplates() {
			const query = this.nameFilter.trim().toLowerCase()
			if (!query) return this.templates
			return this.templates.filter(t => t.name?.toLowerCase().includes(query))
		},

		totalPages() {
			return Math.max(1, Math.ceil(this.filteredTemplates.length / this.pageSize))
		},

		pagedTemplates() {
			const start = (this.currentPage - 1) * this.pageSize
			return this.filteredTemplates.slice(start, start + this.pageSize)
		},
	},

	watch: {
		nameFilter() {
			this.currentPage = 1
		},

		totalPages(newTotal) {
			if (this.currentPage > newTotal) {
				this.currentPage = newTotal
			}
		},
	},

	methods: {
		async loadTemplates() {
			this.loading = true
			try {
				this.templates = await api.getTemplates()
			} finally {
				this.loading = false
			}
		},

		onFileSelected(event) {
			this.selectedFile = event.target.files[0] ?? null
			this.uploadError = null
		},

		async uploadTemplate() {
			if (!this.selectedFile) return

			this.uploading = true
			this.uploadProgress = 0
			this.uploadError = null

			try {
				const template = await api.uploadTemplate(
					this.selectedFile,
					this.uploadName || null,
					(progressEvent) => {
						if (progressEvent.total) {
							this.uploadProgress = Math.round((progressEvent.loaded / progressEvent.total) * 100)
						}
					},
				)
				this.templates.unshift(template)
				this.selectedFile = null
				this.uploadName = ''
				this.$refs.fileInput.value = ''
			} catch (err) {
				this.uploadError = err.response?.data?.ocs?.data?.error
					?? t('opencase', 'Upload mislykkedes')
			} finally {
				this.uploading = false
				this.uploadProgress = 0
			}
		},

		async downloadTemplate(template) {
			await api.downloadTemplate(template.id, template.original_filename)
		},

		confirmDelete(template) {
			this.templateToDelete = template
		},

		async deleteTemplate() {
			const template = this.templateToDelete
			this.templateToDelete = null
			try {
				await api.deleteTemplate(template.id)
				this.templates = this.templates.filter(t => t.id !== template.id)
			} catch (err) {
				// Re-fetch to stay in sync
				await this.loadTemplates()
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

		openFieldsPage() {
			window.open(generateUrl('/apps/opencase/templates/fields'), '_blank')
		},

		openNewTemplateDialog() {
			this.newTemplateName = ''
			this.createBlankError = null
			this.showNewTemplateDialog = true
		},

		async createBlankTemplate() {
			this.creatingBlank = true
			this.createBlankError = null
			try {
				const template = await api.createBlankTemplate(this.newTemplateName || 'Ny skabelon')
				this.templates.unshift(template)
				this.showNewTemplateDialog = false
				if (template.nc_file_id) {
					window.open(generateUrl('/f/' + template.nc_file_id), '_blank')
				}
			} catch (err) {
				this.createBlankError = err.response?.data?.ocs?.data?.error
					?? t('opencase', 'Kunne ikke oprette skabelon')
			} finally {
				this.creatingBlank = false
			}
		},

		async editTemplate(template) {
			template._opening = true
			try {
				const ncFileId = await api.openTemplateForEdit(template.id)
				window.open(generateUrl('/f/' + ncFileId), '_blank')
			} catch (e) {
				console.error('OpenCase: failed to open template for edit', e)
			} finally {
				template._opening = false
			}
		},
	},
}
</script>

<style scoped>
.template-manager {
	padding: 20px;
	max-width: 900px;
}

.template-manager__header {
	margin-bottom: 24px;
}

.template-manager__header-row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.template-manager__header-actions {
	display: flex;
	gap: 8px;
	flex-shrink: 0;
}

.template-manager__header h2 {
	font-size: 1.4em;
	font-weight: 600;
	margin: 0 0 4px;
}

.template-manager__subtitle {
	color: var(--color-text-lighter);
	margin: 0;
}

.template-manager__upload,
.template-manager__list {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.template-manager__upload h3,
.template-manager__list h3 {
	font-size: 1em;
	font-weight: 600;
	margin: 0 0 16px;
}

.template-manager__list-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.template-manager__list-header h3 {
	margin: 0 0 16px;
}

.template-manager__filter {
	width: 260px;
	margin-bottom: 16px;
}

.template-manager__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 16px;
}

.template-manager__page-info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.upload-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.upload-form__file-input {
	display: none;
}

.upload-form__file-row {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.upload-form__progress {
	display: flex;
	align-items: center;
	gap: 8px;
}

.upload-form__progress progress {
	flex: 1;
	height: 6px;
}

.template-manager__loading {
	display: block;
	margin: 24px auto;
}

.template-list {
	width: 100%;
	border-collapse: collapse;
}

.template-list th,
.template-list td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.template-list th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
}

.template-list__actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}
</style>
