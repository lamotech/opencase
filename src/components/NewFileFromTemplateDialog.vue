<template>
	<SelectTemplateDialog :name="t('opencase', 'Ny fil fra skabelon')"
		:confirm-label="creating ? t('opencase', 'Opretter…') : t('opencase', 'Opret og rediger')"
		:confirm-loading="creating"
		@pick="onPick"
		@selected="create"
		@close="$emit('close')">
		<template #before="{ template }">
			<div class="nfft-name">
				<label class="nfft-name__label">{{ t('opencase', 'Filnavn') }}</label>
				<NcTextField v-model="filename"
					:placeholder="t('opencase', 'Navn på den nye fil')"
					:helper-text="template
						? t('opencase', 'Filtypen ({ext}) bevares', { ext: extensionOf(template.original_filename) })
						: t('opencase', 'Udfyldes med skabelonens navn, hvis du lader feltet stå tomt')" />
			</div>
		</template>
		<template #confirm-icon>
			<FileDocumentEditOutlineIcon :size="16" />
		</template>
	</SelectTemplateDialog>
</template>

<script>
import SelectTemplateDialog from './SelectTemplateDialog.vue'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'

import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'NewFileFromTemplateDialog',

	components: { SelectTemplateDialog, NcTextField, FileDocumentEditOutlineIcon },

	props: {
		documentId: {
			type: Number,
			required: true,
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			creating: false,
			filename: '',
			// Last name we filled in ourselves. The field counts as edited by
			// the user as soon as it holds anything else.
			autoFilled: '',
		}
	},

	methods: {
		// The name follows the picked template — switching template renames the
		// file too — but never overwrites a name the user typed themselves.
		onPick(template) {
			if (this.filename !== '' && this.filename !== this.autoFilled) return
			this.autoFilled = this.baseNameOf(template.original_filename)
			this.filename   = this.autoFilled
		},

		baseNameOf(filename) {
			const dot = filename.lastIndexOf('.')
			return dot > 0 ? filename.slice(0, dot) : filename
		},

		extensionOf(filename) {
			const dot = filename.lastIndexOf('.')
			return dot > 0 ? filename.slice(dot + 1) : ''
		},

		async create(template) {
			this.creating = true
			try {
				const file = await api.createFileFromTemplate(
					this.documentId,
					template.id,
					this.filename.trim(),
				)

				// Notify parent so it can refresh the file list
				this.$emit('created', file)

				// Open the new file for editing in Nextcloud Office
				const ncFileId = await api.getEditUrl(file.id, 'edit')
				window.open(generateUrl('/f/' + ncFileId), '_blank')

				this.$emit('close')
			} catch (e) {
				showError(
					e.response?.data?.ocs?.data?.error
						?? t('opencase', 'Kunne ikke oprette fil fra skabelon'),
				)
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.nfft-name {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 16px;
}

.nfft-name__label {
	font-weight: 600;
	font-size: 0.9em;
}
</style>
