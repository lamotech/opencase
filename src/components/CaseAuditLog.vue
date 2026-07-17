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
import AccountCheckIcon from 'vue-material-design-icons/AccountCheck.vue'
import AccountRemoveIcon from 'vue-material-design-icons/AccountRemove.vue'
import AccountPlusOutlineIcon from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountMinusIcon from 'vue-material-design-icons/AccountMinus.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import NoteTextIcon from 'vue-material-design-icons/NoteText.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import ReceiptSendOutlineIcon from 'vue-material-design-icons/ReceiptSendOutline.vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'

import api from '../services/api.js'

const STATUS_NAMES = {
	1: 'Åben',
	2: 'Lukket',
	3: 'Arkiveret',
}

export default {
	name: 'CaseAuditLog',
	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		HistoryIcon,
		AccountPlusIcon,
		AccountCheckIcon,
		AccountRemoveIcon,
		AccountPlusOutlineIcon,
		AccountMinusIcon,
		EyeIcon,
		PencilIcon,
		SwapHorizontalIcon,
		FileDocumentIcon,
		NoteTextIcon,
		LockIcon,
		DeleteIcon,
		BellIcon,
		ReceiptSendOutlineIcon,
		AlertCircleOutlineIcon,
	},
	props: {
		caseId: { type: [String, Number], required: true },
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
		caseId: {
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
				const result = await api.getAuditLog(this.caseId, { limit: this.limit, offset: this.offset })
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
			case 'case_created':        return AccountPlusIcon
			case 'case_viewed':         return EyeIcon
			case 'case_metadata_changed': return PencilIcon
			case 'case_status_changed': return SwapHorizontalIcon
			case 'document_created':    return FileDocumentIcon
			case 'case_access_granted':    return AccountCheckIcon
			case 'case_access_revoked':    return AccountRemoveIcon
			case 'case_participant_added':   return AccountPlusOutlineIcon
			case 'case_participant_deleted': return AccountMinusIcon
			case 'case_caseworker_added':    return AccountPlusIcon
			case 'case_caseworker_removed':  return AccountMinusIcon
			case 'journal_note_created':     return NoteTextIcon
			case 'journal_note_updated':     return PencilIcon
			case 'journal_note_deleted':     return DeleteIcon
			case 'journal_note_locked':      return LockIcon
			case 'journal_note_receipt_sent':   return ReceiptSendOutlineIcon
			case 'journal_note_receipt_failed': return AlertCircleOutlineIcon
			case 'reminder_created':         return BellIcon
			case 'reminder_updated':         return BellIcon
			case 'reminder_deleted':         return DeleteIcon
			case 'cpr_event_received':       return SwapHorizontalIcon
			default:                         return HistoryIcon
			}
		},
		describe(entry) {
			const d = entry.details || {}
			switch (entry.event_type) {
			case 'case_created':
				return t('opencase', 'Sag oprettet')
			case 'case_viewed':
				return t('opencase', 'Sag åbnet')
			case 'case_metadata_changed': {
				const parts = []
				if (d.title) {
					parts.push(t('opencase', 'Titel ændret fra "{from}" til "{to}"', d.title))
				}
				if (d.organisation) {
					parts.push(t('opencase', 'Organisation ændret fra "{from}" til "{to}"', d.organisation))
				}
				if (d.classification_code) {
					parts.push(t('opencase', 'KLE-nummer ændret fra "{from}" til "{to}"', d.classification_code))
				}
				if (d.sensitivity) {
					parts.push(t('opencase', 'Følsomhed ændret fra "{from}" til "{to}"', d.sensitivity))
				}
				return parts.length ? parts.join('; ') : t('opencase', 'Metadata ændret')
			}
			case 'case_status_changed': {
				const from = STATUS_NAMES[d.from_status_id] ?? d.from_status_id
				const to   = STATUS_NAMES[d.to_status_id]   ?? d.to_status_id
				return t('opencase', 'Status ændret fra "{from}" til "{to}"', { from, to })
			}
			case 'document_created':
				return t('opencase', 'Dokument oprettet: {number} – {title}', {
					number: d.document_number || '',
					title:  d.title || '',
				})
			case 'case_access_granted': {
				const level = d.can_write ? t('opencase', 'skriveadgang') : t('opencase', 'læseadgang')
				const expiry = d.expires_at
					? t('opencase', ', udløber {date}', { date: new Date(d.expires_at).toLocaleDateString('da-DK') })
					: ''
				return t('opencase', 'Adgang tildelt: {user} ({level}{expiry})', {
					user: d.granted_user || '',
					level,
					expiry,
				})
			}
			case 'case_access_revoked':
				return t('opencase', 'Adgang fjernet: {user}', { user: d.revoked_user || '' })
			case 'case_participant_added':
				return t('opencase', 'Part tilføjet: {role} – {name} ({cvr})', {
					role: d.role || '',
					name: d.name || '',
					cvr:  d.cvr  || '',
				})
			case 'case_participant_deleted':
				return t('opencase', 'Part slettet: {role} – {name} ({cvr})', {
					role: d.role || '',
					name: d.name || '',
					cvr:  d.cvr  || '',
				})
			case 'case_caseworker_added':
				return t('opencase', 'Sagsbehandler tilføjet: {name}', { name: d.display_name || d.added_user || '' })
			case 'case_caseworker_removed':
				return t('opencase', 'Sagsbehandler fjernet: {name}', { name: d.display_name || d.removed_user || '' })
			case 'journal_note_created':
				return t('opencase', 'Journalnotat oprettet: {title}', { title: d.title || '' })
			case 'journal_note_updated':
				return t('opencase', 'Journalnotat opdateret: {title}', { title: d.title || '' })
			case 'journal_note_deleted':
				return t('opencase', 'Journalnotat slettet: {title}', { title: d.title || '' })
			case 'journal_note_locked':
				return t('opencase', 'Journalnotat låst: {title}', { title: d.title || '' })
			case 'journal_note_receipt_sent':
				return t('opencase', 'Forretningskvittering sendt til Fordelingskomponent for journalnotat {title}', { title: d.title || '' })
			case 'journal_note_receipt_failed':
				return t('opencase', 'Forretningskvittering kunne ikke sendes til Fordelingskomponent for journalnotat {title}', { title: d.title || '' })
			case 'reminder_created':
				return t('opencase', 'Påmindelse oprettet: {title}', { title: d.title || '' })
			case 'reminder_updated': {
				const changeParts = []
				if (d.changes?.status) {
					const statusLabel = d.changes.status === 'completed'
						? t('opencase', 'afsluttet')
						: t('opencase', 'aktiv')
					changeParts.push(t('opencase', 'status → {status}', { status: statusLabel }))
				}
				if (d.changes?.responsible_user_id) {
					changeParts.push(t('opencase', 'ansvarlig → {user}', { user: d.changes.responsible_user_id }))
				}
				const suffix = changeParts.length ? ' (' + changeParts.join(', ') + ')' : ''
				return t('opencase', 'Påmindelse opdateret: {title}', { title: d.title || '' }) + suffix
			}
			case 'reminder_deleted':
				return t('opencase', 'Påmindelse slettet: {title}', { title: d.title || '' })
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
