<template>
	<NcDialog :name="t('opencase', 'Ny fil fra skabelon')"
		size="normal"
		@update:open="v => !v && $emit('close')">
		<div class="nfft">
			<NcLoadingIcon v-if="loadingTemplates" :size="32" class="nfft__loading" />

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
				<!-- 1. Template -->
				<div class="nfft__field">
					<label class="nfft__label">{{ t('opencase', 'Skabelon') }}</label>
					<NcSelect v-model="templateOption"
						:options="templateOptions"
						:placeholder="t('opencase', 'Søg efter skabelon…')"
						:clearable="false"
						:filter-by="filterTemplate">
						<template #option="option">
							<div class="nfft__option">
								<span>{{ option.label }}</span>
								<span class="nfft__muted">{{ option.filename }}</span>
							</div>
						</template>
					</NcSelect>
				</div>

				<!-- 2. Filename -->
				<div class="nfft__field">
					<label class="nfft__label">{{ t('opencase', 'Filnavn') }}</label>
					<NcTextField v-model="filename"
						:placeholder="t('opencase', 'Navn på den nye fil')"
						:helper-text="selectedTemplate
							? t('opencase', 'Filtypen ({ext}) bevares', { ext: extensionOf(selectedTemplate.original_filename) })
							: t('opencase', 'Udfyldes med skabelonens navn, hvis du lader feltet stå tomt')" />
				</div>

				<!-- 3. Phrases -->
				<div v-if="selectedTemplate" class="nfft__field">
					<label class="nfft__label">{{ t('opencase', 'Fraser') }}</label>
					<TemplatePhraseSelector v-model="phraseIds"
						:template-id="selectedTemplate.id"
						:document-id="documentId"
						@loading="loadingPhrases = $event" />
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!selectedTemplate || loadingPhrases || creating"
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import TemplatePhraseSelector from './TemplatePhraseSelector.vue'
import api from '../services/api.js'

/**
 * Create a file on a document from a template. The user picks the
 * template, names the file and chooses which of the template's phrases are
 * merged into {{fraser.indhold}}: required phrases are always included,
 * pre-selected ones start checked.
 */
export default {
	name: 'NewFileFromTemplateDialog',

	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		FileDocumentEditOutlineIcon,
		FileDocumentOutlineIcon,
		TemplatePhraseSelector,
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
			loadingTemplates: true,
			templates: [],
			selectedTemplate: null,
			loadingPhrases: false,
			// Phrases to merge (set by TemplatePhraseSelector; null = template defaults)
			phraseIds: null,
			creating: false,
			filename: '',
			// Last name we filled in ourselves. The field counts as edited by
			// the user as soon as it holds anything else.
			autoFilled: '',
		}
	},

	computed: {
		templateOptions() {
			return this.templates.map(tpl => ({
				id: tpl.id,
				label: tpl.name,
				filename: tpl.original_filename,
			}))
		},
		templateOption: {
			get() {
				return this.templateOptions.find(o => o.id === this.selectedTemplate?.id) || null
			},
			set(option) {
				const template = this.templates.find(tpl => tpl.id === option?.id) ?? null
				if (template && template.id !== this.selectedTemplate?.id) {
					this.pick(template)
				}
			},
		},
	},

	async mounted() {
		try {
			// Only templates available for the case's organisation
			this.templates = (await api.getTemplates({ document_id: this.documentId }))
				.sort((a, b) => a.name.localeCompare(b.name, 'da'))
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente skabeloner'))
		} finally {
			this.loadingTemplates = false
		}
	},

	methods: {
		filterTemplate(option, label, search) {
			const query = search.toLowerCase()
			return option.label.toLowerCase().includes(query)
				|| option.filename.toLowerCase().includes(query)
		},

		pick(template) {
			this.selectedTemplate = template
			this.phraseIds = null

			// The name follows the picked template — switching template renames
			// the file too — but never overwrites a name the user typed themselves.
			if (this.filename === '' || this.filename === this.autoFilled) {
				this.autoFilled = template.name
				this.filename = this.autoFilled
			}
		},

		extensionOf(filename) {
			const dot = filename.lastIndexOf('.')
			return dot > 0 ? filename.slice(dot + 1) : ''
		},

		async create() {
			if (!this.selectedTemplate) return
			this.creating = true
			try {
				const file = await api.createFileFromTemplate(
					this.documentId,
					this.selectedTemplate.id,
					this.filename.trim(),
					this.phraseIds,
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
.nfft {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-height: 160px;
	padding: 4px 0;
}

.nfft__loading {
	display: block;
	margin: 32px auto;
}

.nfft__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.nfft__label {
	font-weight: 600;
	font-size: 0.9em;
}

.nfft__option {
	display: flex;
	flex-direction: column;
}

.nfft__muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
