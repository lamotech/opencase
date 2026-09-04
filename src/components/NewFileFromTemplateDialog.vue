<template>
	<SelectTemplateDialog :name="t('opencase', 'Ny fil fra skabelon')"
		:confirm-label="creating ? t('opencase', 'Opretter…') : t('opencase', 'Opret og rediger')"
		:confirm-loading="creating"
		@selected="create"
		@close="$emit('close')">
		<template #confirm-icon>
			<FileDocumentEditOutlineIcon :size="16" />
		</template>
	</SelectTemplateDialog>
</template>

<script>
import SelectTemplateDialog from './SelectTemplateDialog.vue'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'

import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'NewFileFromTemplateDialog',

	components: { SelectTemplateDialog, FileDocumentEditOutlineIcon },

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
		}
	},

	methods: {
		async create(template) {
			this.creating = true
			try {
				const file = await api.createFileFromTemplate(this.documentId, template.id)

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
