<template>
	<div class="opencase-doc-detail">
		<NcLoadingIcon v-if="loading" :size="44" class="opencase-doc-detail__loading" />

		<template v-else-if="document">
			<NcBreadcrumbs>
				<NcBreadcrumb :title="t('opencase', 'Sager')" :to="{ name: 'cases' }" />
				<NcBreadcrumb v-if="parentCase"
					:title="parentCase.case_number"
					:to="{ name: 'case-detail', params: { id: parentCase.id } }" />
				<NcBreadcrumb :title="document.title" :disableDrop="true" />
			</NcBreadcrumbs>

			<!-- Document header -->
			<div class="opencase-doc-detail__header">
				<div>
					<div class="opencase-doc-detail__title-row">
						<h2>{{ document.title }}</h2>
						<span v-if="document.document_number" class="opencase-doc-detail__docnumber">
							{{ document.document_number }}
						</span>
					</div>
					<div class="opencase-doc-detail__meta">
						<span v-if="document.document_type" class="opencase-doc-detail__type">
							{{ document.document_type }}
						</span>
						<CaseStatusBadge :status="document.status" :label="document.status_name" />
						<span class="opencase-doc-detail__date">
							{{ t('opencase', 'Oprettet {date}', { date: formatDate(document.created_at) }) }}
						</span>
					</div>
					<div v-if="nextReminder"
						class="opencase-doc-detail__reminder-banner"
						:class="{ 'opencase-doc-detail__reminder-banner--overdue': isReminderOverdue(nextReminder) }"
						@click="activeTab = 'info'">
						<BellAlertIcon :size="15" />
						<span>{{ nextReminder.title }}</span>
						<span v-if="nextReminder.deadline" class="opencase-doc-detail__reminder-deadline">
							{{ formatReminderDate(nextReminder.deadline) }}
						</span>
					</div>
					<div v-if="activeWorkflow && workflowProgress" class="opencase-doc-detail__wf-progress">
						<div class="opencase-doc-detail__wf-progress-label">
							<SitemapIcon :size="14" />
							{{ workflowTypeLabel(activeWorkflow.type) }}:
							{{ t('opencase', '{done} / {total} trin', { done: workflowProgress.done, total: workflowProgress.total }) }}
						</div>
						<div class="opencase-doc-detail__wf-progress-track">
							<div class="opencase-doc-detail__wf-progress-fill"
								:style="{ width: workflowProgress.percent + '%' }" />
						</div>
					</div>
				</div>
				<div class="opencase-doc-detail__actions">
					<FavoriteButton entity="document" :entity-key="document.id" />
					<NcActions ref="headerActions">
					<NcActionButton v-if="canWrite" @click="showEditDialog = true">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('opencase', 'Rediger') }}
					</NcActionButton>
					<NcActionButton v-if="canWrite && !isFinal" @click="showReminderDialog = true">
						<template #icon>
							<BellPlusOutlineIcon :size="20" />
						</template>
						{{ t('opencase', 'Ny påmindelse') }}
					</NcActionButton>
					<NcActionButton v-if="canJournalize"
						@click="showMoveToCaseDialog = true">
						<template #icon>
							<FolderArrowRightIcon :size="20" />
						</template>
						{{ t('opencase', 'Journaliser på eksisterende sag') }}
					</NcActionButton>
					<NcActionButton v-if="canJournalize"
						@click="showMoveToNewCaseDialog = true">
						<template #icon>
							<FolderPlusIcon :size="20" />
						</template>
						{{ t('opencase', 'Journaliser på ny sag') }}
					</NcActionButton>
					<NcActionButton @click="showShareDialog = true">
						<template #icon>
							<ShareVariantIcon :size="20" />
						</template>
						{{ t('opencase', 'Del') }}
					</NcActionButton>
					<NcActionButton v-if="canWrite && !isFinal && document.status === 1"
						destructive
						@click="deleteDoc">
						{{ t('opencase', 'Slet') }}
					</NcActionButton>
					<NcActionCaption v-if="((isFinal || canWrite) && digitalPostEnabled) || mailAppEnabled" :name="t('opencase', 'Forsendelse')" />
					<NcActionButton v-if="(isFinal || canWrite) && digitalPostEnabled" @click="openDigitalPost">
						<template #icon>
							<EmailArrowRightIcon :size="20" />
						</template>
						{{ t('opencase', 'Digital post') }}
					</NcActionButton>
					<NcActionButton v-if="mailAppEnabled" @click="openSendEmail">
						<template #icon>
							<EmailFastOutlineIcon :size="20" />
						</template>
						{{ t('opencase', 'Send med email') }}
					</NcActionButton>
					<template v-if="canWrite && !isFinal && favoriteAiPrompts.length > 0">
						<NcActionCaption :name="t('opencase', 'Favorit-prompts')" />
						<NcActionButton v-for="p in favoriteAiPrompts"
							:key="p.id"
							@click="openAiPrompt(p.id)">
							<template #icon>
								<AutoFixIcon :size="20" />
							</template>
							{{ p.title }}
						</NcActionButton>
					</template>
				</NcActions>
				</div>
			</div>

			<!-- Tabs -->
			<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Info tab -->
			<div v-if="activeTab === 'info'" class="opencase-doc-detail__tab-content">
				<DocumentInfoPanel :document="document" :parent-case="parentCase" />
				<ReminderList
					:reminders="reminders"
					:loading="remindersLoading"
					@updated="onReminderUpdated" />
			</div>

			<!-- Filer tab -->
			<div v-else-if="activeTab === 'files'" class="opencase-doc-detail__tab-content">
				<div v-if="canWrite && !isFinal" class="opencase-doc-detail__content-header">
					<NcButton @click="showTemplateDialog = true">
						<template #icon>
							<FileDocumentEditOutlineIcon :size="20" />
						</template>
						{{ t('opencase', 'Ny fil') }}
					</NcButton>
					<FileUploadButton :document-id="document.id"
						@uploaded="loadFiles" />
				</div>

				<NcLoadingIcon v-if="filesLoading" :size="32" />
				<NcEmptyContent v-else-if="files.length === 0"
					:title="t('opencase', 'Ingen filer endnu')"
					:description="t('opencase', 'Upload en fil for at tilføje den til dette dokument')">
					<template #icon>
						<FileIcon :size="48" />
					</template>
				</NcEmptyContent>

				<FileList v-else
					:files="files"
					:is-read-only="!canWrite || document.is_final"
					show-version
					@delete="onDeleteFile" />
			</div>

			<!-- Afsendere/Modtagere tab -->
			<div v-else-if="activeTab === 'contacts'" class="opencase-doc-detail__tab-content">
				<DocumentContactList :document-id="document.id" :can-write="canWrite && !isFinal" @count-changed="contactsCount = $event" />
			</div>

			<!-- Noter tab -->
			<div v-else-if="activeTab === 'notes'" class="opencase-doc-detail__tab-content">
				<DocumentNoteList
					:notes="documentNotes"
					:loading="documentNotesLoading"
					:can-write="canWrite && !isFinal"
					@new="showDocumentNoteDialog = true"
					@edit="openEditDocumentNote"
					@delete="deleteDocumentNote" />

				<DocumentNoteDialog v-if="showDocumentNoteDialog"
					:document-id="document.id"
					:note="editingDocumentNote"
					@close="closeDocumentNoteDialog"
					@saved="onDocumentNoteSaved" />
			</div>

			<!-- Log tab -->
			<div v-else-if="activeTab === 'log'" class="opencase-doc-detail__tab-content">
				<DocumentAuditLog :document-id="document.id" />
			</div>

			<!-- Workflow tab -->
			<div v-else-if="activeTab === 'workflow'" class="opencase-doc-detail__tab-content">
				<WorkflowPanel :document-id="document.id"
					:can-write="canWrite && !isFinal"
					@workflow-changed="onWorkflowChanged" />
			</div>

			<!-- Send with email dialog -->
			<SendEmailDialog v-if="showSendEmailDialog"
				:document-id="document.id"
				:from-address="emailAccount.address"
				:from-label-text="emailAccount.label"
				@close="showSendEmailDialog = false" />

			<!-- Digital post: new shipment dialog -->
			<DigitalPostDialog v-if="showDigitalPostDialog"
				:document-id="document.id"
				@close="showDigitalPostDialog = false" />

			<!-- Digital post: shipment status dialog -->
			<DigitalPostStatusDialog v-if="showDigitalPostStatusDialog && latestShipmentData"
				:document-id="document.id"
				:initial-shipment="latestShipmentData"
				@close="showDigitalPostStatusDialog = false"
				@new-shipment="onNewShipment" />

			<!-- Move to new case dialog -->
			<MoveToNewCaseDialog v-if="showMoveToNewCaseDialog"
				:document-id="document.id"
				@moved="onMovedToNewCase"
				@close="showMoveToNewCaseDialog = false" />

			<!-- Move to case dialog -->
			<MoveToCaseDialog v-if="showMoveToCaseDialog"
				:document-id="document.id"
				@moved="onMovedToCase"
				@close="showMoveToCaseDialog = false" />

			<!-- Edit dialog -->
			<EditDocumentDialog v-if="showEditDialog"
				:document="document"
				@close="showEditDialog = false"
				@saved="onSaved" />

			<!-- New file from template dialog -->
			<NewFileFromTemplateDialog v-if="showTemplateDialog"
				:document-id="document.id"
				@created="onFileCreatedFromTemplate"
				@close="showTemplateDialog = false" />

			<!-- Document share dialog -->
			<DocumentShareDialog
				:show="showShareDialog"
				:document-id="document.id"
				:is-read-only="!canWrite"
				@close="showShareDialog = false" />

			<!-- Reminder dialog -->
			<ReminderDialog v-if="showReminderDialog"
				entity="document"
				:entity-key="document.id"
				@close="showReminderDialog = false"
				@created="onReminderCreated" />
		</template>
	</div>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'
