<template>
	<div class="opencase-journal-notes">
		<div class="opencase-journal-notes__header">
			<NcButton v-if="canWrite" type="primary" @click="$emit('new')">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Ny journalnotat') }}
			</NcButton>
			<NcButton v-if="notes.length > 0" :disabled="generatingPdf" @click="generatePdf">
				<template #icon>
					<NcLoadingIcon v-if="generatingPdf" :size="20" />
					<FilePdfBoxIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Dan journalark') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="notes.length === 0"
			:title="t('opencase', 'Ingen journalnotater')">
			<template #icon>
				<NoteTextIcon :size="48" />
			</template>
		</NcEmptyContent>

		<div v-else class="opencase-journal-notes__list">
			<div v-for="note in notes"
				:key="note.id"
				class="opencase-journal-notes__item">
				<div class="opencase-journal-notes__icon">
					<LockIcon v-if="note.is_locked" :size="20" class="opencase-journal-notes__lock-icon" />
					<NoteTextIcon v-else :size="20" />
				</div>

				<div class="opencase-journal-notes__info">
					<span class="opencase-journal-notes__title">{{ note.title }}</span>
					<span class="opencase-journal-notes__meta">
						{{ note.created_by }} · {{ formatDate(note.created_at) }}
					</span>
				</div>

				<div class="opencase-journal-notes__actions">
					<template v-if="note.is_locked">
						<NcButton :aria-label="t('opencase', 'Vis journalnotat')"
							type="tertiary"
							@click="$emit('view', note)">
							<template #icon>
								<EyeIcon :size="20" />
							</template>
						</NcButton>
					</template>
					<template v-else>
						<NcButton :aria-label="t('opencase', 'Rediger journalnotat')"
							type="tertiary"
							@click="$emit('edit', note)">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
						</NcButton>
						<NcButton :aria-label="t('opencase', 'Lås journalnotat')"
							type="tertiary"
							@click="$emit('lock', note)">
							<template #icon>
								<LockOutlineIcon :size="20" />
							</template>
						</NcButton>
						<NcButton :aria-label="t('opencase', 'Slet journalnotat')"
							type="tertiary"
							@click="$emit('delete', note)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</template>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import NoteTextIcon from 'vue-material-design-icons/NoteText.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import FilePdfBoxIcon from 'vue-material-design-icons/FilePdfBox.vue'

