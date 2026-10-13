<template>
	<div class="opencase-document-list">
		<div v-for="doc in sortedDocuments"
			:key="doc.id"
			class="opencase-document-list__item"
			:class="{ 'opencase-document-list__item--deleted': doc.is_deleted }"
			@click="$emit('open', doc.id)">
			<div class="opencase-document-list__icon">
				<FileDocumentIcon :size="24" />
			</div>
			<div class="opencase-document-list__info">
				<div class="opencase-document-list__title-row">
					<span class="opencase-document-list__title">{{ doc.title }}</span>
					<span v-if="doc.document_number" class="opencase-document-list__docnumber">
						{{ doc.document_number }}
					</span>
				</div>
				<span class="opencase-document-list__meta">
					<span v-if="doc.document_type">{{ doc.document_type }}</span>
					<CaseStatusBadge :status="doc.status" :label="doc.status_name" />
					<span>{{ formatDate(doc.updated_at) }}</span>
					<span v-if="doc.is_deleted" class="opencase-document-list__deleted">
						{{ t('opencase', 'Slettet {date}', { date: formatDate(doc.deleted_date) }) }}
					</span>
				</span>
			</div>
			<!-- Keep clicks on the menu from opening the document via the row -->
			<div class="opencase-document-list__actions" @click.stop>
				<NcActions :force-menu="true">
					<NcActionButton @click.stop="$emit('open', doc.id)">
						{{ t('opencase', 'Åbn') }}
					</NcActionButton>
					<NcActionButton v-if="doc.is_deleted && canRestore"
						@click.stop="$emit('restore', doc.id)">
						<template #icon>
							<RestoreIcon :size="20" />
						</template>
						{{ t('opencase', 'Gendan dokument') }}
					</NcActionButton>
					<NcActionButton v-else-if="!doc.is_deleted && doc.status === 1"
						@click.stop="$emit('delete', doc.id)">
						{{ t('opencase', 'Slet') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>
	</div>
</template>

<script>
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import CaseStatusBadge from './CaseStatusBadge.vue'

export default {
	name: 'DocumentList',
	components: { NcActions, NcActionButton, FileDocumentIcon, RestoreIcon, CaseStatusBadge },
	props: {
		documents: { type: Array, required: true },
		canRestore: { type: Boolean, default: false },
	},
	emits: ['open', 'delete', 'restore'],
	computed: {
		// Newest first: document numbers end in a running sequence ("2026-00113-6"),
		// so a numeric-aware compare orders them correctly; unnumbered ones fall back to id
		sortedDocuments() {
			return [...this.documents].sort((a, b) => {
				if (a.document_number && b.document_number) {
					return b.document_number.localeCompare(a.document_number, undefined, { numeric: true })
				}
				if (a.document_number) return -1
				if (b.document_number) return 1
				return b.id - a.id
			})
		},
	},
	methods: {
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', { year: 'numeric', month: 'short', day: 'numeric' })
		},
	},
}
</script>

<style scoped>
.opencase-document-list__item {
	display: flex; align-items: center; gap: 12px;
	padding: 10px 12px; border-radius: 8px; cursor: pointer;
	transition: background 0.15s;
}
.opencase-document-list__item:hover { background: var(--color-background-hover); }
.opencase-document-list__icon { color: var(--color-primary-element); flex-shrink: 0; }
.opencase-document-list__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.opencase-document-list__title-row { display: flex; align-items: baseline; gap: 8px; overflow: hidden; }
.opencase-document-list__title { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.opencase-document-list__docnumber { font-size: 0.8em; color: var(--color-text-maxcontrast); white-space: nowrap; flex-shrink: 0; font-family: monospace; }
.opencase-document-list__meta { display: flex; gap: 8px; align-items: center; font-size: 0.85em; color: var(--color-text-lighter); }
.opencase-document-list__item--deleted .opencase-document-list__icon,
.opencase-document-list__item--deleted .opencase-document-list__title { opacity: 0.5; }
.opencase-document-list__item--deleted .opencase-document-list__title { text-decoration: line-through; }
.opencase-document-list__deleted { color: var(--color-error-text, var(--color-error)); }
.opencase-document-list__actions { flex-shrink: 0; cursor: default; }
</style>