import NcBreadcrumbs from '@nextcloud/vue/components/NcBreadcrumbs'
import NcBreadcrumb from '@nextcloud/vue/components/NcBreadcrumb'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue'
import FolderArrowRightIcon from 'vue-material-design-icons/FolderArrowRight.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import EmailArrowRightIcon from 'vue-material-design-icons/EmailArrowRight.vue'
import EmailFastOutlineIcon from 'vue-material-design-icons/EmailFastOutline.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import EmailOutlineIcon from 'vue-material-design-icons/EmailOutline.vue'
import NoteTextOutlineIcon from 'vue-material-design-icons/NoteTextOutline.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import BellPlusOutlineIcon from 'vue-material-design-icons/BellPlusOutline.vue'
import BellAlertIcon from 'vue-material-design-icons/BellAlert.vue'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import FileList from '../components/FileList.vue'
import FileUploadButton from '../components/FileUploadButton.vue'
import EditDocumentDialog from '../components/EditDocumentDialog.vue'
import DocumentInfoPanel from '../components/DocumentInfoPanel.vue'
import DocumentAuditLog from '../components/DocumentAuditLog.vue'
import DocumentContactList from '../components/DocumentContactList.vue'
import DocumentNoteList from '../components/DocumentNoteList.vue'
import DocumentNoteDialog from '../components/DocumentNoteDialog.vue'
import NewFileFromTemplateDialog from '../components/NewFileFromTemplateDialog.vue'
import MoveToCaseDialog from '../components/MoveToCaseDialog.vue'
import MoveToNewCaseDialog from '../components/MoveToNewCaseDialog.vue'
import SendEmailDialog from '../components/SendEmailDialog.vue'
import DigitalPostDialog from '../components/DigitalPostDialog.vue'
import DigitalPostStatusDialog from '../components/DigitalPostStatusDialog.vue'
import DocumentShareDialog from '../components/DocumentShareDialog.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import FavoriteButton from '../components/FavoriteButton.vue'
import WorkflowPanel from '../components/WorkflowPanel.vue'
import ReminderList from '../components/ReminderList.vue'
import ReminderDialog from '../components/ReminderDialog.vue'

