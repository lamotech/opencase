<template>
	<div class="ai-sidepanel-root">
	<NcAppSidebar :name="t('opencase', 'AI-assistent')"
		:open="open"
		@close="$emit('close')">
		<template #description>
			{{ t('opencase', 'Få hjælp til at udføre handlinger nemt og hurtigt') }}
		</template>

		<div class="ai-panel">
			<!-- Toolbar -->
			<div class="ai-panel__toolbar">
				<NcButton :title="t('opencase', 'Promptbibliotek')" @click="openPromptDialog">
					<template #icon>
						<BookOpenIcon :size="18" />
					</template>
					{{ t('opencase', 'Prompts') }}
				</NcButton>
				<NcButton v-if="conversation.length > 0"
					:title="t('opencase', 'Ryd samtale')"
					@click="clearHistory">
					<template #icon>
						<DeleteIcon :size="18" />
					</template>
					{{ t('opencase', 'Ryd') }}
				</NcButton>
			</div>

			<!-- Message history -->
			<div class="ai-panel__messages" ref="messages">
				<div v-if="conversation.length === 0" class="ai-panel__empty">
					<AutoFixIcon :size="48" class="ai-panel__empty-icon" />
					<p class="ai-panel__empty-title">
						{{ t('opencase', 'Hvad kan jeg hjælpe dig med?') }}
					</p>
					<p class="ai-panel__empty-hint">
						{{ t('opencase', 'Prøv f.eks.:') }}
					</p>
					<ul class="ai-panel__examples">
						<li @click="useExample(t('opencase', 'Find sag med sagsnummer 2026-00001'))">
							{{ t('opencase', 'Find sag med sagsnummer 2026-00001') }}
						</li>
						<li v-if="currentCaseId" @click="useExample(t('opencase', 'Opret et dokument med titlen \'Notat\' og kategori \'Notat\''))">
							{{ t('opencase', 'Opret et dokument på denne sag') }}
						</li>
					</ul>
				</div>

				<template v-for="(msg, i) in conversation" :key="i">
					<div :class="['ai-panel__message', `ai-panel__message--${msg.role}`]">
						<span class="ai-panel__message-text" v-html="formatMessage(msg.text)" />
					</div>

					<div v-for="(result, ri) in (msg.actionResults ?? [])"
						:key="ri"
						class="ai-panel__action-result">
						<CheckCircleIcon v-if="result.ok" :size="16" class="ai-panel__action-result-icon--ok" />
						<AlertCircleIcon v-else :size="16" class="ai-panel__action-result-icon--err" />
						<span>{{ result.label }}</span>
					</div>
				</template>

				<div v-if="loading" class="ai-panel__message ai-panel__message--assistant ai-panel__message--loading">
					<span class="ai-panel__dots"><span /><span /><span /></span>
				</div>
			</div>

			<!-- Error banner -->
			<div v-if="errorMessage" class="ai-panel__error">
				<AlertCircleIcon :size="16" />
				{{ errorMessage }}
			</div>

			<!-- Input area -->
			<div class="ai-panel__input-area">
				<textarea ref="promptInput"
					v-model="prompt"
					class="ai-panel__textarea"
					rows="1"
					:placeholder="t('opencase', 'Skriv din besked… (Enter sender, Shift+Enter ny linje)')"
					:disabled="loading"
					@input="autoResize"
					@keydown.enter.exact.prevent="send" />
				<NcButton v-if="speechSupported"
					:class="['ai-panel__mic-btn', { 'ai-panel__mic-btn--active': recording }]"
					:title="recording
						? t('opencase', 'Stop optagelse (Alt+M)')
						: t('opencase', 'Tal din besked (Alt+M)')"
					@click="toggleRecording">
					<template #icon>
						<MicrophoneIcon v-if="!recording" :size="18" />
						<MicrophoneOffIcon v-else :size="18" />
					</template>
				</NcButton>
				<NcButton type="primary"
					:disabled="!prompt.trim() || loading"
					@click="send">
					<template #icon>
						<SendIcon :size="18" />
					</template>
				</NcButton>
			</div>
		</div>

	</NcAppSidebar>

	<AiPromptDialog v-if="showPromptDialog"
		:current-scope="promptScope"
		:initial-prompt-id="pendingPromptId"
		@execute="onPromptExecute"
		@execute-actions="onPromptExecuteActions"
		@close="closePromptDialog" />
	</div>
</template>

<script>
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue'
import SendIcon from 'vue-material-design-icons/Send.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import MicrophoneIcon from 'vue-material-design-icons/Microphone.vue'
import MicrophoneOffIcon from 'vue-material-design-icons/MicrophoneOff.vue'
import BookOpenIcon from 'vue-material-design-icons/BookOpen.vue'

import { generateUrl } from '@nextcloud/router'

import api from '../services/api.js'
import AiPromptDialog from './AiPromptDialog.vue'

