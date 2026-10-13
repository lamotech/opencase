<template>
	<div class="opencase-doc-relations">
		<div v-if="canWrite" class="opencase-doc-relations__header">
			<NcButton type="primary" @click="addRelationTypeId = RELATION_TYPE_ATTACHMENT">
				<template #icon>
					<PaperclipIcon :size="20" />
				</template>
				{{ t('opencase', 'Tilføj bilag') }}
			</NcButton>
			<NcButton @click="addRelationTypeId = RELATION_TYPE_RELATION">
				<template #icon>
					<LinkVariantIcon :size="20" />
				</template>
				{{ t('opencase', 'Tilføj relation') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="relations.length === 0"
			:title="t('opencase', 'Ingen dokumentrelationer')">
			<template #icon>
				<FileLinkOutlineIcon :size="48" />
			</template>
		</NcEmptyContent>

		<div v-else class="opencase-doc-relations__list">
			<div v-for="relation in relations"
				:key="relation.id"
				class="opencase-doc-relations__item">
				<div class="opencase-doc-relations__icon">
					<component :is="relation.direction === 'to' ? ArrowRightIcon : ArrowLeftIcon" :size="20" />
				</div>

				<div class="opencase-doc-relations__info">
					<span class="opencase-doc-relations__title">
						<span class="opencase-doc-relations__type">{{ relation.type_name }}</span>
						<span class="opencase-doc-relations__number">{{ relation.document_number || '–' }}</span>
						<router-link v-if="relation.can_read"
							:to="{ name: 'document-detail', params: { id: String(relation.document_id) } }"
							class="opencase-doc-relations__link">
							{{ relation.document_title }}
						</router-link>
						<span v-else class="opencase-doc-relations__no-access">{{ t('opencase', 'Ingen adgang') }}</span>
					</span>
					<span class="opencase-doc-relations__meta">
						{{ relation.direction === 'to' ? t('opencase', 'Fra dette dokument') : t('opencase', 'Til dette dokument') }}
						<template v-if="relation.created_at">
							· {{ relation.created_by }} · {{ formatDate(relation.created_at) }}
						</template>
					</span>
				</div>

				<div v-if="canWrite" class="opencase-doc-relations__actions">
					<NcButton :aria-label="t('opencase', 'Fjern relation')"
						:title="t('opencase', 'Fjern relation')"
						type="tertiary"
						@click="remove(relation)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>

		<AddDocumentRelationDialog v-if="addRelationTypeId"
			:document-id="documentId"
			:initial-case-number="caseNumber"
			:relation-type-id="addRelationTypeId"
			@close="addRelationTypeId = null"
			@added="onRelationsAdded" />
	</div>
</template>

<script>
import { markRaw } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import FileLinkOutlineIcon from 'vue-material-design-icons/FileLinkOutline.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import AddDocumentRelationDialog, { RELATION_TYPE_ATTACHMENT, RELATION_TYPE_RELATION } from './AddDocumentRelationDialog.vue'

import api from '../services/api.js'

export default {
	name: 'DocumentRelationList',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		FileLinkOutlineIcon,
		PaperclipIcon,
		LinkVariantIcon,
		DeleteIcon,
		AddDocumentRelationDialog,
	},

	props: {
		documentId: { type: [String, Number], required: true },
		caseNumber: { type: String, default: '' },
		canWrite: { type: Boolean, default: false },
	},

	emits: ['count-changed'],

	data() {
		return {
			relations: [],
			loading: false,
			// Relation type of the open add-dialog, null when closed
			addRelationTypeId: null,
			RELATION_TYPE_ATTACHMENT,
			RELATION_TYPE_RELATION,
			ArrowRightIcon: markRaw(ArrowRightIcon),
			ArrowLeftIcon: markRaw(ArrowLeftIcon),
		}
	},

	watch: {
		documentId: { immediate: true, handler() { this.load() } },
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.relations = await api.getDocumentRelations(this.documentId)
				this.$emit('count-changed', this.relations.length || null)
			} catch (e) {
				this.relations = []
			} finally {
				this.loading = false
			}
		},

		async remove(relation) {
			if (!confirm(t('opencase', 'Er du sikker på, at du vil fjerne denne relation?'))) return
			try {
				await api.deleteDocumentRelation(this.documentId, relation.id)
				this.relations = this.relations.filter(r => r.id !== relation.id)
				this.$emit('count-changed', this.relations.length || null)
				showSuccess(t('opencase', 'Relation fjernet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke fjerne relation'))
			}
		},

		onRelationsAdded() {
			this.addRelationTypeId = null
			this.load()
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.opencase-doc-relations__header {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.opencase-doc-relations__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-doc-relations__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border-radius: 8px;
	transition: background 0.15s;
}

.opencase-doc-relations__item:hover {
	background: var(--color-background-hover);
}

.opencase-doc-relations__icon {
	color: var(--color-text-lighter);
	flex-shrink: 0;
}

.opencase-doc-relations__info {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-doc-relations__title {
	display: flex;
	gap: 8px;
	align-items: baseline;
	overflow: hidden;
	white-space: nowrap;
}

.opencase-doc-relations__type {
	font-weight: 600;
	flex-shrink: 0;
}

.opencase-doc-relations__number {
	font-family: monospace;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.opencase-doc-relations__link {
	overflow: hidden;
	text-overflow: ellipsis;
}

.opencase-doc-relations__link:hover {
	text-decoration: underline;
}

.opencase-doc-relations__no-access {
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.opencase-doc-relations__actions {
	flex-shrink: 0;
}

.opencase-doc-relations__meta {
	font-size: 0.85em;
	color: var(--color-text-lighter);
}
</style>