import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import eventBus from '../utils/eventBus.js'

export default {
	name: 'DocumentDetailView',

	components: {
		NcBreadcrumbs, NcBreadcrumb, NcButton, NcActions, NcActionButton, NcActionCaption,
		NcEmptyContent, NcLoadingIcon, AutoFixIcon, FolderArrowRightIcon, FolderPlusIcon, PencilIcon, FileIcon,
		FileDocumentEditOutlineIcon, EmailArrowRightIcon, EmailFastOutlineIcon, ShareVariantIcon,
		MoveToCaseDialog,
		MoveToNewCaseDialog,
		CaseStatusBadge, FileList, FileUploadButton, EditDocumentDialog,
		DocumentInfoPanel, DocumentAuditLog, DocumentContactList,
		DocumentNoteList, DocumentNoteDialog, NewFileFromTemplateDialog,
		SendEmailDialog,
		DigitalPostDialog,
		DigitalPostStatusDialog,
		DocumentShareDialog,
		OverflowTabs,
		FavoriteButton,
		WorkflowPanel,
		SitemapIcon,
		BellPlusOutlineIcon,
		BellAlertIcon,
		ReminderList,
		ReminderDialog,
	},

	props: {
		id: { type: [String, Number], required: true },
	},

	data() {
		const hashTab = window.location.hash.replace('#tab-', '')
		const validDocTabs = ['info', 'files', 'contacts', 'notes', 'log', 'workflow']
		return {
			loading: true,
			document: null,
			parentCase: null,
			files: [],
			filesLoading: false,
			showEditDialog: false,
			showTemplateDialog: false,
			showDigitalPostDialog: false,
			showDigitalPostStatusDialog: false,
			showSendEmailDialog: false,
			emailAccount: { address: '', label: '' },
			showMoveToCaseDialog: false,
			showMoveToNewCaseDialog: false,
			showShareDialog: false,
			latestShipmentData: null,
			activeTab: validDocTabs.includes(hashTab) ? hashTab : 'info',
			documentNotes: [],
			documentNotesLoading: false,
			contactsCount: null,
			showDocumentNoteDialog: false,
			editingDocumentNote: null,
			favoriteAiPrompts: [],
			aiEnabled: loadState('opencase', 'ai_enabled', false),
			activeWorkflow: null,
			showReminderDialog: false,
			reminders: [],
			remindersLoading: false,
		}
	},

	computed: {
		canWrite() { return this.document?.can_write ?? this.parentCase?.can_write ?? false },
		isFinal() { return !!this.document?.is_final },
		isIncoming() { return this.document?.document_category_id === 1 },
		canJournalize() {
			if (!this.canWrite || !this.parentCase || !this.parentCase.is_inbox) return false
			return !this.isFinal || this.isIncoming
		},
		digitalPostEnabled() { return this.$store.state.digitalPostEnabled },
		mailAppEnabled() { return this.$store.state.mailAppEnabled },

		nextReminder() {
			const active = this.reminders.filter(r => r.status === 'active' && r.deadline)
			if (!active.length) return null
			return active.sort((a, b) => new Date(a.deadline) - new Date(b.deadline))[0]
		},

		workflowProgress() {
			if (!this.activeWorkflow?.steps?.length) return null
			const total = this.activeWorkflow.steps.length
			const done = this.activeWorkflow.steps.filter(s => s.status !== 'pending').length
			return { total, done, percent: Math.round(done / total * 100) }
		},

		tabs() {
			return [
				{ id: 'info', label: t('opencase', 'Info'), icon: InformationOutlineIcon },
				{ id: 'files', label: t('opencase', 'Filer'), count: this.files.length || null, icon: FolderOutlineIcon },
				{ id: 'contacts', label: t('opencase', 'Afsendere/Modtagere'), count: this.contactsCount, icon: EmailOutlineIcon },
				{ id: 'notes', label: t('opencase', 'Noter'), count: this.documentNotes.length || null, icon: NoteTextOutlineIcon },
				{ id: 'log', label: t('opencase', 'Log'), icon: HistoryIcon },
				{ id: 'workflow', label: t('opencase', 'Workflow'), icon: SitemapIcon },
			]
		},
	},

	mounted() {
		this._aiMovedHandler = (doc) => {
			if (String(doc.id) === String(this.id)) this.applyMovedDocument(doc)
		}
		this._aiFilesChangedHandler = (docId) => {
			if (String(docId) === String(this.id)) this.loadFiles()
		}
		this._aiNotesChangedHandler = (docId) => {
			if (String(docId) === String(this.id)) {
				api.getDocumentNotes(this.id).then(r => { this.documentNotes = r }).catch(() => {})
			}
		}
		eventBus.on('ai:document-moved', this._aiMovedHandler)
		eventBus.on('ai:files-changed', this._aiFilesChangedHandler)
		eventBus.on('ai:notes-changed', this._aiNotesChangedHandler)
	},

	beforeUnmount() {
		eventBus.off('ai:document-moved', this._aiMovedHandler)
		eventBus.off('ai:files-changed', this._aiFilesChangedHandler)
		eventBus.off('ai:notes-changed', this._aiNotesChangedHandler)
	},

	watch: {
		id: { immediate: true, handler() { this.load() } },
		activeTab(tab) {
			history.replaceState(null, '', '#tab-' + tab)
			if (tab === 'notes' && this.documentNotes.length === 0) {
				this.loadDocumentNotes()
			}
		},
	},

	methods: {
		async openDigitalPost() {
			try {
				const data = await api.getLatestDigitalPostShipment(this.id)
				if (data.shipment) {
					this.latestShipmentData = data
					this.showDigitalPostStatusDialog = true
				} else {
					this.showDigitalPostDialog = true
				}
			} catch (e) {
				this.showDigitalPostDialog = true
			}
		},

		onNewShipment() {
			this.showDigitalPostStatusDialog = false
			this.showDigitalPostDialog = true
		},

		async openSendEmail() {
			try {
				const status = await api.getEmailAccountStatus()
				if (!status.configured) {
					showError(t('opencase', 'Der er ikke konfigureret en e-mailkonto i Mail-appen. Opret venligst en e-mailkonto i Mail-appen først.'))
					return
				}
				this.emailAccount = status.from || { address: '', label: '' }
				this.showSendEmailDialog = true
			} catch (e) {
				showError(t('opencase', 'Kunne ikke kontrollere e-mailkonto'))
			}
		},

		async load() {
			this.loading = true
			try {
				this.document = await api.getDocument(this.id)
				this.$store.commit('SET_CURRENT_DOCUMENT', this.document)
				this.parentCase = await api.getCase(this.document.case_id, { audit: false }).catch(() => null)
				if (this.parentCase) {
					this.$store.commit('SET_CURRENT_CASE', this.parentCase)
				}
				await Promise.all([
					this.loadFiles().catch(() => {}),
					api.getDocumentContacts(this.id).then(r => { this.contactsCount = r.length }).catch(() => {}),
					api.getDocumentNotes(this.id).then(r => { this.documentNotes = r }).catch(() => {}),
					api.getActiveWorkflow(this.id).then(w => { this.activeWorkflow = w }).catch(() => { this.activeWorkflow = null }),
					api.getDocumentReminders(this.id).then(r => { this.reminders = r }).catch(() => {}),
				])
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse dokument'))
			} finally {
				this.loading = false
			}
			if (this.aiEnabled) {
				const scope = this.parentCase?.is_inbox ? 'inbox_document' : 'document'
				api.getFavoriteAiPrompts(scope).then(p => { this.favoriteAiPrompts = p }).catch(() => {})
			}
		},

		openAiPrompt(id) {
			// The prompt dialog opens via a global event bus into a distant sidebar
			// component, so NcActions' own click-outside detection can't reliably
			// close the menu once the dialog's overlay is on top of it — close it
			// explicitly first.
			this.$refs.headerActions?.closeMenu()
			emit('ai:open-prompt', id)
		},

		async loadFiles() {
			this.filesLoading = true
			try {
				this.files = await api.getFilesByDocument(this.id)
			} finally {
				this.filesLoading = false
			}
		},

		async deleteDoc() {
			if (!confirm(t('opencase', 'Er du sikker på, at du vil slette dette dokument?'))) return
			try {
				await api.deleteDocument(this.id)
				showSuccess(t('opencase', 'Dokument slettet'))
				this.$router.push({ name: 'case-detail', params: { id: this.document.case_id } })
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke slette'))
			}
		},

		async onDeleteFile(fileId) {
			try {
				await api.deleteFile(fileId)
				this.files = this.files.filter(f => f.id !== fileId)
				showSuccess(t('opencase', 'Fil slettet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke slette fil'))
			}
		},

		onSaved(updated) {
			this.document = updated
			this.showEditDialog = false
		},

		onFileCreatedFromTemplate(file) {
			this.files.push(file)
		},

		onMovedToCase(updatedDoc) {
			this.showMoveToCaseDialog = false
			this.applyMovedDocument(updatedDoc)
		},

		onMovedToNewCase({ doc, case: newCase }) {
			this.showMoveToNewCaseDialog = false
			this.applyMovedDocument(doc)
			// Navigate to the new case detail page after the document detail refreshes
			this.$router.push({ name: 'case-detail', params: { id: String(newCase.id) } })
		},

		applyMovedDocument(updatedDoc) {
			this.document = updatedDoc
			this.$store.commit('SET_CURRENT_DOCUMENT', updatedDoc)
			this.parentCase = null
			this.load()
		},

		workflowTypeLabel(type) {
			return type === 'review'
				? t('opencase', 'Gennemse')
				: t('opencase', 'Godkendelse')
		},

		onWorkflowChanged(workflow) {
			this.activeWorkflow = workflow
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'long', day: 'numeric',
			})
		},

		async loadDocumentNotes() {
			this.documentNotesLoading = true
			try {
				this.documentNotes = await api.getDocumentNotes(this.id)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse noter'))
			} finally {
				this.documentNotesLoading = false
			}
		},

		openEditDocumentNote(note) {
			this.editingDocumentNote = note
			this.showDocumentNoteDialog = true
		},

		closeDocumentNoteDialog() {
			this.showDocumentNoteDialog = false
			this.editingDocumentNote = null
		},

		onDocumentNoteSaved(saved) {
			const idx = this.documentNotes.findIndex(n => n.id === saved.id)
			if (idx !== -1) {
				this.documentNotes[idx] = saved
			} else {
				this.documentNotes.unshift(saved)
			}
			this.closeDocumentNoteDialog()
		},

		onReminderCreated(reminder) {
			this.reminders.unshift(reminder)
			this.showReminderDialog = false
		},

		onReminderUpdated(updated) {
			const idx = this.reminders.findIndex(r => r.id === updated.id)
			if (idx !== -1) {
				this.reminders[idx] = updated
			}
		},

		isReminderOverdue(reminder) {
			if (!reminder?.deadline) return false
			return new Date(reminder.deadline) < new Date()
		},

		formatReminderDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},

		async deleteDocumentNote(note) {
			if (!confirm(t('opencase', 'Er du sikker på, at du vil slette denne note?'))) return
			try {
				await api.deleteDocumentNote(note.id)
				this.documentNotes = this.documentNotes.filter(n => n.id !== note.id)
				showSuccess(t('opencase', 'Note slettet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke slette note'))
			}
		},
	},
}
</script>

