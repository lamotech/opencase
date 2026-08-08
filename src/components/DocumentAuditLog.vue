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
import AccountPlusIcon from 'vue-material-design-icons/AccountPlus.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import AccountPlusOutlineIcon from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountMinusIcon from 'vue-material-design-icons/AccountMinus.vue'
import NoteTextIcon from 'vue-material-design-icons/NoteText.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import EmailArrowRightIcon from 'vue-material-design-icons/EmailArrowRight.vue'
import EmailAlertIcon from 'vue-material-design-icons/EmailAlert.vue'
import EmailFastOutlineIcon from 'vue-material-design-icons/EmailFastOutline.vue'
import ReceiptSendOutlineIcon from 'vue-material-design-icons/ReceiptSendOutline.vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import FolderArrowRightIcon from 'vue-material-design-icons/FolderArrowRight.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import CancelIcon from 'vue-material-design-icons/Cancel.vue'

import api from '../services/api.js'
import { maskCpr, maskedPartyNumber } from '../utils/cprMask.js'

export default {
	name: 'DocumentAuditLog',
	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		HistoryIcon,
		AccountPlusIcon,
		AccountPlusOutlineIcon,
		AccountMinusIcon,
		EyeIcon,
		PencilIcon,
		SwapHorizontalIcon,
		PaperclipIcon,
		NoteTextIcon,
		DeleteIcon,
		EmailArrowRightIcon,
		EmailAlertIcon,
		EmailFastOutlineIcon,
		ReceiptSendOutlineIcon,
		AlertCircleOutlineIcon,
		FolderArrowRightIcon,
		SitemapIcon,
		CheckCircleIcon,
		CloseCircleIcon,
		CancelIcon,
	},
	props: {
		documentId: { type: [String, Number], required: true },
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
	computed: {
		statusNameMap() {
			const statuses = this.$store.state.documentStatuses || []
			return Object.fromEntries(statuses.map(s => [s.id, s.name]))
		},
	},
	created() {
		if (this.$store.state.documentStatuses.length === 0) {
			this.$store.dispatch('fetchDocumentStatuses')
		}
	},
	watch: {
		documentId: {
			immediate: true,
			handler() {
				this.offset = 0
				this.load()
			},
		},
	},
	methods: {
		statusName(id) {
			return this.statusNameMap[id] ?? String(id)
		},
		async load() {
			this.loading = true
			try {
				const result = await api.getDocumentAuditLog(this.documentId, { limit: this.limit, offset: this.offset })
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
			case 'document_created':          return AccountPlusIcon
			case 'document_viewed':           return EyeIcon
			case 'document_metadata_changed': return PencilIcon
			case 'document_status_changed':   return SwapHorizontalIcon
			case 'file_uploaded':             return PaperclipIcon
			case 'file_viewed':               return EyeIcon
			case 'file_edited':               return PencilIcon
			case 'file_downloaded':           return DownloadIcon
			case 'file_version_viewed':       return EyeIcon
			case 'file_version_downloaded':   return DownloadIcon
			case 'file_version_restored':          return HistoryIcon
			case 'document_contact_added':         return AccountPlusOutlineIcon
			case 'document_contact_deleted':       return AccountMinusIcon
			case 'document_note_created':          return NoteTextIcon
			case 'document_note_updated':          return PencilIcon
			case 'document_note_deleted':          return DeleteIcon
			case 'digital_post_sent':              return EmailArrowRightIcon
			case 'digital_post_failed':            return EmailAlertIcon
			case 'document_sent_by_email':         return EmailFastOutlineIcon
			case 'document_send_by_email_failed':  return EmailAlertIcon
			case 'distribution_receipt_sent':      return ReceiptSendOutlineIcon
			case 'distribution_receipt_failed':    return AlertCircleOutlineIcon
			case 'document_moved_to_case':         return FolderArrowRightIcon
			case 'workflow_created':               return SitemapIcon
			case 'workflow_step_reviewed':
			case 'workflow_step_approved':
			case 'workflow_completed':             return CheckCircleIcon
			case 'workflow_step_rejected':
			case 'workflow_rejected':              return CloseCircleIcon
			case 'workflow_cancelled':             return CancelIcon
			case 'cpr_event_received':             return SwapHorizontalIcon
			default:                               return HistoryIcon
			}
		},
		describe(entry) {
			const d = entry.details || {}
			switch (entry.event_type) {
			case 'document_created':
				return t('opencase', 'Dokument oprettet')
			case 'document_viewed':
				return t('opencase', 'Dokument åbnet')
			case 'document_metadata_changed': {
				const parts = []
				if (d.title) {
					parts.push(t('opencase', 'Titel ændret fra "{from}" til "{to}"', d.title))
				}
				if (d.document_type) {
					parts.push(t('opencase', 'Dokumenttype ændret fra "{from}" til "{to}"', d.document_type))
				}
				return parts.length ? parts.join('; ') : t('opencase', 'Metadata ændret')
			}
			case 'document_status_changed': {
				const from = this.statusName(d.from_status_id)
				const to   = this.statusName(d.to_status_id)
				return t('opencase', 'Status ændret fra "{from}" til "{to}"', { from, to })
			}
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
			case 'document_contact_added':
				return t('opencase', 'Kontakt tilføjet: {role} – {name} ({cprCvr})', {
					role: d.role || '',
					name: d.name || '',
					cprCvr: maskedPartyNumber(d),
				})
			case 'document_contact_deleted':
				return t('opencase', 'Kontakt slettet: {role} – {name} ({cprCvr})', {
					role: d.role || '',
					name: d.name || '',
					cprCvr: maskedPartyNumber(d),
				})
			case 'document_note_created':
				return t('opencase', 'Note oprettet: {title}', { title: d.title || '' })
			case 'document_note_updated':
				return t('opencase', 'Note opdateret: {title}', { title: d.title || '' })
			case 'document_note_deleted':
				return t('opencase', 'Note slettet: {title}', { title: d.title || '' })
			case 'digital_post_sent':
				return t('opencase', 'Digital post sendt til {name}', { name: d.receiver_name || maskCpr(d.cpr_cvr) || '' })
			case 'digital_post_failed':
				return t('opencase', 'Digital post fejlede for {name}', { name: d.receiver_name || maskCpr(d.cpr_cvr) || '' })
			case 'document_sent_by_email':
				return t('opencase', 'Dokument sendt med email til {to}', { to: (d.to || []).join(', ') || '' })
			case 'document_send_by_email_failed':
				return t('opencase', 'Afsendelse af email fejlede: {error}', { error: d.error || '' })
			case 'distribution_receipt_sent':
				return t('opencase', 'Forretningskvittering sendt til Fordelingskomponent')
			case 'distribution_receipt_failed':
				return t('opencase', 'Forretningskvittering kunne ikke sendes til Fordelingskomponent')
			case 'document_moved_to_case':
				return t('opencase', 'Dokument journaliseret fra sag {from} til sag {to} – nyt dokumentnummer: {dn}', {
					from: d.from_case_number || '',
					to:   d.to_case_number   || '',
					dn:   d.new_document_number || '',
				})
			case 'workflow_created': {
				const wfType = d.type === 'review' ? t('opencase', 'gennemse') : t('opencase', 'godkendelse')
				return t('opencase', 'Workflow oprettet ({type}) med {n} trin', { type: wfType, n: (d.users || []).length })
			}
			case 'workflow_step_reviewed':
				return d.comment
					? t('opencase', 'Dokument gennemset – kommentar: {comment}', { comment: d.comment })
					: t('opencase', 'Dokument gennemset')
			case 'workflow_step_approved':
				return t('opencase', 'Dokument godkendt')
			case 'workflow_step_rejected':
				return d.comment
					? t('opencase', 'Dokument afvist – begrundelse: {comment}', { comment: d.comment })
					: t('opencase', 'Dokument afvist')
			case 'workflow_completed':
				return t('opencase', 'Workflow fuldført')
			case 'workflow_rejected':
				return d.comment
					? t('opencase', 'Workflow afvist – begrundelse: {comment}', { comment: d.comment })
					: t('opencase', 'Workflow afvist')
			case 'workflow_cancelled':
				return t('opencase', 'Workflow annulleret')
			case 'cpr_event_received':
				return t('opencase', 'Hændelse "{description}" modtaget for {name}', {
					description: d.description || '',
					name: d.name || '',
				})
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
