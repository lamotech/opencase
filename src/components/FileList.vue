<template>
	<div class="opencase-file-list">
		<div v-for="file in files"
			:key="file.id"
			class="opencase-file-list__item"
			:class="{ 'opencase-file-list__item--deleted': file.is_deleted }">
			<div class="opencase-file-list__main" @click="handleRowClick(file)">
				<div class="opencase-file-list__icon">
					<component :is="fileIcon(file.mime_type)" :size="22" />
				</div>
				<div class="opencase-file-list__info">
					<div class="opencase-file-list__name-row">
						<span class="opencase-file-list__name">{{ file.original_filename }}</span>
						<span v-if="showDocumentNumber && file.document_number"
							class="opencase-file-list__docnumber">
							{{ file.document_number }}
						</span>
					</div>
					<span class="opencase-file-list__meta">
						{{ formatSize(file.size) }}
						<span v-if="showVersion">· v{{ file.version }}</span>
						· {{ formatDate(file.updated_at) }}
						<span v-if="file.is_deleted" class="opencase-file-list__deleted">
							· {{ t('opencase', 'Slettet {date}', { date: formatDate(file.deleted_date) }) }}
						</span>
					</span>
				</div>
			</div>
			<NcActions :force-menu="true"
				:open="openMenuId === file.id"
				@update:open="val => openMenuId = val ? file.id : null">
				<template v-if="file.is_deleted || locked">
					<NcActionButton v-if="file.is_deleted && !isReadOnly && !locked" @click="openMenuId = null; $emit('restore', file.id)">
						<template #icon>
							<RestoreIcon :size="20" />
						</template>
						{{ t('opencase', 'Gendan fil') }}
					</NcActionButton>
					<NcActionButton @click="handleDownload(file)">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t('opencase', 'Download') }}
					</NcActionButton>
					<NcActionButton @click="openLog(file)">
						<template #icon>
							<ClipboardTextIcon :size="20" />
						</template>
						{{ t('opencase', 'Vis log') }}
					</NcActionButton>
				</template>
				<template v-else>
					<NcActionButton v-if="!isReadOnly && isEditableFile(file.mime_type)" @click="handleEdit(file)">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('opencase', 'Rediger') }}
					</NcActionButton>
					<NcActionButton v-else @click="handleView(file)">
						<template #icon>
							<EyeIcon :size="20" />
						</template>
						{{ t('opencase', 'Vis') }}
					</NcActionButton>
					<NcActionButton @click="handleDownload(file)">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t('opencase', 'Download') }}
					</NcActionButton>
					<NcActionButton @click="openVersions(file)">
						<template #icon>
							<HistoryIcon :size="20" />
						</template>
						{{ t('opencase', 'Vis versioner') }}
					</NcActionButton>
					<NcActionButton @click="openLog(file)">
						<template #icon>
							<ClipboardTextIcon :size="20" />
						</template>
						{{ t('opencase', 'Vis log') }}
					</NcActionButton>
					<NcActionButton @click="openShare(file)">
						<template #icon>
							<ShareVariantIcon :size="20" />
						</template>
						{{ t('opencase', 'Del') }}
					</NcActionButton>
					<NcActionButton v-if="!isReadOnly" @click="openRename(file)">
						<template #icon>
							<FormTextboxIcon :size="20" />
						</template>
						{{ t('opencase', 'Omdøb') }}
					</NcActionButton>
					<NcActionButton v-if="!isReadOnly" @click="openDelete(file)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('opencase', 'Slet') }}
					</NcActionButton>
				</template>
			</NcActions>
		</div>

		<FileVersionsDialog
			:show="versionsFile !== null"
			:file="versionsFile || {}"
			@close="versionsFile = null" />

		<FileAuditLogDialog
			:show="logFile !== null"
			:file="logFile || {}"
			@close="logFile = null" />

		<FileShareDialog
			:show="shareFile !== null"
			:file="shareFile || {}"
			:is-read-only="isReadOnly"
			@close="shareFile = null" />

		<NcDialog v-if="renameFile !== null"
			:name="t('opencase', 'Omdøb fil')"
			@update:open="v => !v && closeRename()">
			<div class="opencase-file-list__rename">
				<NcTextField v-model="renameValue"
					:label="t('opencase', 'Filnavn')"
					:error="!!renameError"
					:helper-text="renameError || t('opencase', 'Filtypen ({ext}) bevares', { ext: extensionOf(renameFile.original_filename) })"
					@keyup.enter="submitRename" />
			</div>
			<template #actions>
				<NcButton type="tertiary" @click="closeRename">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="renaming || renameValue.trim() === ''"
					@click="submitRename">
					<template v-if="renaming" #icon>
						<NcLoadingIcon :size="16" />
					</template>
					{{ renaming ? t('opencase', 'Gemmer…') : t('opencase', 'Omdøb') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog v-if="deleteFile !== null"
			:name="t('opencase', 'Slet fil')"
			@update:open="v => !v && closeDelete()">
			<div class="opencase-file-list__delete">
				<p>
					{{ t('opencase', 'Er du sikker på, at du vil slette filen »{filename}«?', { filename: deleteFile.original_filename }) }}
				</p>
				<p v-if="softDeleteEnabled">
					{{ t('opencase', 'Filen kan gendannes efterfølgende.') }}
				</p>
				<p v-else class="opencase-file-list__delete-warning">
					{{ t('opencase', 'Filen og alle dens versioner slettes permanent og kan ikke gendannes.') }}
				</p>
			</div>
			<template #actions>
				<NcButton type="tertiary" @click="closeDelete">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="error" @click="submitDelete">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('opencase', 'Slet') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FileIcon from 'vue-material-design-icons/File.vue'
import FilePdfIcon from 'vue-material-design-icons/FilePdfBox.vue'
import FileWordIcon from 'vue-material-design-icons/FileWord.vue'
import FileExcelIcon from 'vue-material-design-icons/FileExcel.vue'
import FileImageIcon from 'vue-material-design-icons/FileImage.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import ClipboardTextIcon from 'vue-material-design-icons/ClipboardText.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import FormTextboxIcon from 'vue-material-design-icons/FormTextbox.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import api from '../services/api.js'
import FileVersionsDialog from './FileVersionsDialog.vue'
import FileAuditLogDialog from './FileAuditLogDialog.vue'
import FileShareDialog from './FileShareDialog.vue'

const MIME_ICONS = {
	'application/pdf': FilePdfIcon,
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': FileWordIcon,
	'application/msword': FileWordIcon,
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': FileExcelIcon,
	'application/vnd.ms-excel': FileExcelIcon,
	'image/': FileImageIcon,
}

export default {
	name: 'FileList',
	components: { NcActions, NcActionButton, NcDialog, NcButton, NcTextField, NcLoadingIcon, PencilIcon, EyeIcon, DownloadIcon, DeleteIcon, HistoryIcon, ClipboardTextIcon, ShareVariantIcon, FormTextboxIcon, RestoreIcon, FileVersionsDialog, FileAuditLogDialog, FileShareDialog },
	props: {
		files: { type: Array, required: true },
		showVersion: { type: Boolean, default: false },
		isReadOnly: { type: Boolean, default: false },
		showDocumentNumber: { type: Boolean, default: false },
		// Files of a deleted document: not openable, download/log only
		locked: { type: Boolean, default: false },
	},
	emits: ['delete', 'restore', 'renamed'],
	data() {
		return {
			versionsFile: null,
			logFile: null,
			shareFile: null,
			openMenuId: null,
			renameFile: null,
			renameValue: '',
			renameError: '',
			renaming: false,
			deleteFile: null,
		}
	},
	computed: {
		softDeleteEnabled() {
			return this.$store.state.softDeleteEnabled
		},
	},
	methods: {
		fileIcon(mimeType) {
			for (const [prefix, icon] of Object.entries(MIME_ICONS)) {
				if (mimeType.startsWith(prefix)) return icon
			}
			return FileIcon
		},
		isEditableFile(mimeType) {
			if (!mimeType) return false
			if (mimeType === 'text/html') return false
			if (mimeType.startsWith('text/')) return true
			return [
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/msword',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.ms-excel',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'application/vnd.ms-powerpoint',
				'application/vnd.oasis.opendocument.text',
				'application/vnd.oasis.opendocument.spreadsheet',
				'application/vnd.oasis.opendocument.presentation',
			].includes(mimeType)
		},
		handleRowClick(file) {
			// Deleted files are not in the virtual filesystem and cannot be opened
			if (file.is_deleted || this.locked) return
			if (!this.isReadOnly && this.isEditableFile(file.mime_type)) {
				this.handleEdit(file)
			} else {
				this.handleView(file)
			}
		},
		async handleEdit(file) {
			this.openMenuId = null
			try {
				const ncFileId = await api.getEditUrl(file.id, 'edit')
				window.open(generateUrl('/f/' + ncFileId), '_blank')
			} catch (e) {
				console.error('OpenCase: failed to open file for editing', e)
			}
		},
		async handleView(file) {
			this.openMenuId = null
			if (!this.isEditableFile(file.mime_type)) {
				window.open(generateOcsUrl('/apps/opencase/api/v1/files/' + file.id + '/view'), '_blank')
				return
			}
			try {
				const ncFileId = await api.getEditUrl(file.id, 'view')
				window.open(generateUrl('/f/' + ncFileId), '_blank')
			} catch (e) {
				console.error('OpenCase: failed to open file for viewing', e)
			}
		},
		async handleDownload(file) {
			this.openMenuId = null
			await api.downloadFile(file.id, file.original_filename)
		},
		openVersions(file) {
			this.openMenuId = null
			this.versionsFile = file
		},
		openLog(file) {
			this.openMenuId = null
			this.logFile = file
		},
		openShare(file) {
			this.openMenuId = null
			this.shareFile = file
		},
		openRename(file) {
			this.openMenuId = null
			this.renameFile = file
			// Offer the name without its extension — the backend keeps that
			this.renameValue = this.baseNameOf(file.original_filename)
			this.renameError = ''
		},
		closeRename() {
			this.renameFile = null
			this.renameValue = ''
			this.renameError = ''
		},
		async submitRename() {
			const name = this.renameValue.trim()
			if (this.renameFile === null || name === '' || this.renaming) return
			this.renaming = true
			this.renameError = ''
			try {
				const updated = await api.renameFile(this.renameFile.id, name)
				this.$emit('renamed', updated)
				this.closeRename()
			} catch (e) {
				this.renameError = e.response?.data?.ocs?.data?.error
					|| t('opencase', 'Kunne ikke omdøbe filen')
			} finally {
				this.renaming = false
			}
		},
		openDelete(file) {
			this.openMenuId = null
			this.deleteFile = file
		},
		closeDelete() {
			this.deleteFile = null
		},
		submitDelete() {
			if (this.deleteFile === null) return
			this.$emit('delete', this.deleteFile.id)
			this.closeDelete()
		},
		baseNameOf(filename) {
			const dot = (filename || '').lastIndexOf('.')
			return dot > 0 ? filename.slice(0, dot) : (filename || '')
		},
		extensionOf(filename) {
			const dot = (filename || '').lastIndexOf('.')
			return dot > 0 ? filename.slice(dot + 1) : ''
		},
		formatSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / 1048576).toFixed(1) + ' MB'
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', { year: 'numeric', month: 'short', day: 'numeric' })
		},
	},
}
</script>