<style scoped>
.opencase-doc-detail {
	padding: 20px;
	max-width: 1000px;
}

.opencase-doc-detail__loading { margin: 60px auto; }

.opencase-doc-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 24px;
}

.opencase-doc-detail__actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.opencase-doc-detail__title-row { display: flex; align-items: baseline; gap: 12px; }
.opencase-doc-detail__title-row h2 { margin: 0 0 6px; font-size: 1.3em; }
.opencase-doc-detail__docnumber { font-size: 0.85em; color: var(--color-text-maxcontrast); font-family: monospace; white-space: nowrap; }

.opencase-doc-detail__meta {
	display: flex; align-items: center; gap: 10px;
	color: var(--color-text-lighter); font-size: 0.9em;
}

.opencase-doc-detail__type {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
	font-weight: 600;
}


.opencase-doc-detail__wf-progress {
	margin-top: 10px;
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 400px;
}

.opencase-doc-detail__wf-progress-label {
	display: flex;
	align-items: center;
	gap: 5px;
	font-size: 0.82em;
	color: var(--color-text-lighter);
}

.opencase-doc-detail__wf-progress-track {
	height: 6px;
	border-radius: 3px;
	background: var(--color-primary-element-light);
	overflow: hidden;
}

.opencase-doc-detail__wf-progress-fill {
	height: 100%;
	border-radius: 3px;
	background: var(--color-primary-element);
	transition: width 0.3s ease;
}

.opencase-doc-detail__reminder-banner {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-top: 8px;
	padding: 4px 10px;
	border-radius: 6px;
	background: var(--color-warning-hover, #fff3cd);
	color: var(--color-main-text);
	font-size: 0.875em;
	cursor: pointer;
	border: 1px solid var(--color-warning, #ffc107);
}

.opencase-doc-detail__reminder-banner:hover {
	opacity: 0.85;
}

.opencase-doc-detail__reminder-banner--overdue {
	background: var(--color-error-hover, #fce4ec);
	border-color: var(--color-error, #c62828);
	color: var(--color-error, #c62828);
	font-weight: 600;
}

.opencase-doc-detail__reminder-deadline {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.opencase-doc-detail__reminder-banner--overdue .opencase-doc-detail__reminder-deadline {
	color: inherit;
}

.opencase-doc-detail__tab-content {
	padding-top: 4px;
}

.opencase-doc-detail__content-header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}
</style>
