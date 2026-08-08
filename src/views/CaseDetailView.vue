<template>
	<div class="opencase-case-detail">
		<NcLoadingIcon v-if="loading" :size="44" class="opencase-case-detail__loading" />

		<template v-else-if="caseData">
			<!-- Breadcrumb -->
			<NcBreadcrumbs class="opencase-case-detail__breadcrumbs">
				<NcBreadcrumb :title="t('opencase', 'Sager')" :to="{ name: 'cases' }" />
				<NcBreadcrumb :title="caseData.organisation"
					:to="{ name: 'cases', query: { organisation: caseData.organisation } }" />
				<NcBreadcrumb :title="caseData.case_number" :disableDrop="true" />
			</NcBreadcrumbs>

			<!-- Case header -->
			<div class="opencase-case-detail__header">
				<div class="opencase-case-detail__header-left">
					<h2>{{ caseData.title }}</h2>
					<div class="opencase-case-detail__meta">
						<span class="opencase-case-detail__case-number">{{ caseData.case_number }}</span>
						<span v-if="caseData.casetype_name" class="opencase-case-detail__casetype">{{ caseData.casetype_name }}</span>
						<CaseStatusBadge :status="caseData.status_class" :label="caseData.status_name" />
						<span class="opencase-case-detail__org">{{ caseData.organisation }}</span>
					</div>
					<div v-if="nextReminder" class="opencase-case-detail__reminder-banner"
						:class="{ 'opencase-case-detail__reminder-banner--overdue': isReminderOverdue(nextReminder) }"
						@click="activeTab = 'info'">
						<BellAlertIcon :size="15" />
						<span>{{ nextReminder.title }}</span>
						<span v-if="nextReminder.deadline" class="opencase-case-detail__reminder-deadline">
							{{ formatReminderDate(nextReminder.deadline) }}
						</span>
					</div>
					<div v-if="primaryParticipant" class="opencase-case-detail__primary-part">
						<button class="opencase-case-detail__primary-part-link" @click="openPrimaryPart(primaryParticipant)">
							<OfficeBuildingIcon v-if="isCompany(primaryParticipant)" :size="16" class="opencase-case-detail__primary-part-icon" />
							<AccountIcon v-else :size="16" class="opencase-case-detail__primary-part-icon" />
							<CprDisplay v-if="primaryParticipant.contact_type !== 1" :value="primaryParticipant.cpr_cvr" />
						</button>
						<span class="opencase-case-detail__primary-part-name">{{ primaryParticipant.name }}</span>
						<template v-if="primaryParticipant.has_address_protection">
							<div class="opencase-case-detail__protected-badge" @click="revealPrimaryAddress = !revealPrimaryAddress">
								{{ revealPrimaryAddress ? formatParticipantAddress(primaryParticipant) : t('opencase', 'Beskyttet adresse') }}
							</div>
						</template>
						<span v-else-if="formatParticipantAddress(primaryParticipant)" class="opencase-case-detail__primary-part-address">{{ formatParticipantAddress(primaryParticipant) }}</span>
						<span v-if="primaryParticipant.pnumber" class="opencase-case-detail__primary-part-pnumber">{{ t('opencase', 'P-nummer') }}: {{ primaryParticipant.pnumber }}</span>
					</div>
				</div>
				<div class="opencase-case-detail__actions">
					<FavoriteButton entity="case" :entity-key="caseData.id" />
					<NcActions ref="headerActions">
						<NcActionButton v-if="canWrite" @click="showEditDialog = true">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('opencase', 'Rediger') }}
						</NcActionButton>
						<NcActionButton @click="showCopyCaseDialog = true">
							<template #icon>
								<ContentCopyIcon :size="20" />
							</template>
							{{ t('opencase', 'Kopier sag') }}
						</NcActionButton>
						<NcActionButton v-if="canWrite" @click="showSubCaseDialog = true">
							<template #icon>
								<SubdirectoryArrowRightIcon :size="20" />
							</template>
							{{ t('opencase', 'Opret undersag') }}
						</NcActionButton>
						<NcActionButton v-if="canWrite" @click="showReminderDialog = true">
							<template #icon>
								<BellPlusOutlineIcon :size="20" />
							</template>
							{{ t('opencase', 'Ny påmindelse') }}
						</NcActionButton>
						<NcActionButton v-if="canWrite && caseData.status_id === 1"
							@click="changeStatus(2)">
							{{ t('opencase', 'Luk sag') }}
						</NcActionButton>
						<NcActionButton v-if="canWrite && caseData.status_id === 2"
							@click="changeStatus(1)">
							{{ t('opencase', 'Genåbn sag') }}
						</NcActionButton>
						<NcActionButton v-if="canWrite && caseData.status_id === 2"
							@click="changeStatus(3)">
							{{ t('opencase', 'Arkiver') }}
						</NcActionButton>
						<template v-if="canWrite">
							<NcActionCaption :name="t('opencase', 'Aktindsigt')" />
							<NcActionButton @click="showAccessRequestDialog = true">
								<template #icon>
									<FileEyeOutlineIcon :size="20" />
								</template>
								{{ t('opencase', 'Ny aktindsigtsanmodning') }}
							</NcActionButton>
							<NcActionButton v-if="accessRequestsCount" @click="openAccessList">
								<template #icon>
									<FileEyeOutlineIcon :size="20" />
								</template>
								{{ t('opencase', 'Se aktindsigter') }} <span>({{ accessRequestsCount }})</span>
							</NcActionButton>
						</template>
						<template v-if="favoriteAiPrompts.length > 0 && !caseData.status_is_closed">
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

			<!-- Tab navigation -->
			<OverflowTabs :tabs="caseTabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Documents tab -->
			<div v-if="activeTab === 'documents'" class="opencase-case-detail__content">
				<div class="opencase-case-detail__content-header">
					<NcButton v-if="canWrite && !caseData.status_is_closed"
						type="primary" @click="showNewDocDialog = true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('opencase', 'Nyt dokument') }}
					</NcButton>
				</div>

				<NcLoadingIcon v-if="documentsLoading" :size="32" />
				<NcEmptyContent v-else-if="documents.length === 0"
					:title="t('opencase', 'Ingen dokumenter')">
					<template #icon>
						<FileDocumentIcon :size="48" />
					</template>
				</NcEmptyContent>

				<DocumentList v-else
					:documents="documents"
					@open="openDocument"
					@delete="deleteDocument" />
			</div>

			<!-- Files tab -->
			<div v-if="activeTab === 'files'" class="opencase-case-detail__content">
				<div v-if="canWrite" class="opencase-case-detail__content-header">
					<FileUploadButton :case-id="caseData.id"
						:documents="documents"
						@uploaded="onFileUploaded" />
				</div>

				<NcLoadingIcon v-if="filesLoading" :size="32" />
				<NcEmptyContent v-else-if="files.length === 0"
					:title="t('opencase', 'Ingen filer')">
					<template #icon>
						<FileIcon :size="48" />
					</template>
				</NcEmptyContent>

				<FileList v-else
					:files="files"
					:show-document-number="true"
					:is-read-only="!canWrite"
					@delete="deleteFile" />
			</div>

			<!-- Info tab -->
			<div v-if="activeTab === 'info'" class="opencase-case-detail__content">
				<CaseInfoPanel :case-data="caseData" />
				<ReminderList
					:reminders="reminders"
					:loading="remindersLoading"
					@updated="onReminderUpdated" />
			</div>

			<!-- Participants tab -->
			<div v-if="activeTab === 'participants'" class="opencase-case-detail__content">
				<CaseParticipantList :case-id="caseData.id" :can-write="canWrite" @count-changed="participantsCount = $event" />
			</div>

			<!-- Journal notes tab -->
			<div v-if="activeTab === 'journalnotes'" class="opencase-case-detail__content">
				<JournalNoteList
					:notes="journalNotes"
					:loading="journalNotesLoading"
					:case-title="caseData && caseData.title"
					:can-write="canWrite"
					@new="showJournalNoteDialog = true"
					@edit="openEditJournalNote"
					@delete="deleteJournalNote"
					@lock="lockJournalNote"
					@view="openViewJournalNote" />

				<JournalNoteDialog v-if="showJournalNoteDialog"
					:case-id="caseData.id"
					:note="editingJournalNote"
					@close="closeJournalNoteDialog"
					@saved="onJournalNoteSaved" />

				<JournalNoteViewDialog v-if="viewingJournalNote"
					:note="viewingJournalNote"
					@close="viewingJournalNote = null" />
			</div>

			<!-- Caseworkers tab -->
			<div v-if="activeTab === 'caseworkers'" class="opencase-case-detail__content">
				<CaseCaseworkerList ref="caseworkerList" :case-id="caseData.id" :can-write="canWrite" @count-changed="caseworkersCount = $event" />
			</div>

			<!-- Log tab -->
			<div v-if="activeTab === 'log'" class="opencase-case-detail__content">
				<CaseAuditLog :case-id="caseData.id" />
			</div>

			<!-- Adgang tab -->
			<div v-if="activeTab === 'access'" class="opencase-case-detail__content">
				<CaseAccessPanel ref="accessPanel" :case-id="caseData.id" :can-manage="canWrite && !caseData.status_is_closed" :case-data="caseData" />
			</div>

			<!-- Sagshierarki tab -->
			<div v-if="activeTab === 'hierarchy'" class="opencase-case-detail__content">
				<CaseHierarchyTab :case-id="caseData.id" />
			</div>

			<!-- Aktindsigt tab -->
			<div v-if="activeTab === 'access_requests'" class="opencase-case-detail__content">
				<AccessRequestListView :case-id="caseData.id" />
			</div>

			<!-- New document dialog -->
			<NewDocumentDialog v-if="showNewDocDialog"
				:case-id="caseData.id"
				@close="showNewDocDialog = false"
				@created="onDocumentCreated" />

			<!-- Edit case dialog -->
			<EditCaseDialog v-if="showEditDialog"
				:case-data="caseData"
				@close="showEditDialog = false"
				@saved="onCaseSaved" />

			<!-- Copy case dialog -->
			<CopyCaseDialog v-if="showCopyCaseDialog"
				:case-data="caseData"
				@close="showCopyCaseDialog = false"
				@copied="onCaseCopied" />

			<!-- Create sub case dialog -->
			<CreateSubCaseDialog v-if="showSubCaseDialog"
				:case-data="caseData"
				@close="showSubCaseDialog = false"
				@created="onSubCaseCreated" />

			<!-- New access request dialog -->
			<NewAccessRequestDialog v-if="showAccessRequestDialog"
				:case-id="caseData.id"
				@close="showAccessRequestDialog = false"
				@created="onAccessRequestCreated" />
			<!-- Reminder dialog -->
			<ReminderDialog v-if="showReminderDialog"
				entity="case"
				:entity-key="caseData.id"
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
import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import SubdirectoryArrowRightIcon from 'vue-material-design-icons/SubdirectoryArrowRight.vue'
import BellPlusOutlineIcon from 'vue-material-design-icons/BellPlusOutline.vue'
import BellAlertIcon from 'vue-material-design-icons/BellAlert.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import AccountMultipleOutlineIcon from 'vue-material-design-icons/AccountMultipleOutline.vue'
import NoteTextOutlineIcon from 'vue-material-design-icons/NoteTextOutline.vue'
import AccountCogOutlineIcon from 'vue-material-design-icons/AccountCogOutline.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'
import SitemapOutlineIcon from 'vue-material-design-icons/SitemapOutline.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import FileEyeOutlineIcon from 'vue-material-design-icons/FileEyeOutline.vue'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import DocumentList from '../components/DocumentList.vue'
import FileList from '../components/FileList.vue'
import FileUploadButton from '../components/FileUploadButton.vue'
import CaseInfoPanel from '../components/CaseInfoPanel.vue'
import CaseAuditLog from '../components/CaseAuditLog.vue'
import NewDocumentDialog from '../components/NewDocumentDialog.vue'
import EditCaseDialog from '../components/EditCaseDialog.vue'
import CopyCaseDialog from '../components/CopyCaseDialog.vue'
import CreateSubCaseDialog from '../components/CreateSubCaseDialog.vue'
import FavoriteButton from '../components/FavoriteButton.vue'
import CaseAccessPanel from '../components/CaseAccessPanel.vue'
import CaseParticipantList from '../components/CaseParticipantList.vue'
import CaseCaseworkerList from '../components/CaseCaseworkerList.vue'
import JournalNoteList from '../components/JournalNoteList.vue'
import JournalNoteDialog from '../components/JournalNoteDialog.vue'
import JournalNoteViewDialog from '../components/JournalNoteViewDialog.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import CaseHierarchyTab from '../components/CaseHierarchyTab.vue'
import NewAccessRequestDialog from '../components/NewAccessRequestDialog.vue'
import AccessRequestListView from './AccessRequestListView.vue'
import ReminderDialog from '../components/ReminderDialog.vue'
import ReminderList from '../components/ReminderList.vue'

