<template>
	<div class="opencase-file-upload">
		<NcButton v-if="!uploading"
			@click="selectFile">
			<template #icon>
				<UploadIcon :size="20" />
			</template>
			{{ t('opencase', 'Upload fil') }}
		</NcButton>

		<div v-else class="opencase-file-upload__progress">
			<NcProgressBar :value="progress" />
			<span class="opencase-file-upload__progress-text">
				{{ t('opencase', 'Uploader {name}… {pct}%', { name: uploadingName, pct: progress }) }}
			</span>
		</div>

		<!-- Hidden file input -->
		<input ref="fileInput"
			type="file"
			multiple
			style="display: none"
			@change="onFilesSelected">

		<!-- Document picker dialog (when uploading from case level) -->
		<NcDialog v-if="showDocPicker"
			:title="t('opencase', 'Vælg dokument')"
			@close="showDocPicker = false">
			<p>{{ t('opencase', 'Vælg hvilket dokument filen skal tilknyttes:') }}</p>
			<NcSelect v-model="selectedDoc"
				:options="documentOptions"
				:placeholder="t('opencase', 'Vælg dokument')" />
			<template #actions>
				<NcButton @click="showDocPicker = false">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="!selectedDoc"
					@click="confirmUpload">
					{{ t('opencase', 'Upload') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'FileUploadButton',
	components: { NcButton, NcDialog, NcSelect, NcProgressBar, UploadIcon },
	props: {
		documentId: { type: Number, default: null },
		caseId: { type: Number, default: null },
		documents: { type: Array, default: () => [] },
	},
	data() {
		return {
			uploading: false,
			progress: 0,
			uploadingName: '',
			showDocPicker: false,
			selectedDoc: null,
			pendingFiles: [],
		}
	},
	computed: {
		documentOptions() {
			return this.documents.map(d => ({ id: d.id, label: d.title }))
		},
	},
	methods: {
		selectFile() {
			this.$refs.fileInput.click()
		},

		onFilesSelected(event) {
			const files = Array.from(event.target.files)
			if (files.length === 0) return

			if (this.documentId) {
				this.uploadFiles(files, this.documentId)
			} else {
				this.pendingFiles = files
				this.showDocPicker = true
			}

			this.$refs.fileInput.value = ''
		},

		confirmUpload() {
			if (!this.selectedDoc) return
			this.showDocPicker = false
			this.uploadFiles(this.pendingFiles, this.selectedDoc.id)
			this.pendingFiles = []
		},

		async uploadFiles(files, docId) {
			this.uploading = true
			for (const file of files) {
				this.uploadingName = file.name
				this.progress = 0

				try {
					await this.$store.dispatch('uploadFile', {
						documentId: docId,
						file,
						onProgress: (event) => {
							if (event.total) {
								this.progress = Math.round((event.loaded / event.total) * 100)
							}
						},
					})
					showSuccess(t('opencase', '{name} uploadet', { name: file.name }))
				} catch (e) {
					showError(t('opencase', 'Kunne ikke uploade {name}', { name: file.name }))
				}
			}
			this.uploading = false
			this.progress = 0
			this.$emit('uploaded')
		},
	},
}
</script>

<style scoped>
.opencase-file-upload__progress { display: flex; align-items: center; gap: 10px; padding: 6px 0; }
.opencase-file-upload__progress-text { font-size: 0.85em; color: var(--color-text-lighter); white-space: nowrap; }
</style>
