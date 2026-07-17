<template>
	<NcDialog :name="t('opencase', 'Ny fil fra skabelon')"
		@closing="$emit('close')">

		<div class="template-picker">
			<!-- Loading -->
			<NcLoadingIcon v-if="loading" :size="32" class="template-picker__loading" />

			<!-- No templates available -->
			<NcEmptyContent v-else-if="templates.length === 0"
				:name="t('opencase', 'Ingen skabeloner tilgængelige')">
				<template #icon>
					<FileDocumentOutlineIcon :size="48" />
				</template>
				<template #description>
					{{ t('opencase', 'Bed en administrator om at uploade skabeloner') }}
				</template>
			</NcEmptyContent>

			<!-- Template list -->
			<ul v-else class="template-picker__list">
				<li v-for="template in templates"
					:key="template.id"
					class="template-picker__item"
					:class="{ 'template-picker__item--selected': selectedId === template.id }"
					@click="selectedId = template.id">
					<component :is="mimeIcon(template.mime_type)" :size="28" class="template-picker__icon" />
					<div class="template-picker__info">
						<span class="template-picker__name">{{ template.name }}</span>
						<span class="template-picker__meta">
							{{ template.original_filename }} · {{ formatSize(template.size) }}
						</span>
					</div>
					<CheckIcon v-if="selectedId === template.id" :size="20" class="template-picker__check" />
				</li>
			</ul>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="selectedId === null || creating"
				@click="create">
				<template #icon>
					<NcLoadingIcon v-if="creating" :size="16" />
					<FileDocumentEditOutlineIcon v-else :size="16" />
				</template>
				{{ creating ? t('opencase', 'Opretter…') : t('opencase', 'Opret og rediger') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileWordIcon from 'vue-material-design-icons/FileWord.vue'
import FileExcelIcon from 'vue-material-design-icons/FileExcel.vue'
import FilePdfIcon from 'vue-material-design-icons/FilePdfBox.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

const MIME_ICONS = {
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': FileWordIcon,
	'application/msword': FileWordIcon,
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': FileExcelIcon,
	'application/vnd.ms-excel': FileExcelIcon,
	'application/pdf': FilePdfIcon,
}

export default {
	name: 'NewFileFromTemplateDialog',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		FileDocumentOutlineIcon,
		FileDocumentEditOutlineIcon,
		FileWordIcon,
		FileExcelIcon,
		FilePdfIcon,
		FileIcon,
		CheckIcon,
	},

	props: {
		documentId: {
			type: Number,
			required: true,
		},
	},

	emits: ['close', 'created'],

	data() {
		return {
			loading: true,
			templates: [],
			selectedId: null,
			creating: false,
		}
	},

	async mounted() {
		try {
			this.templates = await api.getTemplates()
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente skabeloner'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		async create() {
			if (this.selectedId === null) return

			this.creating = true
			try {
				const file = await api.createFileFromTemplate(this.documentId, this.selectedId)

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

		mimeIcon(mimeType) {
			for (const [prefix, icon] of Object.entries(MIME_ICONS)) {
				if (mimeType.startsWith(prefix)) return icon
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
.template-picker {
	min-height: 160px;
}

.template-picker__loading {
	display: block;
	margin: 32px auto;
}

.template-picker__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.template-picker__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	cursor: pointer;
	border: 2px solid transparent;
	transition: background 0.15s, border-color 0.15s;
}

.template-picker__item:hover {
	background: var(--color-background-hover);
}

.template-picker__item--selected {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

.template-picker__icon {
	flex-shrink: 0;
	color: var(--color-text-lighter);
}

.template-picker__item--selected .template-picker__icon {
	color: var(--color-primary-element);
}

.template-picker__info {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.template-picker__name {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.template-picker__meta {
	font-size: 0.8em;
	color: var(--color-text-lighter);
}

.template-picker__check {
	flex-shrink: 0;
	color: var(--color-primary-element);
}
</style>