<style scoped>
.opencase-file-list__item {
	display: flex; align-items: center; gap: 12px;
	padding: 8px 12px; border-radius: 6px;
	transition: background 0.15s;
}
.opencase-file-list__item:hover { background: var(--color-background-hover); }
.opencase-file-list__main { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; cursor: pointer; }
.opencase-file-list__icon { color: var(--color-text-lighter); flex-shrink: 0; }
.opencase-file-list__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.opencase-file-list__name-row { display: flex; align-items: baseline; gap: 8px; overflow: hidden; }
.opencase-file-list__name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.opencase-file-list__docnumber { font-size: 0.8em; color: var(--color-text-maxcontrast); white-space: nowrap; flex-shrink: 0; font-family: monospace; }
.opencase-file-list__meta { font-size: 0.8em; color: var(--color-text-lighter); }
.opencase-file-list__rename { padding: 8px 0 16px; }
.opencase-file-list__delete { display: flex; flex-direction: column; gap: 8px; padding: 8px 0 16px; }
.opencase-file-list__delete-warning { color: var(--color-error-text, var(--color-error)); }
.opencase-file-list__item--deleted .opencase-file-list__main { cursor: default; }
.opencase-file-list__item--deleted .opencase-file-list__icon,
.opencase-file-list__item--deleted .opencase-file-list__name { opacity: 0.5; }
.opencase-file-list__item--deleted .opencase-file-list__name { text-decoration: line-through; }
.opencase-file-list__deleted { color: var(--color-error-text, var(--color-error)); }
</style>