export default {
	name: 'AiSidePanel',

	components: {
		NcAppSidebar,
		NcButton,
		AutoFixIcon,
		SendIcon,
		CheckCircleIcon,
		AlertCircleIcon,
		DeleteIcon,
		MicrophoneIcon,
		MicrophoneOffIcon,
		BookOpenIcon,
		AiPromptDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	data() {
		return {
			prompt: '',
			conversation: [],
			loading: false,
			errorMessage: null,
			recording: false,
			speechSupported: typeof window !== 'undefined'
				&& !!(window.SpeechRecognition || window.webkitSpeechRecognition),
			_recognition: null,
			showPromptDialog: false,
			pendingPromptId: null,
		}
	},

	mounted() {
		try {
			const saved = localStorage.getItem('opencase_ai_conversation')
			if (saved) {
				this.conversation = JSON.parse(saved)
			}
		} catch {
			// ignore parse errors
		}
		this._hotkeyHandler = (e) => {
			if (e.altKey && e.key === 'm' && this.open && this.speechSupported) {
				e.preventDefault()
				this.toggleRecording()
			}
		}
		window.addEventListener('keydown', this._hotkeyHandler)

		this._onOpenPrompt = (promptId) => {
			this.pendingPromptId = promptId ?? null
			this.openPromptDialog()
		}
		subscribe('ai:open-prompt', this._onOpenPrompt)
	},

	beforeUnmount() {
		this.stopRecording()
		window.removeEventListener('keydown', this._hotkeyHandler)
		unsubscribe('ai:open-prompt', this._onOpenPrompt)
	},

	watch: {
		conversation: {
			deep: true,
			handler(val) {
				try {
					localStorage.setItem('opencase_ai_conversation', JSON.stringify(val))
				} catch {
					// ignore storage errors (e.g. quota exceeded)
				}
			},
		},
	},

	computed: {
		currentCaseId() {
			return this.$route.name === 'case-detail' ? this.$route.params.id : null
		},

		currentCaseNumber() {
			return this.$store.state.currentCase?.case_number ?? null
		},

		currentCaseTitle() {
			return this.$store.state.currentCase?.title ?? null
		},

		currentDocumentId() {
			return this.$route.name === 'document-detail' ? this.$route.params.id : null
		},

		currentDocumentTitle() {
			return this.$store.state.currentDocument?.title ?? null
		},

		currentDocumentIsInbox() {
			if (this.$route.name !== 'document-detail') return false
			return this.$store.state.currentCase?.is_inbox === true
		},

		promptScope() {
			if (this.currentDocumentIsInbox) return 'inbox_document'
			if (this.$route.name === 'document-detail') return 'document'
			if (this.$route.name === 'case-detail') return 'case'
			return 'all'
		},

		routeContext() {
			return {
				view:               this.$route.name ?? 'unknown',
				case_id:            this.currentCaseId,
				case_number:        this.currentCaseNumber,
				case_title:         this.currentCaseTitle,
				document_id:        this.currentDocumentId,
				document_title:     this.currentDocumentTitle,
				document_is_inbox:  this.currentDocumentIsInbox,
			}
		},

		// History as JSON-encoded message objects expected by OpenAiAPIService
		historyForApi() {
			return this.conversation.map(m => JSON.stringify({
				role:    m.role === 'user' ? 'user' : 'assistant',
				content: m.text,
			}))
		},
	},

	methods: {
		clearHistory() {
			this.conversation = []
			this.errorMessage = null
		},

		openPromptDialog() {
			if (this._promptDialogBlocked) return
			this.showPromptDialog = true
		},

		closePromptDialog() {
			this.showPromptDialog = false
			this.pendingPromptId = null
			// Block reopening for one event-loop tick so that any click event
			// that caused this close (focus-return click synthesis, backdrop
			// click propagation, etc.) cannot immediately reopen the dialog.
			this._promptDialogBlocked = true
			setTimeout(() => { this._promptDialogBlocked = false }, 0)
		},

		onPromptExecute(promptText) {
			this.closePromptDialog()
			this.prompt = promptText
			this.$nextTick(() => {
				this.autoResize()
				this.send()
			})
		},

		// Replays a cached action sequence (generated when the prompt was saved)
		// directly, without calling the AI model, to avoid spending tokens on
		// every execution of a saved prompt.
		async onPromptExecuteActions(actions, label) {
			this.closePromptDialog()
			if (this.loading) return

			this.errorMessage = null
			this.conversation.push({ role: 'user', text: label })
			const msgEntry = { role: 'assistant', text: '', actionResults: [] }
			this.conversation.push(msgEntry)
			this.loading = true
			this.$nextTick(() => this.scrollToBottom())

			const contextSnapshot = { ...this.routeContext }
			const runtimeCtx = {}

			try {
				for (const action of actions) {
					const result = await this.executeAction(action, contextSnapshot, runtimeCtx)
					msgEntry.actionResults.push(result)
				}
			} catch (err) {
				this.errorMessage = err?.response?.data?.ocs?.data?.error
					|| err?.response?.data?.error
					|| err.message
					|| t('opencase', 'Noget gik galt. Prøv igen.')
			} finally {
				this.loading = false
				this.$nextTick(() => this.scrollToBottom())
			}
		},

		toggleRecording() {
			if (this.recording) {
				this.stopRecording()
			} else {
				this.startRecording()
			}
		},

		startRecording() {
			const SR = window.SpeechRecognition || window.webkitSpeechRecognition
			if (!SR) return

			const recognition = new SR()
			recognition.lang = 'da-DK'
			recognition.continuous = true
			recognition.interimResults = true

			let committed = this.prompt

			recognition.onresult = (event) => {
				let interim = ''
				for (let i = event.resultIndex; i < event.results.length; i++) {
					const transcript = event.results[i][0].transcript
					if (event.results[i].isFinal) {
						committed += (committed && !committed.endsWith(' ') ? ' ' : '') + transcript
					} else {
						interim = transcript
					}
				}
				this.prompt = committed + (interim ? ' ' + interim : '')
				this.$nextTick(() => this.autoResize())
			}

			recognition.onerror = () => {
				this.stopRecording()
			}

			recognition.onend = () => {
				// Restart automatically while still in recording state
				// (browser cuts stream after ~60 s of silence)
				if (this.recording) {
					recognition.start()
				}
			}

			recognition.start()
			this._recognition = recognition
			this.recording = true
		},

		stopRecording() {
			if (this._recognition) {
				this._recognition.onend = null
				this._recognition.stop()
				this._recognition = null
			}
			this.recording = false
		},

		useExample(text) {
			this.prompt = text
			this.$nextTick(() => this.autoResize())
		},

		autoResize() {
			const el = this.$refs.promptInput
			if (!el) return
			el.style.height = 'auto'
			el.style.height = el.scrollHeight + 'px'
		},

		resetTextarea() {
			const el = this.$refs.promptInput
			if (!el) return
			el.style.height = 'auto'
		},

		async send() {
			const text = this.prompt.trim()
			if (!text || this.loading) return

			this.errorMessage = null
			this.stopRecording()
			this.conversation.push({ role: 'user', text })
			this.prompt = ''
			this.resetTextarea()
			this.loading = true
			this.$nextTick(() => this.scrollToBottom())

			// Snapshot history and context at send time so action handlers use
			// the same document/case scope that was visible when the user sent the message.
			const historySnapshot  = this.historyForApi.slice(0, -1)
			const contextSnapshot  = { ...this.routeContext }

			try {
				const { reply, actions } = await api.aiChat(text, historySnapshot, contextSnapshot)

				const displayText = this.stripActionTags(reply)
				const msgEntry    = { role: 'assistant', text: displayText, actionResults: [] }

				this.conversation.push(msgEntry)

				// Mutable object shared across the action chain so later actions can use
				// IDs produced by earlier ones (e.g. newCaseId from create_case_and_move_document
				// consumed by a subsequent create_document).
				const runtimeCtx = {}

				for (const action of (actions ?? [])) {
					const result = await this.executeAction(action, contextSnapshot, runtimeCtx)
					msgEntry.actionResults.push(result)
				}
			} catch (err) {
				this.conversation.pop()
				this.errorMessage = err?.response?.data?.ocs?.data?.error
					|| err?.response?.data?.error
					|| err.message
					|| t('opencase', 'Noget gik galt. Prøv igen.')
			} finally {
				this.loading = false
				this.$nextTick(() => this.scrollToBottom())
			}
		},

		stripActionTags(text) {
			return text.replace(/<action>.*?<\/action>/gs, '').trim()
		},

		formatMessage(text) {
			const escaped = text
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
			return escaped.replace(/\n/g, '<br>')
		},

		async executeAction(action, ctx, runtimeCtx = {}) {
			try {
				switch (action.type) {
				case 'navigate_to_case':
					return await this.actionNavigateToCase(action)
				case 'create_case':
					return await this.actionCreateCase(action, ctx, runtimeCtx)
				case 'create_document':
					return await this.actionCreateDocument(action, ctx, runtimeCtx)
				case 'add_file_from_template':
					return await this.actionAddFileFromTemplate(action, ctx, runtimeCtx)
				case 'add_citizen_contact':
					return await this.actionAddCitizenContact(action, ctx, runtimeCtx)
				case 'add_company_contact':
					return await this.actionAddCompanyContact(action, ctx, runtimeCtx)
				case 'add_citizen_participant':
					return await this.actionAddCitizenParticipant(action, ctx, runtimeCtx)
				case 'add_company_participant':
					return await this.actionAddCompanyParticipant(action, ctx, runtimeCtx)
				case 'send_digital_post':
					return await this.actionSendDigitalPost(action, ctx, runtimeCtx)
				case 'move_to_case':
					return await this.actionMoveToCase(action, ctx, runtimeCtx)
				case 'create_case_and_move_document':
					return await this.actionCreateCaseAndMoveDocument(action, ctx, runtimeCtx)
				case 'add_sender_as_receiver':
					return await this.actionAddSenderAsReceiver(action, ctx, runtimeCtx)
				case 'create_journalnote':
					return await this.actionCreateJournalNote(action, ctx, runtimeCtx)
				case 'add_other_caseworker':
					return await this.actionAddOtherCaseworker(action, ctx, runtimeCtx)
				case 'grant_access':
					return await this.actionGrantAccess(action, ctx, runtimeCtx)
				case 'create_sub_case':
					return await this.actionCreateSubCase(action, ctx, runtimeCtx)
				case 'copy_case':
					return await this.actionCopyCase(action, ctx, runtimeCtx)
				case 'close_case':
					return await this.actionCloseCase(action, ctx, runtimeCtx)
				case 'add_to_favorites':
					return await this.actionAddToFavorites(action, ctx, runtimeCtx)
				case 'create_note':
					return await this.actionCreateNote(action, ctx, runtimeCtx)
				case 'share_document':
					return await this.actionShareDocument(action, ctx, runtimeCtx)
				case 'add_document_to_favorites':
					return await this.actionAddDocumentToFavorites(action, ctx, runtimeCtx)
				default:
					return { ok: false, label: t('opencase', 'Ukendt handling: ') + action.type }
				}
			} catch (err) {
				return { ok: false, label: err.message || t('opencase', 'Handlingen fejlede.') }
			}
		},

		async actionNavigateToCase(action) {
			const caseNumber = action.case_number
			if (!caseNumber) {
				return { ok: false, label: t('opencase', 'Manglende sagsnummer.') }
			}

			const result = await api.getCases({ search: caseNumber, limit: 5 })
			const found  = (result.cases ?? []).find(c => c.case_number === caseNumber)

			if (!found) {
				return { ok: false, label: t('opencase', 'Sag {n} ikke fundet.', { n: caseNumber }) }
			}

			this.$router.push({ name: 'case-detail', params: { id: String(found.id) } })
			this.$emit('close')
			return { ok: true, label: t('opencase', 'Navigerede til sag {n}', { n: caseNumber }) }
		},

		// Shared helper — resolves lookups and calls the API. Returns the created case object.
		// Throws on validation failure (string message) or API error.
		async _buildAndCreateCase(action) {
			const title = (action.title ?? '').trim()
			const kleCode = (action.kle_number ?? action.kle ?? '').trim()
			const facetCode = (action.action_facet ?? action.facet ?? '').trim()

			if (!title) throw new Error(t('opencase', 'Titel er påkrævet for at oprette en sag.'))
			if (!kleCode) throw new Error(t('opencase', 'KLE-nummer er påkrævet for at oprette en sag.'))
			if (!facetCode) throw new Error(t('opencase', 'Handlingsfacet er påkrævet for at oprette en sag.'))

			let orgName = (action.organisation ?? '').trim()
			if (!orgName) orgName = await api.getMyPrimaryOrg()
			if (!orgName) throw new Error(t('opencase', 'Ingen organisation fundet.'))

			const [sensitivities, insightLevels, facets] = await Promise.all([
				api.getSensitivities(),
				api.getInsightLevels(),
				api.getClassificationFacets(),
			])

			const wantedSens = (action.sensitivity ?? 'Ikke-fortrolig data').toLowerCase()
			const sensitivity = sensitivities.find(s => s.title?.toLowerCase() === wantedSens)
				?? sensitivities.find(s => s.title?.toLowerCase().includes(wantedSens))
				?? sensitivities.find(s => s.key?.toLowerCase().includes('ikke_fortrolig'))
				?? sensitivities[0]
			if (!sensitivity) throw new Error(t('opencase', 'Ingen følsomhedstyper fundet.'))

			const wantedInsight = (action.access_level ?? 'åben').toLowerCase()
			const insightLevel = insightLevels.find(l => l.name?.toLowerCase() === wantedInsight)
				?? insightLevels.find(l => l.name?.toLowerCase().includes(wantedInsight))
				?? insightLevels.find(l => l.name?.toLowerCase().includes('åben'))
				?? insightLevels[0]

			const facetCodeLower = facetCode.toLowerCase()
			const facet = facets.find(f => f.code?.toLowerCase() === facetCodeLower)
				?? facets.find(f => f.code?.toLowerCase().includes(facetCodeLower))
			if (!facet) throw new Error(t('opencase', 'Handlingsfacet \'{f}\' ikke fundet.', { f: facetCode }))

			return api.createCase({
				title,
				organisation:              orgName,
				classification_code:       kleCode,
				sensitivity:               sensitivity.key,
				classification_facet_uuid: facet.uuid,
				insight_level_id:          insightLevel?.id ?? null,
			})
		},

		async actionCreateCase(action, ctx, runtimeCtx = {}) {
			try {
				const created = await this._buildAndCreateCase(action)
				runtimeCtx.newCaseId = created.id
				this.$router.push({ name: 'case-detail', params: { id: String(created.id) } })
				return { ok: true, label: t('opencase', 'Sag \'{t}\' oprettet.', { t: created.title }) }
			} catch (err) {
				return { ok: false, label: err.message || t('opencase', 'Handlingen fejlede.') }
			}
		},

		async actionCreateDocument(action, ctx, runtimeCtx = {}) {
			// Prefer a case created earlier in this action chain over the frozen context snapshot
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag at oprette dokument på.') }
			}

			const categories = await api.getDocumentCategories()
			if (!categories.length) {
				return { ok: false, label: t('opencase', 'Ingen dokumentkategorier fundet.') }
			}

			const wanted = (action.category ?? '').toLowerCase()
			const category = categories.find(c => c.name?.toLowerCase() === wanted)
				?? categories.find(c => c.name?.toLowerCase().includes(wanted))
				?? categories[0]

			const doc = await api.createDocument(caseId, {
				title:                action.title || t('opencase', 'Nyt dokument'),
				document_category_id: category.id,
			})

			// Make the new document available to subsequent actions in this chain
			runtimeCtx.lastCreatedDocumentId = doc.id

			const warnings = []

			// Optionally create a file from a template inside the new document
			let createdFile = null
			if (action.template) {
				const templateName = action.template.toLowerCase()
				const templates = await api.getTemplates()
				const template = templates.find(tmpl => tmpl.name?.toLowerCase() === templateName)
					?? templates.find(tmpl => tmpl.name?.toLowerCase().includes(templateName))

				if (template) {
					createdFile = await api.createFileFromTemplate(doc.id, template.id)
					this.$root.$emit('ai:files-changed', doc.id)
				} else {
					warnings.push(t('opencase', 'Skabelonen \'{s}\' blev ikke fundet.', { s: action.template }))
				}
			}

			// Optionally open the created file for editing in Office
			if (action.open_in_office && createdFile) {
				try {
					const ncFileId = await api.getEditUrl(createdFile.id, 'edit')
					window.open(generateUrl('/f/' + ncFileId), '_blank')
				} catch {
					warnings.push(t('opencase', 'Filen blev oprettet, men kunne ikke åbnes i Office.'))
				}
			}

			// Optionally add a citizen as a document contact
			if (action.citizen_cpr) {
				const w = await this.addCitizenContact(doc.id, action.citizen_cpr, action.citizen_role)
				if (w) warnings.push(w)
			}

			// Optionally add a company as a document contact
			if (action.company_cvr) {
				const w = await this.addCompanyContact(doc.id, action.company_cvr, action.company_role)
				if (w) warnings.push(w)
			}

			await this.$store.dispatch('fetchDocuments', caseId)

			const label = warnings.length
				? t('opencase', 'Dokument \'{t}\' oprettet. ', { t: doc.title }) + warnings.join(' ')
				: t('opencase', 'Dokument \'{t}\' oprettet.', { t: doc.title })

			return { ok: true, label }
		},

		async actionAddFileFromTemplate(action, ctx, runtimeCtx = {}) {
			const docId = runtimeCtx.lastCreatedDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			const templateName = (action.template ?? '').toLowerCase()
			const templates = await api.getTemplates()
			const template = templates.find(tmpl => tmpl.name?.toLowerCase() === templateName)
				?? templates.find(tmpl => tmpl.name?.toLowerCase().includes(templateName))
			if (!template) {
				return { ok: false, label: t('opencase', 'Skabelonen \'{s}\' blev ikke fundet.', { s: action.template }) }
			}
			await api.createFileFromTemplate(docId, template.id)
			this.$root.$emit('ai:files-changed', docId)
			return { ok: true, label: t('opencase', 'Fil oprettet fra skabelon \'{s}\'.', { s: template.name }) }
		},

		async actionAddCitizenContact(action, ctx, runtimeCtx = {}) {
			const docId = runtimeCtx.lastCreatedDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			const warning = await this.addCitizenContact(docId, action.citizen_cpr, action.citizen_role)
			if (!warning) this.$root.$emit('ai:contacts-changed', docId)
			return warning
				? { ok: false, label: warning }
				: { ok: true, label: t('opencase', 'Borger tilføjet som kontakt.') }
		},

		async actionAddCompanyContact(action, ctx, runtimeCtx = {}) {
			const docId = runtimeCtx.lastCreatedDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			const warning = await this.addCompanyContact(docId, action.company_cvr, action.company_role)
			if (!warning) this.$root.$emit('ai:contacts-changed', docId)
			return warning
				? { ok: false, label: warning }
				: { ok: true, label: t('opencase', 'Virksomhed tilføjet som kontakt.') }
		},

		async actionMoveToCase(action, ctx, runtimeCtx = {}) {
			const docId = ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			if (!ctx?.document_is_inbox && !this.currentDocumentIsInbox) {
				return { ok: false, label: t('opencase', 'Dokumentet er ikke et indkommende dokument.') }
			}
			const caseNumber = (action.case_number ?? '').trim()
			if (!caseNumber) {
				return { ok: false, label: t('opencase', 'Sagsnummer er påkrævet.') }
			}

			const doc = await api.moveDocumentToCase(docId, caseNumber)

			runtimeCtx.sourceDocumentId = docId

			this.$store.commit('SET_CURRENT_DOCUMENT', doc)
			this.$root.$emit('ai:document-moved', doc)

			return {
				ok: true,
				label: t('opencase', 'Dokument journaliseret på sag {n} med dokumentnummer {dn}.', {
					n: caseNumber,
					dn: doc.document_number,
				}),
			}
		},

		async actionCreateCaseAndMoveDocument(action, ctx, runtimeCtx = {}) {
			const docId = ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			if (!ctx?.document_is_inbox && !this.currentDocumentIsInbox) {
				return { ok: false, label: t('opencase', 'Dokumentet er ikke et indkommende dokument.') }
			}
			try {
				const created = await this._buildAndCreateCase(action)
				const doc = await api.moveDocumentToCase(docId, created.case_number)

				// Expose created case and source doc to subsequent actions in this chain
				runtimeCtx.newCaseId        = created.id
				runtimeCtx.newCaseNumber    = created.case_number
				runtimeCtx.sourceDocumentId = docId

				this.$store.commit('SET_CURRENT_DOCUMENT', doc)
				this.$root.$emit('ai:document-moved', doc)
				this.$router.push({ name: 'case-detail', params: { id: String(created.id) } })
				return {
					ok: true,
					label: t('opencase', 'Sag {cn} oprettet og dokument journaliseret som {dn}.', {
						cn: created.case_number,
						dn: doc.document_number,
					}),
				}
			} catch (err) {
				return { ok: false, label: err.message || t('opencase', 'Handlingen fejlede.') }
			}
		},

		async actionAddSenderAsReceiver(action, ctx, runtimeCtx = {}) {
			// Source: the original inbox document; target: the last document created in this chain
			const sourceDocId = runtimeCtx.sourceDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			const targetDocId = runtimeCtx.lastCreatedDocumentId

			if (!sourceDocId) {
				return { ok: false, label: t('opencase', 'Intet kildedokument at kopiere afsender fra.') }
			}
			if (!targetDocId) {
				return { ok: false, label: t('opencase', 'Intet måldokument. Opret et dokument først.') }
			}

			const [contacts, roles] = await Promise.all([
				api.getDocumentContacts(sourceDocId),
				api.getContactRoles(),
			])

			const senderRole = roles.find(r => r.name?.toLowerCase().includes('afsend'))
			const senders = senderRole
				? contacts.filter(c => c.contactrole_id === senderRole.id)
				: contacts

			if (!senders.length) {
				return { ok: false, label: t('opencase', 'Ingen afsender fundet på det indkommende dokument.') }
			}

			const receiverRole = roles.find(r => r.name?.toLowerCase().includes('modtag')) ?? roles[0]
			if (!receiverRole) {
				return { ok: false, label: t('opencase', 'Ingen modtagerrolle fundet.') }
			}

			for (const sender of senders) {
				// Look up fresh data from CitizenClient (CPR = 10 digits) or CompanyClient (CVR = 8 digits)
				let entity = sender
				const cprCvr = (sender.cpr_cvr ?? '').replace(/\D/g, '')
				if (cprCvr.length === 10) {
					const results = await api.searchCitizen({ cpr: cprCvr }).catch(() => [])
					if (results.length) entity = { ...results[0], cpr_cvr: results[0].cpr_cvr ?? sender.cpr_cvr }
				} else if (cprCvr.length === 8) {
					const results = await api.searchCompany({ cvr: cprCvr }).catch(() => [])
					if (results.length) entity = { ...results[0], cpr_cvr: results[0].cpr_cvr ?? sender.cpr_cvr }
				}

				await api.createDocumentContact(targetDocId, {
					contactrole_id: receiverRole.id,
					cpr_cvr:        entity.cpr_cvr,
					name:           entity.name,
					streetname:     entity.streetname,
					housenumber:    entity.housenumber,
					floor:          entity.floor,
					door:           entity.door,
					zipcode:        entity.zipcode,
					zipdistrict:    entity.zipdistrict,
					phone:          entity.phone,
					email:          entity.email,
				})
			}

			this.$root.$emit('ai:contacts-changed', targetDocId)

			return {
				ok: true,
				label: t('opencase', '{n} afsender(e) tilføjet som modtager(e).', { n: senders.length }),
			}
		},

		async actionSendDigitalPost(action, ctx, runtimeCtx = {}) {
			const docId = runtimeCtx.lastCreatedDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}

			const canBeReplied = action.can_be_replied !== false

			// Fetch files, contacts and roles in parallel
			const [files, contacts, roles] = await Promise.all([
				api.getFilesByDocument(docId),
				api.getDocumentContacts(docId),
				api.getContactRoles(),
			])

			if (!files.length) {
				return { ok: false, label: t('opencase', 'Dokumentet har ingen filer at sende.') }
			}

			const mainFileId = files[0].id

			// Resolve receiver role by name (consistent with how contacts are created)
			const receiverRole = roles.find(r => r.name?.toLowerCase().includes('modtag')) ?? null
			const receivers = receiverRole
				? contacts.filter(c => c.contactrole_id === receiverRole.id)
				: contacts
			if (!receivers.length) {
				return { ok: false, label: t('opencase', 'Dokumentet har ingen modtagere.') }
			}

			// Check digital post subscription for each receiver with a CPR/CVR in parallel
			const dpChecks = await Promise.all(
				receivers.map(async (r) => {
					if (!r.cpr_cvr) return false
					try {
						const result = await api.checkDigitalPost(r.cpr_cvr)
						return result?.has_subscription === true
					} catch {
						return false
					}
				}),
			)

			await api.createDigitalPostShipment(docId, {
				can_be_replied: canBeReplied,
				main_file_id:   mainFileId,
				receivers: receivers.map((r, i) => ({
					document_contact_id: r.id,
					cpr_cvr:             r.cpr_cvr || null,
					receiver_name:       r.name || null,
					dp_subscription:     dpChecks[i],
				})),
			})

			const replyText = canBeReplied
				? t('opencase', 'som kan besvares')
				: t('opencase', 'som ikke kan besvares')
			return {
				ok: true,
				label: t('opencase', 'Digital post sendt ({reply}, {n} modtager(e)).', {
					reply: replyText,
					n: receivers.length,
				}),
			}
		},

		async actionAddCitizenParticipant(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag at tilføje part på.') }
			}
			const warning = await this.addCaseParticipant(caseId, 'cpr', action.citizen_cpr, action.participant_role)
			if (!warning) this.$root.$emit('ai:participants-changed', caseId)
			return warning
				? { ok: false, label: warning }
				: { ok: true, label: t('opencase', 'Borger tilføjet som part på sagen.') }
		},

		async actionAddCompanyParticipant(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag at tilføje part på.') }
			}
			const warning = await this.addCaseParticipant(caseId, 'cvr', action.company_cvr, action.participant_role)
			if (!warning) this.$root.$emit('ai:participants-changed', caseId)
			return warning
				? { ok: false, label: warning }
				: { ok: true, label: t('opencase', 'Virksomhed tilføjet som part på sagen.') }
		},

		async addCaseParticipant(caseId, type, cprCvr, roleName) {
			if (!cprCvr) {
				return type === 'cpr'
					? t('opencase', 'CPR-nummer er påkrævet.')
					: t('opencase', 'CVR-nummer er påkrævet.')
			}

			let entity
			if (type === 'cpr') {
				const results = await api.searchCitizen({ cpr: cprCvr })
				if (!results.length) return t('opencase', 'Borger med CPR {cpr} blev ikke fundet.', { cpr: cprCvr })
				entity = results[0]
			} else {
				const results = await api.searchCompany({ cvr: cprCvr })
				if (!results.length) return t('opencase', 'Virksomhed med CVR {cvr} blev ikke fundet.', { cvr: cprCvr })
				entity = results[0]
			}

			const roles = await api.getParticipantRoles()
			const wanted = (roleName ?? 'part').toLowerCase()
			const role = roles.find(r => r.name?.toLowerCase() === wanted)
				?? roles.find(r => r.name?.toLowerCase().includes(wanted))
				?? roles[0]
			if (!role) return t('opencase', 'Ingen partroller fundet.')

			try {
				await api.createCaseParticipant(caseId, {
					participantrole_id: role.id,
					cpr_cvr:            entity.cpr_cvr,
					name:               entity.name,
					streetname:         entity.streetname,
					housenumber:        entity.housenumber,
					floor:              entity.floor,
					door:               entity.door,
					zipcode:            entity.zipcode,
					zipdistrict:        entity.zipdistrict,
					phone:              entity.phone,
					email:              entity.email,
				})
			} catch (err) {
				const msg = err?.response?.data?.ocs?.data?.error ?? ''
				if (msg.toLowerCase().includes('already exists')) {
					return t('opencase', 'Parten er allerede tilføjet med denne rolle.')
				}
				throw err
			}

			return null
		},

		async addCompanyContact(documentId, cvr, roleName) {
			const companies = await api.searchCompany({ cvr })
			if (!companies.length) {
				return t('opencase', 'Virksomhed med CVR {cvr} blev ikke fundet.', { cvr })
			}
			const company = companies[0]
			return this.createContact(documentId, roleName, company)
		},

		// Returns a warning string on partial failure, null on success
		async addCitizenContact(documentId, cpr, roleName) {
			const citizens = await api.searchCitizen({ cpr })
			if (!citizens.length) {
				return t('opencase', 'Borger med CPR {cpr} blev ikke fundet.', { cpr })
			}
			return this.createContact(documentId, roleName, citizens[0])
		},

		async createContact(documentId, roleName, entity) {
			const roles = await api.getContactRoles()
			const wanted = (roleName ?? 'modtager').toLowerCase()
			const role = roles.find(r => r.name?.toLowerCase() === wanted)
				?? roles.find(r => r.name?.toLowerCase().includes(wanted))
				?? roles.find(r => r.name?.toLowerCase().includes('modtag'))
				?? roles[0]

			if (!role) {
				return t('opencase', 'Ingen kontaktroller fundet.')
			}

			await api.createDocumentContact(documentId, {
				contactrole_id: role.id,
				cpr_cvr:        entity.cpr_cvr,
				name:           entity.name,
				streetname:     entity.streetname,
				housenumber:    entity.housenumber,
				floor:          entity.floor,
				door:           entity.door,
				zipcode:        entity.zipcode,
				zipdistrict:    entity.zipdistrict,
				phone:          entity.phone,
				email:          entity.email,
			})

			return null
		},

		async actionCreateJournalNote(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag at oprette journalnotat på.') }
			}
			const title = (action.title ?? '').trim()
			if (!title) {
				return { ok: false, label: t('opencase', 'Titel er påkrævet for journalnotat.') }
			}
			await api.createJournalNote(caseId, { title, text: action.text ?? '' })
			this.$root.$emit('ai:journal-notes-changed', caseId)
			return { ok: true, label: t('opencase', 'Journalnotat \'{t}\' oprettet.', { t: title }) }
		},

		async actionAddOtherCaseworker(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag.') }
			}
			const query = (action.user_id ?? '').trim()
			if (!query) {
				return { ok: false, label: t('opencase', 'Brugernavn er påkrævet.') }
			}
			const userId = await this._resolveUserId(query)
			if (!userId) {
				return { ok: false, label: t('opencase', 'Bruger \'{u}\' blev ikke fundet.', { u: query }) }
			}
			try {
				await api.addCaseworker(caseId, userId)
			} catch (err) {
				const msg = err?.response?.data?.ocs?.data?.error ?? ''
				if (msg.toLowerCase().includes('already')) {
					return { ok: false, label: t('opencase', 'Brugeren er allerede sagsbehandler på sagen.') }
				}
				throw err
			}
			this.$root.$emit('ai:caseworkers-changed', caseId)
			return { ok: true, label: t('opencase', 'Sagsbehandler \'{u}\' tilføjet.', { u: userId }) }
		},

		async actionGrantAccess(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag.') }
			}
			const query = (action.user_id ?? '').trim()
			if (!query) {
				return { ok: false, label: t('opencase', 'Brugernavn er påkrævet.') }
			}
			const canWrite = action.can_write !== false && action.can_write !== 'false'
			const userId = await this._resolveUserId(query)
			if (!userId) {
				return { ok: false, label: t('opencase', 'Bruger \'{u}\' blev ikke fundet.', { u: query }) }
			}
			await api.grantCaseAccess(caseId, userId, canWrite)
			this.$root.$emit('ai:grants-changed', caseId)
			const accessLabel = canWrite
				? t('opencase', 'skriveadgang')
				: t('opencase', 'læseadgang')
			return {
				ok: true,
				label: t('opencase', '{u} har fået {a} til sagen.', { u: userId, a: accessLabel }),
			}
		},

		async actionCreateSubCase(action, ctx, runtimeCtx = {}) {
			const parentId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!parentId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag at oprette undersag på.') }
			}
			const title = (action.title ?? '').trim()
			if (!title) {
				return { ok: false, label: t('opencase', 'Titel er påkrævet for undersagen.') }
			}
			// Cache parent metadata on first call so subsequent router navigations
			// (caused by earlier sub-case creations) don't corrupt the source data.
			if (!runtimeCtx.subCaseParentData) {
				const parent = this.$store.state.currentCase
				if (!parent) {
					return { ok: false, label: t('opencase', 'Sagdata er ikke tilgængelig.') }
				}
				runtimeCtx.subCaseParentData = {
					organisation:              parent.organisation,
					classification_code:       parent.classification_code,
					sensitivity_key:           parent.sensitivity_key,
					classification_facet_uuid: parent.classification_facet_uuid,
					insight_level_id:          parent.insight_level_id ?? null,
				}
			}
			const pd = runtimeCtx.subCaseParentData
			const kleOverride = (action.kle_number ?? action.kle ?? '').trim()

			const created = await api.createCase({
				title,
				organisation:              pd.organisation,
				classification_code:       kleOverride || pd.classification_code,
				sensitivity:               pd.sensitivity_key,
				classification_facet_uuid: pd.classification_facet_uuid,
				insight_level_id:          pd.insight_level_id,
				parent_case_id:            parseInt(parentId),
			})

			const warnings = []

			if (action.citizen_cpr) {
				const w = await this.addCaseParticipant(created.id, 'cpr', action.citizen_cpr, action.participant_role)
				if (w) warnings.push(w)
			}
			if (action.company_cvr) {
				const w = await this.addCaseParticipant(created.id, 'cvr', action.company_cvr, action.participant_role)
				if (w) warnings.push(w)
			}

			if (action.document_title) {
				const categories = await api.getDocumentCategories()
				const wanted = (action.document_category ?? '').toLowerCase()
				const category = categories.find(c => c.name?.toLowerCase() === wanted)
					?? categories.find(c => c.name?.toLowerCase().includes(wanted))
					?? categories[0]
				const doc = await api.createDocument(created.id, {
					title:                action.document_title,
					document_category_id: category?.id,
				})
				if (action.document_template) {
					const templateName = action.document_template.toLowerCase()
					const templates = await api.getTemplates()
					const template = templates.find(tmpl => tmpl.name?.toLowerCase() === templateName)
						?? templates.find(tmpl => tmpl.name?.toLowerCase().includes(templateName))
					if (template) {
						await api.createFileFromTemplate(doc.id, template.id)
					} else {
						warnings.push(t('opencase', 'Skabelonen \'{s}\' blev ikke fundet.', { s: action.document_template }))
					}
				}
			}

			this.$router.push({ name: 'case-detail', params: { id: String(created.id) } })

			const label = warnings.length
				? t('opencase', 'Undersag \'{t}\' oprettet. ', { t: created.title }) + warnings.join(' ')
				: t('opencase', 'Undersag \'{t}\' oprettet.', { t: created.title })
			return { ok: true, label }
		},

		async actionCopyCase(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag at kopiere.') }
			}
			const title = (action.title ?? '').trim()
			if (!title) {
				return { ok: false, label: t('opencase', 'Titel er påkrævet for kopien.') }
			}
			const source = this.$store.state.currentCase
			if (!source) {
				return { ok: false, label: t('opencase', 'Sagdata er ikke tilgængelig.') }
			}
			const created = await api.createCase({
				title,
				organisation:              source.organisation,
				classification_code:       source.classification_code,
				sensitivity:               source.sensitivity_key,
				classification_facet_uuid: source.classification_facet_uuid,
				insight_level_id:          source.insight_level_id ?? null,
			})
			this.$router.push({ name: 'case-detail', params: { id: String(created.id) } })
			return { ok: true, label: t('opencase', 'Sag kopieret som \'{t}\'.', { t: created.title }) }
		},

		async actionCloseCase(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag at lukke.') }
			}
			const statuses = await api.getCaseStatuses()
			const closedStatus = statuses.find(s => s.is_closed)
			if (!closedStatus) {
				return { ok: false, label: t('opencase', 'Ingen lukket status fundet.') }
			}
			const updated = await api.changeCaseStatus(caseId, closedStatus.id)
			this.$store.commit('SET_CURRENT_CASE', updated)
			return { ok: true, label: t('opencase', 'Sagen er lukket.') }
		},

		async actionAddToFavorites(action, ctx, runtimeCtx = {}) {
			const caseId = runtimeCtx.newCaseId ?? ctx?.case_id ?? this.currentCaseId
			if (!caseId) {
				return { ok: false, label: t('opencase', 'Ingen aktuel sag.') }
			}
			await this.$store.dispatch('addFavorite', { entity: 'case', key: parseInt(caseId) })
			return { ok: true, label: t('opencase', 'Sagen er tilføjet til favoritter.') }
		},

		async actionCreateNote(action, ctx, runtimeCtx = {}) {
			const docId = runtimeCtx.lastCreatedDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			const title = (action.title ?? '').trim()
			if (!title) {
				return { ok: false, label: t('opencase', 'Titel er påkrævet for noten.') }
			}
			await api.createDocumentNote(docId, { title, text: action.text ?? '' })
			this.$root.$emit('ai:notes-changed', docId)
			return { ok: true, label: t('opencase', 'Note \'{t}\' oprettet.', { t: title }) }
		},

		async actionShareDocument(action, ctx, runtimeCtx = {}) {
			const docId = runtimeCtx.lastCreatedDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			const query = (action.user_id ?? '').trim()
			if (!query) {
				return { ok: false, label: t('opencase', 'Brugernavn er påkrævet.') }
			}
			const userId = await this._resolveUserId(query)
			if (!userId) {
				return { ok: false, label: t('opencase', 'Bruger \'{u}\' blev ikke fundet.', { u: query }) }
			}
			const canWrite = action.can_write === true || action.can_write === 'true'
			await api.createDocumentShare(docId, userId, canWrite)
			const accessLabel = canWrite
				? t('opencase', 'redigerbart')
				: t('opencase', 'skrivebeskyttet')
			return {
				ok: true,
				label: t('opencase', 'Dokument delt med {u} ({a}).', { u: userId, a: accessLabel }),
			}
		},

		async actionAddDocumentToFavorites(action, ctx, runtimeCtx = {}) {
			const docId = runtimeCtx.lastCreatedDocumentId ?? ctx?.document_id ?? this.currentDocumentId
			if (!docId) {
				return { ok: false, label: t('opencase', 'Intet dokument er åbent.') }
			}
			await this.$store.dispatch('addFavorite', { entity: 'document', key: parseInt(docId) })
			return { ok: true, label: t('opencase', 'Dokumentet er tilføjet til favoritter.') }
		},

		async _resolveUserId(query) {
			const results = await api.searchCaseworkerUsers(query).catch(() => [])
			if (results.length) return results[0].id
			// Fallback: try NC sharees search
			const ncResults = await api.searchUsers(query).catch(() => [])
			if (ncResults.length) return ncResults[0].id
			return null
		},

		scrollToBottom() {
			const el = this.$refs.messages
			if (el) el.scrollTop = el.scrollHeight
		},
	},
}
</script>

