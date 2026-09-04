<template>
	<NcDialog :name="name" @update:open="v => !v && $emit('close')">
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

			<template v-else>
				<NcTextField v-model="nameFilter"
					class="template-picker__filter"
					:label="t('opencase', 'Filtrer efter navn')"
					:placeholder="t('opencase', 'Filtrer efter navn…')"
					trailing-button-icon="close"
					:show-trailing-button="nameFilter !== ''"
					@trailing-button-click="nameFilter = ''" />

				<!-- No templates match filter -->
				<NcEmptyContent v-if="filteredTemplates.length === 0"
					:name="t('opencase', 'Ingen skabeloner matcher filteret')">
					<template #icon>
						<FileDocumentOutlineIcon :size="48" />
					</template>
				</NcEmptyContent>

				<!-- Template list -->
				<ul v-else class="template-picker__list">
					<li v-for="template in pagedTemplates"
						:key="template.id"
						class="template-picker__item"
						:class="{ 'template-picker__item--selected': selectedId === template.id }"
						@click="pick(template)"
						@dblclick="confirm">
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

				<!-- Pagination -->
				<div v-if="filteredTemplates.length > pageSize" class="template-picker__pagination">
					<NcButton :disabled="currentPage === 1"
						@click="currentPage--">
						{{ t('opencase', 'Forrige') }}
					</NcButton>
					<span class="template-picker__page-info">
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
			</template>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="selectedId === null || confirmLoading"
				@click="confirm">
				<template #icon>
					<NcLoadingIcon v-if="confirmLoading" :size="16" />
					<slot v-else name="confirm-icon">
						<CheckIcon :size="16" />
					</slot>
				</template>
				{{ confirmLabel || t('opencase', 'Vælg') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileWordIcon from 'vue-material-design-icons/FileWord.vue'
import FileExcelIcon from 'vue-material-design-icons/FileExcel.vue'
import FilePdfIcon from 'vue-material-design-icons/FilePdfBox.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

const MIME_ICONS = {
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': FileWordIcon,
	'application/msword': FileWordIcon,
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': FileExcelIcon,
	'application/vnd.ms-excel': FileExcelIcon,
	'application/pdf': FilePdfIcon,
}

/**
 * Pure template picker: lists the available document templates and emits the
 * chosen one. It never closes itself on confirm — the parent decides, so a
 * caller that still has work to do (creating a file, say) can keep the dialog
 * open and show progress via `confirmLoading`.
 */
export default {
	name: 'SelectTemplateDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		FileDocumentOutlineIcon,
		FileWordIcon,
		FileExcelIcon,
		FilePdfIcon,
		FileIcon,
		CheckIcon,
	},

	props: {
		name:           { type: String,  default: () => t('opencase', 'Vælg skabelon') },
		confirmLabel:   { type: String,  default: '' },
		confirmLoading: { type: Boolean, default: false },
		selectOnClick:  { type: Boolean, default: false },
	},

	emits: ['close', 'selected'],

	data() {
		return {
			loading: true,
			templates: [],
			selectedId: null,
			nameFilter: '',
			currentPage: 1,
			pageSize: 20,
		}
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

		selectedTemplate() {
			return this.templates.find(tpl => tpl.id === this.selectedId) ?? null
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
		pick(template) {
			this.selectedId = template.id
			if (this.selectOnClick) this.confirm()
		},

		confirm() {
			if (this.selectedTemplate === null || this.confirmLoading) return
			this.$emit('selected', this.selectedTemplate)
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

.template-picker__filter {
	margin-bottom: 12px;
}

.template-picker__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 12px;
}

.template-picker__page-info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
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
