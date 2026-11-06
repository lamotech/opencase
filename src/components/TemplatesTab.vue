<template>
	<div class="templates-tab">
		<div class="template-manager__toolbar">
			<NcButton type="secondary" @click="openFieldsPage">
				<template #icon>
					<FormatListBulletedIcon :size="16" />
				</template>
				{{ t('opencase', 'Skabelonfelter') }}
			</NcButton>
			<NcButton type="primary" @click="showNewDialog = true">
				<template #icon>
					<PlusIcon :size="16" />
				</template>
				{{ t('opencase', 'Ny skabelon') }}
			</NcButton>
		</div>

		<NewTemplateDialog v-if="showNewDialog"
			@close="showNewDialog = false"
			@created="onCreated" />

		<EditTemplateDialog v-if="editingTemplate"
			:template="editingTemplate"
			@close="editingTemplate = null"
			@saved="onSaved" />

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
						<th>{{ t('opencase', 'Fraser') }}</th>
						<th>{{ t('opencase', 'Organisationer') }}</th>
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
						<td>{{ template.phrase_count ?? 0 }}</td>
						<td class="template-list__orgs">{{ orgNames(template.org_uuids) }}</td>
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
									<FileDocumentEditOutlineIcon v-else :size="16" />
								</template>
							</NcButton>
							<NcButton
								type="tertiary"
								:title="t('opencase', 'Rediger info og fraser')"
								@click="editingTemplate = template">
								<template #icon>
									<PencilIcon :size="16" />
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
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { generateUrl } from '@nextcloud/router'

import DownloadIcon from 'vue-material-design-icons/Download.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'

import NewTemplateDialog from './NewTemplateDialog.vue'
import EditTemplateDialog from './EditTemplateDialog.vue'
import api from '../services/api.js'

export default {
	name: 'TemplatesTab',

	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		DownloadIcon,
		DeleteIcon,
		FileDocumentOutlineIcon,
		PlusIcon,
		PencilIcon,
		FileDocumentEditOutlineIcon,
		FormatListBulletedIcon,
		NewTemplateDialog,
		EditTemplateDialog,
	},

	data() {
		return {
			loading: false,
			templates: [],
			// Filter & pagination state
			nameFilter: '',
			currentPage: 1,
			pageSize: 20,
			// Dialog state
			showNewDialog: false,
			editingTemplate: null,
			templateToDelete: null,
			orgs: [],
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
				if (this.orgs.length === 0) {
					this.orgs = await api.getOrgTree().catch(() => [])
				}
			} finally {
				this.loading = false
			}
		},

		orgNames(uuids) {
			if (!uuids?.length) return t('opencase', 'Alle')
			return uuids.map(uuid => this.orgs.find(o => o.org_uuid === uuid)?.org_name || uuid).join(', ')
		},

		onCreated(template) {
			this.showNewDialog = false
			this.templates.unshift(template)
			if (template.nc_file_id) {
				window.open(generateUrl('/f/' + template.nc_file_id), '_blank')
			}
		},

		onSaved(template) {
			this.editingTemplate = null
			const index = this.templates.findIndex(t => t.id === template.id)
			if (index !== -1) {
				this.templates.splice(index, 1, { ...this.templates[index], ...template })
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
.template-manager__toolbar {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-bottom: 16px;
}

.template-manager__list {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

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

.template-list__orgs {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.template-list__actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}
</style>
