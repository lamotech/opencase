<template>
	<div class="opencase-audit-log">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent v-else-if="entries.length === 0"
			:title="t('opencase', 'Ingen logposter')">
			<template #icon>
				<HistoryIcon :size="48" />
			</template>
		</NcEmptyContent>
		<template v-else>
			<div class="opencase-audit-log__list">
				<div v-for="entry in entries"
					:key="entry.id"
					class="opencase-audit-log__entry">
					<div class="opencase-audit-log__icon">
						<component :is="eventIcon(entry.event_type)" :size="18" />
					</div>
					<div class="opencase-audit-log__body">
						<span class="opencase-audit-log__description">{{ describe(entry) }}</span>
						<span class="opencase-audit-log__meta">
							{{ entry.user_display_name }} · {{ formatDate(entry.created_at) }}
						</span>
					</div>
				</div>
			</div>
			<div v-if="total > limit" class="opencase-audit-log__pagination">
				<NcButton :disabled="offset === 0" @click="prevPage">
					{{ t('opencase', 'Forrige') }}
				</NcButton>
				<span class="opencase-audit-log__page-info">
					{{ t('opencase', '{start}–{end} af {total}', {
						start: offset + 1,
						end: Math.min(offset + limit, total),
						total,
					}) }}
				</span>
				<NcButton :disabled="offset + limit >= total" @click="nextPage">
					{{ t('opencase', 'Næste') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'

import api from '../services/api.js'

export default {
	name: 'FileAuditLog',
	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		HistoryIcon,
		PaperclipIcon,
		EyeIcon,
		PencilIcon,
		DownloadIcon,
	},
	props: {
		fileId: { type: [String, Number], required: true },
	},
	data() {
		return {
			entries: [],
			total: 0,
			loading: false,
			offset: 0,
			limit: 25,
		}
	},
	watch: {
		fileId: {
			immediate: true,
			handler() {
				this.offset = 0
				this.load()
			},
		},
	},
	methods: {
		async load() {
			this.loading = true
			try {
				const result = await api.getFileAuditLog(this.fileId, { limit: this.limit, offset: this.offset })
				this.entries = result.entries
				this.total = result.total
			} finally {
				this.loading = false
			}
		},
		prevPage() {
			this.offset = Math.max(0, this.offset - this.limit)
			this.load()
		},
		nextPage() {
			this.offset += this.limit
			this.load()
		},
		eventIcon(eventType) {
			switch (eventType) {
			case 'file_uploaded':           return PaperclipIcon
			case 'file_viewed':             return EyeIcon
			case 'file_edited':             return PencilIcon
			case 'file_downloaded':         return DownloadIcon
			case 'file_version_viewed':     return EyeIcon
			case 'file_version_downloaded': return DownloadIcon
			case 'file_version_restored':   return HistoryIcon
			default:                        return HistoryIcon
			}
		},
		describe(entry) {
			const d = entry.details || {}
			switch (entry.event_type) {
			case 'file_uploaded':
				return t('opencase', 'Fil uploadet: {filename}', { filename: d.filename || '' })
			case 'file_viewed':
				return t('opencase', 'Fil åbnet: {filename}', { filename: d.filename || '' })
			case 'file_edited':
				return t('opencase', 'Fil redigeret: {filename}', { filename: d.filename || '' })
			case 'file_downloaded':
				return t('opencase', 'Fil downloadet: {filename}', { filename: d.filename || '' })
			case 'file_version_viewed':
				return t('opencase', 'Fil version åbnet: {filename}', { filename: d.filename || '' })
			case 'file_version_downloaded':
				return t('opencase', 'Fil version downloadet: {filename}', { filename: d.filename || '' })
			case 'file_version_restored':
				return t('opencase', 'Fil version gendannet: {filename}', { filename: d.filename || '' })
			default:
				return entry.event_type
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.opencase-audit-log__list {
	display: flex;
	flex-direction: column;
}

.opencase-audit-log__entry {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 10px 12px;
	border-radius: 6px;
	transition: background 0.15s;
}

.opencase-audit-log__entry:hover {
	background: var(--color-background-hover);
}

.opencase-audit-log__icon {
	color: var(--color-text-lighter);
	flex-shrink: 0;
	margin-top: 2px;
}

.opencase-audit-log__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.opencase-audit-log__description {
	font-size: 0.95em;
	color: var(--color-main-text);
}

.opencase-audit-log__meta {
	font-size: 0.8em;
	color: var(--color-text-lighter);
}

.opencase-audit-log__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 12px 12px 4px;
}

.opencase-audit-log__page-info {
	font-size: 0.85em;
	color: var(--color-text-lighter);
}
</style>
