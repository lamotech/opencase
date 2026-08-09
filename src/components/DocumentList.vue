<template>
	<div class="opencase-document-list">
		<div v-for="doc in documents"
			:key="doc.id"
			class="opencase-document-list__item"
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
				</span>
			</div>
			<NcActions :force-menu="true">
				<NcActionButton @click.stop="$emit('open', doc.id)">
					{{ t('opencase', 'Åbn') }}
				</NcActionButton>
				<NcActionButton v-if="doc.status === 1"
					@click.stop="$emit('delete', doc.id)">
					{{ t('opencase', 'Slet') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import CaseStatusBadge from './CaseStatusBadge.vue'

export default {
	name: 'DocumentList',
	components: { NcActions, NcActionButton, FileDocumentIcon, CaseStatusBadge },
	props: {
		documents: { type: Array, required: true },
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
</style>