import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import CprDisplay from '../components/CprDisplay.vue'
import eventBus from '../utils/eventBus.js'

export default {
	name: 'CaseDetailView',

	components: {
		NcBreadcrumbs,
		NcBreadcrumb,
		NcButton,
		NcActions,
		NcActionButton,
		NcActionCaption,
		NcEmptyContent,
		NcLoadingIcon,
		PlusIcon,
		PencilIcon,
		FileDocumentIcon,
		FileIcon,
		AutoFixIcon,
		CaseStatusBadge,
		DocumentList,
		FileList,
		FileUploadButton,
		CaseInfoPanel,
		CaseAuditLog,
		NewDocumentDialog,
		EditCaseDialog,
		CopyCaseDialog,
		ContentCopyIcon,
		SubdirectoryArrowRightIcon,
		BellPlusOutlineIcon,
		BellAlertIcon,
		CreateSubCaseDialog,
		ReminderDialog,
		ReminderList,
		FavoriteButton,
		CaseAccessPanel,
		CaseParticipantList,
		CaseCaseworkerList,
		JournalNoteList,
		JournalNoteDialog,
		JournalNoteViewDialog,
		OverflowTabs,
		CaseHierarchyTab,
		AccountIcon,
		OfficeBuildingIcon,
		CprDisplay,
		FileEyeOutlineIcon,
		NewAccessRequestDialog,
		AccessRequestListView,
	},

	props: {
		id: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		const hashTab = window.location.hash.replace('#tab-', '')
		const validCaseTabs = ['info', 'documents', 'files', 'participants', 'journalnotes', 'caseworkers', 'log', 'access', 'hierarchy', 'access_requests']
		return {
			loading: true,
			activeTab: validCaseTabs.includes(hashTab) ? hashTab : 'info',
			showNewDocDialog: false,
			showEditDialog: false,
			showCopyCaseDialog: false,
			showSubCaseDialog: false,
			showAccessRequestDialog: false,
			accessRequestsCount: null,
			hasHierarchy: false,
			showReminderDialog: false,
			journalNotes: [],
			journalNotesLoading: false,
			reminders: [],
			remindersLoading: false,
			participants: [],
			participantsCount: null,
			caseworkersCount: null,
			showJournalNoteDialog: false,
			editingJournalNote: null,
			viewingJournalNote: null,
			favoriteAiPrompts: [],
			aiEnabled: loadState('opencase', 'ai_enabled', false),
			revealPrimaryAddress: false,
		}
	},

	computed: {
		caseData() { return this.$store.state.currentCase },
		documents() { return this.$store.state.documents },
		documentsLoading() { return this.$store.state.documentsLoading },
		files() { return this.$store.state.files },
		filesLoading() { return this.$store.state.filesLoading },
		canWrite() { return this.caseData?.can_write ?? false },
		nextReminder() {
			const active = this.reminders.filter(r => r.status === 'active' && r.deadline)
			if (active.length === 0) return null
			return active.reduce((a, b) => new Date(a.deadline) < new Date(b.deadline) ? a : b)
		},
		primaryParticipant() {
			return this.participants.find(p => p.participantrole_id === 0) || null
		},
		caseTabs() {
			const tabs = [
				{ id: 'info', label: t('opencase', 'Info'), icon: InformationOutlineIcon },
				{ id: 'documents', label: t('opencase', 'Dokumenter'), count: this.documents.length || null, icon: FileDocumentOutlineIcon },
				{ id: 'files', label: t('opencase', 'Filer'), count: this.files.length || null, icon: FolderOutlineIcon },
				{ id: 'participants', label: t('opencase', 'Parter'), count: this.participantsCount, icon: AccountMultipleOutlineIcon },
				{ id: 'journalnotes', label: t('opencase', 'Journalnotater'), count: this.journalNotes.length || null, icon: NoteTextOutlineIcon },
				{ id: 'caseworkers', label: t('opencase', 'Andre sagsbehandlere'), count: this.caseworkersCount, icon: AccountCogOutlineIcon },
				{ id: 'log', label: t('opencase', 'Log'), icon: HistoryIcon },
				{ id: 'access', label: t('opencase', 'Adgang'), icon: LockOutlineIcon },
			]
			if (this.hasHierarchy) {
				tabs.push({ id: 'hierarchy', label: t('opencase', 'Sagshierarki'), icon: SitemapOutlineIcon })
			}
			if (this.accessRequestsCount) {
				tabs.push({ id: 'access_requests', label: t('opencase', 'Aktindsigt'), count: this.accessRequestsCount, icon: FileEyeOutlineIcon })
			}
			return tabs
		},
	},

	mounted() {
		this._onAiJournalNotesChanged = () => { this.loadJournalNotes() }
		this._onAiCaseworkersChanged = () => { this.$refs.caseworkerList?.load() }
		this._onAiGrantsChanged = () => { this.$refs.accessPanel?.loadGrants() }
		eventBus.on('ai:journal-notes-changed', this._onAiJournalNotesChanged)
		eventBus.on('ai:caseworkers-changed', this._onAiCaseworkersChanged)
		eventBus.on('ai:grants-changed', this._onAiGrantsChanged)
	},

	beforeUnmount() {
		eventBus.off('ai:journal-notes-changed', this._onAiJournalNotesChanged)
		eventBus.off('ai:caseworkers-changed', this._onAiCaseworkersChanged)
		eventBus.off('ai:grants-changed', this._onAiGrantsChanged)
	},

	watch: {
		id: {
			immediate: true,
			handler() { this.loadCase() },
		},
		activeTab(tab) {
			history.replaceState(null, '', '#tab-' + tab)
			if (tab === 'journalnotes' && this.journalNotes.length === 0) {
				this.loadJournalNotes()
			}
		},
	},

	methods: {
		isCompany(participant) {
			const id = (participant.cpr_cvr || '').replace(/\D/g, '')
			return id.length === 8
		},

		openPrimaryPart(participant) {
			if (participant.contact_type === 1) {
				this.$router.push({ name: 'search-employees', query: { username: participant.user_id } })
			} else if (this.isCompany(participant)) {
				this.$router.push({ name: 'search-company', query: { cvr: participant.cpr_cvr } })
			} else {
				this.$router.push({ name: 'search-citizen', query: { cpr: participant.cpr_cvr } })
			}
		},

		formatParticipantAddress(p) {
			const street = [p.streetname, p.housenumber, p.floor, p.door].filter(Boolean).join(' ')
			const city = [p.zipcode, p.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ')
		},

		async loadCase() {
			this.loading = true
			try {
				await this.$store.dispatch('fetchCase', this.id)
				await Promise.all([
					this.$store.dispatch('fetchDocuments', this.id),
					this.$store.dispatch('fetchFiles', this.id),
					api.getCaseParticipants(this.id).then(r => { this.participants = r; this.participantsCount = r.length }).catch(() => {}),
					api.getJournalNotes(this.id).then(r => { this.journalNotes = r }).catch(() => {}),
					api.getCaseworkers(this.id).then(r => { this.caseworkersCount = r.filter(c => c.role !== 'responsible').length }).catch(() => {}),
					api.getAccessRequests(this.id).then(r => { this.accessRequestsCount = r.access_requests?.length ?? null }).catch(() => {}),
					api.getCaseReminders(this.id).then(r => { this.reminders = r }).catch(() => {}),
					api.getCaseHierarchy(this.id).then(tree => { this.hasHierarchy = !!tree }).catch(() => {}),
				])
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse sagen'))
			} finally {
				this.loading = false
			}
			if (this.aiEnabled) {
				api.getFavoriteAiPrompts('case').then(p => { this.favoriteAiPrompts = p }).catch(() => {})
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

		async changeStatus(statusId) {
			try {
				await this.$store.dispatch('changeCaseStatus', { id: this.id, statusId })
				showSuccess(t('opencase', 'Status ændret'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke ændre status'))
			}
		},

		openDocument(docId) {
			this.$router.push({ name: 'document-detail', params: { id: docId } })
		},

		async deleteDocument(docId) {
			try {
				await this.$store.dispatch('deleteDocument', docId)
				showSuccess(t('opencase', 'Dokument slettet'))
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke slette dokument'))
			}
		},

		async deleteFile(fileId) {
			try {
				await this.$store.dispatch('deleteFile', fileId)
				showSuccess(t('opencase', 'Fil slettet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke slette fil'))
			}
		},

		onDocumentCreated(doc) {
			this.showNewDocDialog = false
			this.$router.push({ name: 'document-detail', params: { id: String(doc.id) } })
		},

		onFileUploaded() {
			this.$store.dispatch('fetchFiles', this.id)
		},

		onCaseSaved() {
			this.showEditDialog = false
		},

		onCaseCopied(newCase) {
			this.showCopyCaseDialog = false
			this.$router.push({ name: 'case-detail', params: { id: newCase.id } })
		},

		onSubCaseCreated(newCase) {
			this.showSubCaseDialog = false
			this.$router.push({ name: 'case-detail', params: { id: newCase.id } })
		},

		async loadJournalNotes() {
			this.journalNotesLoading = true
			try {
				this.journalNotes = await api.getJournalNotes(this.id)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse journalnotater'))
			} finally {
				this.journalNotesLoading = false
			}
		},

		openEditJournalNote(note) {
			this.editingJournalNote = note
			this.showJournalNoteDialog = true
		},

		openViewJournalNote(note) {
			this.viewingJournalNote = note
		},

		closeJournalNoteDialog() {
			this.showJournalNoteDialog = false
			this.editingJournalNote = null
		},

		onJournalNoteSaved(saved) {
			const idx = this.journalNotes.findIndex(n => n.id === saved.id)
			if (idx >= 0) {
				this.journalNotes[idx] = saved
			} else {
				this.journalNotes.unshift(saved)
			}
			this.closeJournalNoteDialog()
		},

		async deleteJournalNote(note) {
			if (!confirm(t('opencase', 'Slet journalnotat "{title}"?', { title: note.title }))) {
				return
			}
			try {
				await api.deleteJournalNote(note.id)
				this.journalNotes = this.journalNotes.filter(n => n.id !== note.id)
				showSuccess(t('opencase', 'Journalnotat slettet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke slette journalnotat'))
			}
		},

		async lockJournalNote(note) {
			if (!confirm(t('opencase', 'Lås journalnotat "{title}"? Det kan ikke fortrydes.', { title: note.title }))) {
				return
			}
			try {
				const locked = await api.lockJournalNote(note.id)
				const idx = this.journalNotes.findIndex(n => n.id === locked.id)
				if (idx >= 0) {
					this.journalNotes[idx] = locked
				}
				showSuccess(t('opencase', 'Journalnotat låst'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke låse journalnotat'))
			}
		},

		openAccessList() {
			this.activeTab = 'access_requests'
		},

		onAccessRequestCreated(req) {
			this.showAccessRequestDialog = false
			this.accessRequestsCount = (this.accessRequestsCount ?? 0) + 1
			this.$router.push({ name: 'access-request-detail', params: { id: req.id } })
		},
		onReminderCreated(reminder) {
			this.reminders.unshift(reminder)
			this.showReminderDialog = false
		},

		onReminderUpdated(updated) {
			const idx = this.reminders.findIndex(r => r.id === updated.id)
			if (idx >= 0) {
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
				day: 'numeric', month: 'short', year: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-case-detail {
	padding: 20px;
	max-width: 1200px;
}

.opencase-case-detail__loading {
	margin: 60px auto;
}

.opencase-case-detail__breadcrumbs {
	margin-bottom: 8px;
}

.opencase-case-detail__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	margin-bottom: 20px;
}

.opencase-case-detail__header h2 {
	margin: 0 0 6px 0;
	font-size: 1.4em;
	font-weight: 700;
}

.opencase-case-detail__meta {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.opencase-case-detail__case-number {
	font-family: var(--font-monospace, monospace);
	font-weight: 600;
}


.opencase-case-detail__reminder-banner {
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

.opencase-case-detail__reminder-banner:hover {
	opacity: 0.85;
}

.opencase-case-detail__reminder-banner--overdue {
	background: var(--color-error-hover, #fce4ec);
	border-color: var(--color-error, #c62828);
	color: var(--color-error, #c62828);
	font-weight: 600;
}

.opencase-case-detail__reminder-deadline {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.opencase-case-detail__reminder-banner--overdue .opencase-case-detail__reminder-deadline {
	color: inherit;
}

.opencase-case-detail__primary-part {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
	font-size: 1.2em;
	color: var(--color-text-maxcontrast);
}

.opencase-case-detail__primary-part-link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	padding: 0;
	cursor: pointer;
	color: var(--color-primary-element);
	font-size: inherit;
}

.opencase-case-detail__primary-part-link:hover .opencase-case-detail__primary-part-id {
	text-decoration: underline;
}

.opencase-case-detail__primary-part-icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.opencase-case-detail__primary-part-id {
	font-family: var(--font-monospace, monospace);
	font-weight: 600;
	color: var(--color-main-text);
}

.opencase-case-detail__primary-part-name {
	color: var(--color-main-text);
}

.opencase-case-detail__primary-part-address::before {
	content: '·';
	margin-right: 8px;
	color: var(--color-text-lighter);
}

.opencase-case-detail__primary-part-pnumber::before {
	content: '·';
	margin-right: 8px;
	color: var(--color-text-lighter);
}

.opencase-case-detail__primary-part-pnumber {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.opencase-case-detail__actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.opencase-case-detail__content-header {
	margin-bottom: 16px;
}

.opencase-case-detail__protected-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	background: #fce4ec;
	color: #c62828;
	border: none;
	cursor: pointer;
}

.opencase-case-detail__protected-badge:hover {
	opacity: 0.85;
}
</style>