<style scoped>
.ai-sidepanel-root {
	display: contents;
}

.ai-panel {
	display: flex;
	flex-direction: column;
	height: calc(100vh - 160px);
	padding: 12px;
	gap: 10px;
	box-sizing: border-box;
}

.ai-panel__toolbar {
	display: flex;
	gap: 6px;
	flex-shrink: 0;
}

.ai-panel__messages {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-right: 2px;
}

.ai-panel__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding-top: 32px;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.ai-panel__empty-icon { opacity: 0.35; }

.ai-panel__empty-title {
	font-weight: 600;
	margin: 0;
}

.ai-panel__empty-hint {
	margin: 4px 0 0;
	font-size: 0.85em;
}

.ai-panel__examples {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
	width: 100%;
}

.ai-panel__examples li {
	cursor: pointer;
	font-size: 0.85em;
	padding: 6px 10px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	text-align: left;
	transition: background 0.1s;
}

.ai-panel__examples li:hover {
	background: var(--color-background-hover);
}

.ai-panel__message {
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	max-width: 88%;
	line-height: 1.5;
	font-size: 0.9em;
}

.ai-panel__message--user {
	align-self: flex-end;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.ai-panel__message--assistant {
	align-self: flex-start;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.ai-panel__message--loading { padding: 12px 16px; }

.ai-panel__dots {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.ai-panel__dots span {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
	animation: ai-dot-bounce 1.2s infinite ease-in-out;
}

.ai-panel__dots span:nth-child(2) { animation-delay: 0.2s; }
.ai-panel__dots span:nth-child(3) { animation-delay: 0.4s; }

@keyframes ai-dot-bounce {
	0%, 80%, 100% { transform: scale(0.7); opacity: 0.5; }
	40%           { transform: scale(1);   opacity: 1; }
}

.ai-panel__action-result {
	align-self: flex-start;
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.82em;
	padding: 4px 10px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	max-width: 88%;
}

.ai-panel__action-result-icon--ok  { color: var(--color-success); flex-shrink: 0; }
.ai-panel__action-result-icon--err { color: var(--color-error);   flex-shrink: 0; }

.ai-panel__error {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	background: rgba(var(--color-error-rgb, 255,0,0), 0.12);
	color: var(--color-error);
	font-size: 0.85em;
}

.ai-panel__input-area {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-shrink: 0;
}

.ai-panel__textarea {
	flex: 1;
	min-height: 36px;
	max-height: 200px;
	padding: 7px 10px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	font-family: inherit;
	line-height: 1.5;
	resize: none;
	overflow-y: auto;
	box-sizing: border-box;
	transition: border-color 0.1s;
}

.ai-panel__textarea:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element-light);
}

.ai-panel__textarea:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.ai-panel__mic-btn {
	flex-shrink: 0;
}

.ai-panel__mic-btn--active :deep(button) {
	background: var(--color-error) !important;
	color: #fff !important;
	animation: ai-mic-pulse 1.4s infinite ease-in-out;
}

@keyframes ai-mic-pulse {
	0%, 100% { box-shadow: 0 0 0 0 rgba(var(--color-error-rgb, 255,0,0), 0.4); }
	50%       { box-shadow: 0 0 0 6px rgba(var(--color-error-rgb, 255,0,0), 0); }
}
</style>