export default {
	name: 'JournalNoteList',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PlusIcon,
		NoteTextIcon,
		PencilIcon,
		DeleteIcon,
		LockIcon,
		LockOutlineIcon,
		EyeIcon,
		FilePdfBoxIcon,
	},

	props: {
		notes: {
			type: Array,
			required: true,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		caseTitle: {
			type: String,
			default: '',
		},
		canWrite: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['new', 'edit', 'delete', 'lock', 'view'],

	data() {
		return {
			generatingPdf: false,
		}
	},

	methods: {
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

		formatDateLong(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		stripHtml(html) {
			if (!html) return ''
			const div = document.createElement('div')
			div.innerHTML = html
			return div.innerText || div.textContent || ''
		},

		parseHtmlToSegments(html) {
			if (!html) return []
			const div = document.createElement('div')
			div.innerHTML = html
			const segments = []
			const walk = (node) => {
				if (node.nodeType === Node.TEXT_NODE) {
					const text = node.textContent
					if (text) segments.push({ type: 'text', text })
					return
				}
				if (node.nodeType !== Node.ELEMENT_NODE) return
				const tag = node.tagName.toLowerCase()
				if (tag === 'h1') {
					segments.push({ type: 'h1', text: node.innerText || node.textContent })
				} else if (tag === 'h2') {
					segments.push({ type: 'h2', text: node.innerText || node.textContent })
				} else if (tag === 'h3') {
					segments.push({ type: 'h3', text: node.innerText || node.textContent })
				} else if (tag === 'p') {
					segments.push({ type: 'p', text: node.innerText || node.textContent })
				} else if (tag === 'blockquote') {
					segments.push({ type: 'blockquote', text: node.innerText || node.textContent })
				} else if (tag === 'ul') {
					for (const li of node.querySelectorAll('li')) {
						segments.push({ type: 'li', text: '• ' + (li.innerText || li.textContent) })
					}
				} else if (tag === 'ol') {
					let i = 1
					for (const li of node.querySelectorAll('li')) {
						segments.push({ type: 'li', text: `${i}. ` + (li.innerText || li.textContent) })
						i++
					}
				} else {
					for (const child of node.childNodes) walk(child)
				}
			}
			for (const child of div.childNodes) walk(child)
			return segments
		},

		async generatePdf() {
			this.generatingPdf = true
			try {
				const { jsPDF } = await import('jspdf')
				const doc = new jsPDF({ unit: 'mm', format: 'a4' })

				const pageW = doc.internal.pageSize.getWidth()
				const pageH = doc.internal.pageSize.getHeight()
				const marginL = 20
				const marginR = 20
				const marginT = 20
				const marginB = 20
				const contentW = pageW - marginL - marginR
				let y = marginT

				const checkPageBreak = (needed) => {
					if (y + needed > pageH - marginB) {
						doc.addPage()
						y = marginT
					}
				}

				// Cover header
				doc.setFont('helvetica', 'bold')
				doc.setFontSize(18)
				doc.text(t('opencase', 'Journalark'), marginL, y)
				y += 8

				if (this.caseTitle) {
					doc.setFont('helvetica', 'normal')
					doc.setFontSize(11)
					doc.text(this.caseTitle, marginL, y)
					y += 6
				}

				doc.setFontSize(9)
				doc.setTextColor(120, 120, 120)
				doc.text(
					t('opencase', 'Genereret') + ': ' + this.formatDateLong(new Date().toISOString()),
					marginL, y,
				)
				doc.setTextColor(0, 0, 0)
				y += 4

				// Divider
				doc.setDrawColor(180, 180, 180)
				doc.line(marginL, y, pageW - marginR, y)
				y += 8

				for (const note of this.notes) {
					checkPageBreak(20)

					// Note title
					doc.setFont('helvetica', 'bold')
					doc.setFontSize(13)
					const titleLines = doc.splitTextToSize(note.title, contentW)
					checkPageBreak(titleLines.length * 6 + 4)
					doc.text(titleLines, marginL, y)
					y += titleLines.length * 6

					// Note meta
					doc.setFont('helvetica', 'normal')
					doc.setFontSize(8)
					doc.setTextColor(120, 120, 120)
					let meta = note.created_by + ' · ' + this.formatDateLong(note.created_at)
					if (note.is_locked) meta += ' · ' + t('opencase', 'Låst')
					doc.text(meta, marginL, y)
					doc.setTextColor(0, 0, 0)
					y += 5

					// Note body
					const segments = this.parseHtmlToSegments(note.text)
					for (const seg of segments) {
						if (!seg.text || !seg.text.trim()) continue
						if (seg.type === 'h1') {
							doc.setFont('helvetica', 'bold')
							doc.setFontSize(12)
							const lines = doc.splitTextToSize(seg.text.trim(), contentW)
							checkPageBreak(lines.length * 6 + 2)
							doc.text(lines, marginL, y)
							y += lines.length * 6 + 1
						} else if (seg.type === 'h2') {
							doc.setFont('helvetica', 'bold')
							doc.setFontSize(11)
							const lines = doc.splitTextToSize(seg.text.trim(), contentW)
							checkPageBreak(lines.length * 5 + 2)
							doc.text(lines, marginL, y)
							y += lines.length * 5 + 1
						} else if (seg.type === 'h3') {
							doc.setFont('helvetica', 'bold')
							doc.setFontSize(10)
							const lines = doc.splitTextToSize(seg.text.trim(), contentW)
							checkPageBreak(lines.length * 5 + 1)
							doc.text(lines, marginL, y)
							y += lines.length * 5 + 1
						} else if (seg.type === 'blockquote') {
							doc.setFont('helvetica', 'italic')
							doc.setFontSize(9.5)
							doc.setTextColor(100, 100, 100)
							const lines = doc.splitTextToSize(seg.text.trim(), contentW - 6)
							checkPageBreak(lines.length * 5 + 1)
							doc.setDrawColor(150, 150, 150)
							doc.line(marginL + 1, y - 3, marginL + 1, y + lines.length * 5 - 2)
							doc.text(lines, marginL + 5, y)
							doc.setTextColor(0, 0, 0)
							doc.setDrawColor(0, 0, 0)
							y += lines.length * 5 + 1
						} else {
							doc.setFont('helvetica', 'normal')
							doc.setFontSize(10)
							const indent = seg.type === 'li' ? marginL + 4 : marginL
							const w = seg.type === 'li' ? contentW - 4 : contentW
							const lines = doc.splitTextToSize(seg.text.trim(), w)
							checkPageBreak(lines.length * 5)
							doc.text(lines, indent, y)
							y += lines.length * 5
						}
					}

					y += 4
					// Separator between notes
					doc.setDrawColor(220, 220, 220)
					doc.line(marginL, y, pageW - marginR, y)
					y += 6
				}

				// Page numbers
				const totalPages = doc.internal.getNumberOfPages()
				for (let i = 1; i <= totalPages; i++) {
					doc.setPage(i)
					doc.setFont('helvetica', 'normal')
					doc.setFontSize(8)
					doc.setTextColor(150, 150, 150)
					doc.text(`${i} / ${totalPages}`, pageW / 2, pageH - 8, { align: 'center' })
					doc.setTextColor(0, 0, 0)
				}

				doc.save('journalark.pdf')
			} finally {
				this.generatingPdf = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-journal-notes__header {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.opencase-journal-notes__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-journal-notes__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border-radius: 8px;
	transition: background 0.15s;
}

.opencase-journal-notes__item:hover {
	background: var(--color-background-hover);
}

.opencase-journal-notes__icon {
	color: var(--color-text-lighter);
	flex-shrink: 0;
}

.opencase-journal-notes__lock-icon {
	color: var(--color-warning);
}

.opencase-journal-notes__info {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-journal-notes__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.opencase-journal-notes__meta {
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.opencase-journal-notes__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}
</style>
