<template>
	<NcDialog :name="t('opencase', 'Kopier sag')"
		size="large"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-copy-case">
			<!-- Tab bar -->
			<div class="opencase-copy-case__tabs">
				<button v-for="tab in tabs"
					:key="tab.id"
					class="opencase-copy-case__tab"
					:class="{ 'opencase-copy-case__tab--active': activeTab === tab.id }"
					type="button"
					@click="activeTab = tab.id">
					{{ tab.label }}
					<span v-if="tab.count != null" class="opencase-copy-case__tab-count">{{ tab.count }}</span>
				</button>
			</div>

			<!-- Info tab -->
			<div v-if="activeTab === 'info'" class="opencase-copy-case__panel">
				<div class="opencase-copy-case__field">
					<label>{{ t('opencase', 'Titel') }}</label>
					<NcTextField v-model="form.title" />
				</div>
				<div class="opencase-copy-case__field">
					<label>{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="selectedOrg"
						:options="orgOptions"
						:loading="orgSearchLoading"
						:filterable="false"
						:placeholder="t('opencase', 'Søg efter organisation')"
						@search="onOrgSearch"
						@update:model-value="v => form.organisation = v?.id || ''" />
				</div>
				<div class="opencase-copy-case__field">
					<label>{{ t('opencase', 'KLE-nummer') }}</label>
					<NcSelect v-model="selectedClassification"
						:options="classificationOptions"
						:placeholder="t('opencase', 'Vælg KLE-emneord')"
						@update:model-value="v => form.classification_code = v?.id || ''" />
				</div>
				<div class="opencase-copy-case__field">
					<label>{{ t('opencase', 'Følsomhed') }}</label>
					<NcSelect v-model="selectedSensitivity"
						:options="sensitivityOptions"
						@update:model-value="v => form.sensitivity = v?.id || null" />
				</div>
				<div class="opencase-copy-case__field">
					<label>{{ t('opencase', 'Handlingsfacet') }}</label>
					<NcSelect v-model="selectedClassificationFacet"
						:options="classificationFacetOptions"
						:placeholder="t('opencase', 'Vælg handlingsfacet')"
						@update:model-value="v => form.classification_facet_uuid = v?.id || ''" />
				</div>
				<div class="opencase-copy-case__field">
					<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="selectedInsightLevel"
						:options="insightLevelOptions"
						:placeholder="t('opencase', 'Vælg indsigtsgrad')"
						:clearable="true"
						@update:model-value="v => form.insight_level_id = v?.id || null" />
				</div>
				<div class="opencase-copy-case__field">
					<label>{{ t('opencase', 'Ansvarlig') }}</label>
					<NcSelect v-model="selectedResponsibleUser"
						:options="userOptions"
						:loading="userSearchLoading"
						:filterable="false"
						:placeholder="t('opencase', 'Søg efter bruger')"
						@search="onUserSearch"
						@update:model-value="v => form.responsible_user_id = v?.id || null" />
				</div>
			</div>

			<!-- Documents tab -->
			<div v-else-if="activeTab === 'documents'" class="opencase-copy-case__panel">
				<NcLoadingIcon v-if="loading.documents" :size="32" />
				<NcEmptyContent v-else-if="documents.length === 0"
					:title="t('opencase', 'Ingen dokumenter')">
					<template #icon><FileIcon :size="36" /></template>
				</NcEmptyContent>
				<template v-else>
					<div class="opencase-copy-case__select-all">
						<NcCheckboxRadioSwitch :checked="allDocumentsSelected" @update:checked="toggleAllDocuments">
							{{ t('opencase', 'Vælg alle') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div v-for="doc in documents" :key="doc.id" class="opencase-copy-case__item">
						<NcCheckboxRadioSwitch :checked="selectedDocuments.includes(doc.id)"
							@update:checked="v => toggleItem(selectedDocuments, doc.id, v)">
							<span class="opencase-copy-case__item-title">{{ doc.title }}</span>
							<span v-if="doc.document_type" class="opencase-copy-case__item-meta">{{ doc.document_type }}</span>
						</NcCheckboxRadioSwitch>
					</div>
				</template>
			</div>

			<!-- Participants tab -->
			<div v-else-if="activeTab === 'participants'" class="opencase-copy-case__panel">
				<NcLoadingIcon v-if="loading.participants" :size="32" />
				<NcEmptyContent v-else-if="participants.length === 0"
					:title="t('opencase', 'Ingen parter')">
					<template #icon><AccountIcon :size="36" /></template>
				</NcEmptyContent>
				<template v-else>
					<div class="opencase-copy-case__select-all">
						<NcCheckboxRadioSwitch :checked="allParticipantsSelected" @update:checked="toggleAllParticipants">
							{{ t('opencase', 'Vælg alle') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div v-for="p in participants" :key="p.id" class="opencase-copy-case__item">
						<NcCheckboxRadioSwitch :checked="selectedParticipants.includes(p.id)"
							@update:checked="v => toggleItem(selectedParticipants, p.id, v)">
							<span class="opencase-copy-case__item-title">{{ p.name || maskCpr(p.cpr_cvr) }}</span>
							<span v-if="p.role_name" class="opencase-copy-case__item-meta">{{ p.role_name }}</span>
						</NcCheckboxRadioSwitch>
					</div>
				</template>
			</div>

			<!-- Journal notes tab -->
			<div v-else-if="activeTab === 'journalnotes'" class="opencase-copy-case__panel">
				<NcLoadingIcon v-if="loading.journalnotes" :size="32" />
				<NcEmptyContent v-else-if="journalNotes.length === 0"
					:title="t('opencase', 'Ingen journalnotater')">
					<template #icon><NoteTextIcon :size="36" /></template>
				</NcEmptyContent>
				<template v-else>
					<div class="opencase-copy-case__select-all">
						<NcCheckboxRadioSwitch :checked="allJournalNotesSelected" @update:checked="toggleAllJournalNotes">
							{{ t('opencase', 'Vælg alle') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div v-for="note in journalNotes" :key="note.id" class="opencase-copy-case__item">
						<NcCheckboxRadioSwitch :checked="selectedJournalNotes.includes(note.id)"
							@update:checked="v => toggleItem(selectedJournalNotes, note.id, v)">
							<span class="opencase-copy-case__item-title">{{ note.title }}</span>
							<span class="opencase-copy-case__item-meta">{{ note.created_by }} · {{ formatDate(note.created_at) }}</span>
						</NcCheckboxRadioSwitch>
					</div>
				</template>
			</div>

			<!-- Caseworkers tab -->
			<div v-else-if="activeTab === 'caseworkers'" class="opencase-copy-case__panel">
				<NcLoadingIcon v-if="loading.caseworkers" :size="32" />
				<NcEmptyContent v-else-if="otherCaseworkers.length === 0"
					:title="t('opencase', 'Ingen andre sagsbehandlere')">
					<template #icon><AccountMultipleIcon :size="36" /></template>
				</NcEmptyContent>
				<template v-else>
					<div class="opencase-copy-case__select-all">
						<NcCheckboxRadioSwitch :checked="allCaseworkersSelected" @update:checked="toggleAllCaseworkers">
							{{ t('opencase', 'Vælg alle') }}
						</NcCheckboxRadioSwitch>
					</div>
					<div v-for="cw in otherCaseworkers" :key="cw.user_id" class="opencase-copy-case__item">
						<NcCheckboxRadioSwitch :checked="selectedCaseworkers.includes(cw.user_id)"
							@update:checked="v => toggleItem(selectedCaseworkers, cw.user_id, v)">
							<span class="opencase-copy-case__item-title">{{ cw.display_name }}</span>
						</NcCheckboxRadioSwitch>
					</div>
				</template>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Annuller') }}</NcButton>
			<NcButton type="primary" :disabled="saving || !form.title.trim()" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencase', 'Kopier sag') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { showError, showSuccess } from '@nextcloud/dialogs'

import FileIcon from 'vue-material-design-icons/File.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import NoteTextIcon from 'vue-material-design-icons/NoteText.vue'

import api from '../services/api.js'
import { maskCpr } from '../utils/cprMask.js'

export default {
	name: 'CopyCaseDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcEmptyContent,
		FileIcon,
		AccountIcon,
		AccountMultipleIcon,
		NoteTextIcon,
	},

	props: {
		caseData: { type: Object, required: true },
	},

	emits: ['close', 'copied'],

	data() {
		const sensitivities = this.$store.state.sensitivities
		const sensitivityMatch = sensitivities.find(s => s.key === this.caseData.sensitivity_key) || null

		const classSubjects = this.$store.state.classificationSubjects
		const classMatch = classSubjects.find(s => s.code === this.caseData.classification_code) || null

		const responsibleUid  = this.caseData.responsible_user_id || null
		const responsibleName = this.caseData.responsible_user_display_name || responsibleUid || ''
		const responsibleOption = responsibleUid ? { id: responsibleUid, label: responsibleName } : null

		const insightLevels = this.$store.state.insightLevels
		const insightMatch = this.caseData.insight_level_id
			? insightLevels.find(l => l.id === this.caseData.insight_level_id) || null
			: null

		const initialOrg = this.caseData.organisation
			? { id: this.caseData.organisation, label: this.caseData.organisation }
			: null

		const facets = this.$store.state.classificationFacets
		const facetMatch = this.caseData.classification_facet_uuid
			? facets.find(f => f.uuid === this.caseData.classification_facet_uuid) || null
			: null

		return {
			activeTab: 'info',

			// Info form
			form: {
				title: this.caseData.title,
				organisation: this.caseData.organisation || '',
				classification_code: this.caseData.classification_code || '',
				sensitivity: this.caseData.sensitivity_key || null,
				insight_level_id: this.caseData.insight_level_id || null,
				classification_facet_uuid: this.caseData.classification_facet_uuid || '',
				responsible_user_id: responsibleUid,
			},
			selectedOrg: initialOrg,
			orgOptions: initialOrg ? [initialOrg] : [],
			orgSearchLoading: false,
			orgSearchTimeout: null,
			selectedClassification: classMatch
				? { id: classMatch.code, label: `${classMatch.code} – ${classMatch.title}` }
				: (this.caseData.classification_code
					? { id: this.caseData.classification_code, label: this.caseData.classification_code }
					: null),
			selectedSensitivity: sensitivityMatch
				? { id: sensitivityMatch.key, label: sensitivityMatch.title }
				: null,
			selectedInsightLevel: insightMatch
				? { id: insightMatch.id, label: insightMatch.name }
				: null,
			selectedClassificationFacet: facetMatch
				? { id: facetMatch.uuid, label: `${facetMatch.code} – ${facetMatch.title}` }
				: (this.caseData.classification_facet_uuid
					? { id: this.caseData.classification_facet_uuid, label: this.caseData.classification_facet_code ? `${this.caseData.classification_facet_code} – ${this.caseData.classification_facet_title}` : this.caseData.classification_facet_uuid }
					: null),
			selectedResponsibleUser: responsibleOption,
			userOptions: responsibleOption ? [responsibleOption] : [],
			userSearchLoading: false,
			userSearchTimeout: null,

			// Tab data
			documents: [],
			participants: [],
			journalNotes: [],
			caseworkers: [],
			loading: { documents: false, participants: false, journalnotes: false, caseworkers: false },

			// Selections
			selectedDocuments: [],
			selectedParticipants: [],
			selectedJournalNotes: [],
			selectedCaseworkers: [],

			saving: false,
		}
	},

	computed: {
		classificationOptions() {
			return this.$store.state.classificationSubjects
				.filter(s => /^\d{2}\.\d{2}\.\d{2}$/.test(s.code))
				.map(s => ({
					id: s.code,
					label: `${s.code} – ${s.title}`,
				}))
		},
		sensitivityOptions() {
			return this.$store.state.sensitivities.map(s => ({ id: s.key, label: s.title }))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({ id: l.id, label: l.name }))
		},
		classificationFacetOptions() {
			return this.$store.state.classificationFacets
				.filter(f => /^[A-Za-z]\d{2}$/.test(f.code))
				.map(f => ({
					id: f.uuid,
					label: `${f.code} – ${f.title}`,
				}))
		},
		otherCaseworkers() {
			return this.caseworkers.filter(cw => cw.role !== 'responsible')
		},
		tabs() {
			return [
				{ id: 'info', label: t('opencase', 'Info'), count: null },
				{ id: 'documents', label: t('opencase', 'Dokumenter'), count: this.selectedDocuments.length || null },
				{ id: 'participants', label: t('opencase', 'Parter'), count: this.selectedParticipants.length || null },
				{ id: 'journalnotes', label: t('opencase', 'Journalnotater'), count: this.selectedJournalNotes.length || null },
				{ id: 'caseworkers', label: t('opencase', 'Andre sagsbehandlere'), count: this.selectedCaseworkers.length || null },
			]
		},
		allDocumentsSelected() {
			return this.documents.length > 0 && this.selectedDocuments.length === this.documents.length
		},
		allParticipantsSelected() {
			return this.participants.length > 0 && this.selectedParticipants.length === this.participants.length
		},
		allJournalNotesSelected() {
			return this.journalNotes.length > 0 && this.selectedJournalNotes.length === this.journalNotes.length
		},
		allCaseworkersSelected() {
			return this.otherCaseworkers.length > 0 && this.selectedCaseworkers.length === this.otherCaseworkers.length
		},
	},

	async mounted() {
		this.$store.dispatch('fetchClassificationSubjects')
		this.$store.dispatch('fetchSensitivities')
		this.$store.dispatch('fetchInsightLevels')
		this.$store.dispatch('fetchClassificationFacets')
		await Promise.all([
			this.loadDocuments(),
			this.loadParticipants(),
			this.loadJournalNotes(),
			this.loadCaseworkers(),
		])
	},

	methods: {
		maskCpr,
		toggleItem(list, id, checked) {
			const idx = list.indexOf(id)
			if (checked && idx === -1) list.push(id)
			else if (!checked && idx !== -1) list.splice(idx, 1)
		},
		toggleAllDocuments(v) {
			this.selectedDocuments = v ? this.documents.map(d => d.id) : []
		},
		toggleAllParticipants(v) {
			this.selectedParticipants = v ? this.participants.map(p => p.id) : []
		},
		toggleAllJournalNotes(v) {
			this.selectedJournalNotes = v ? this.journalNotes.map(n => n.id) : []
		},
		toggleAllCaseworkers(v) {
			this.selectedCaseworkers = v ? this.otherCaseworkers.map(cw => cw.user_id) : []
		},

		async loadDocuments() {
			this.loading.documents = true
			try {
				this.documents = await api.getDocuments(this.caseData.id)
			} catch (e) { /* ignore */ } finally {
				this.loading.documents = false
			}
		},
		async loadParticipants() {
			this.loading.participants = true
			try {
				this.participants = await api.getCaseParticipants(this.caseData.id)
			} catch (e) { /* ignore */ } finally {
				this.loading.participants = false
			}
		},
		async loadJournalNotes() {
			this.loading.journalnotes = true
			try {
				this.journalNotes = await api.getJournalNotes(this.caseData.id)
			} catch (e) { /* ignore */ } finally {
				this.loading.journalnotes = false
			}
		},
		async loadCaseworkers() {
			this.loading.caseworkers = true
			try {
				this.caseworkers = await api.getCaseworkers(this.caseData.id)
			} catch (e) { /* ignore */ } finally {
				this.loading.caseworkers = false
			}
		},

		onOrgSearch(query) {
			clearTimeout(this.orgSearchTimeout)
			this.orgSearchLoading = true
			this.orgSearchTimeout = setTimeout(async () => {
				try {
					this.orgOptions = await api.searchOrganisations(query)
				} catch (e) { /* ignore */ } finally {
					this.orgSearchLoading = false
				}
			}, 300)
		},

		onUserSearch(query) {
			clearTimeout(this.userSearchTimeout)
			if (!query || query.length < 2) return
			this.userSearchLoading = true
			this.userSearchTimeout = setTimeout(async () => {
				try {
					this.userOptions = await api.searchUsers(query)
				} catch (e) { /* ignore */ } finally {
					this.userSearchLoading = false
				}
			}, 300)
		},

		async save() {
			const title = this.form.title.trim()
			if (!title) return

			this.saving = true
			try {
				// 1. Create the new case
				const newCase = await api.createCase({ ...this.form, title })

				// 2. Copy selected items in parallel
				const tasks = []

				// Documents (create then copy files — sequential per document, parallel across documents)
				const docsToCopy = this.documents.filter(d => this.selectedDocuments.includes(d.id))
				for (const doc of docsToCopy) {
					tasks.push(
						api.createDocument(newCase.id, {
							title: doc.title,
							document_type: doc.document_type || null,
							document_category_id: doc.document_category_id,
							insight_level_id: doc.insight_level_id || null,
						}).then(newDoc => api.copyFilesToDocument(doc.id, newDoc.id))
					)
				}

				// Participants
				const partsToCopy = this.participants.filter(p => this.selectedParticipants.includes(p.id))
				for (const p of partsToCopy) {
					tasks.push(api.createCaseParticipant(newCase.id, {
						participantrole_id: p.participantrole_id,
						cpr_cvr: p.cpr_cvr || null,
						name: p.name || null,
						streetname: p.streetname || null,
					}))
				}

				// Journal notes (copy as unlocked)
				const notesCopy = this.journalNotes.filter(n => this.selectedJournalNotes.includes(n.id))
				for (const note of notesCopy) {
					tasks.push(api.createJournalNote(newCase.id, {
						title: note.title,
						text: note.text || '',
						is_locked: false,
					}))
				}

				// Caseworkers
				for (const userId of this.selectedCaseworkers) {
					tasks.push(api.addCaseworker(newCase.id, userId))
				}

				await Promise.allSettled(tasks)

				showSuccess(t('opencase', 'Sag kopieret'))
				this.$emit('copied', newCase)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke kopiere sag'))
			} finally {
				this.saving = false
			}
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-copy-case {
	display: flex;
	flex-direction: column;
	min-height: 420px;
}

.opencase-copy-case__tabs {
	display: flex;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 20px;
	flex-wrap: wrap;
}

.opencase-copy-case__tab {
	padding: 8px 16px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 0.9em;
	color: var(--color-text-lighter);
	border-bottom: 2px solid transparent;
	margin-bottom: -1px;
	display: flex;
	align-items: center;
	gap: 6px;
	white-space: nowrap;
}

.opencase-copy-case__tab:hover {
	color: var(--color-main-text);
}

.opencase-copy-case__tab--active {
	color: var(--color-main-text);
	font-weight: 600;
	border-bottom-color: var(--color-primary-element);
}

.opencase-copy-case__tab-count {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-size: 0.75em;
	font-weight: 700;
	padding: 1px 6px;
	border-radius: 10px;
	min-width: 18px;
	text-align: center;
}

.opencase-copy-case__panel {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.opencase-copy-case__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-copy-case__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-copy-case__select-all {
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.opencase-copy-case__item {
	padding: 2px 0;
}

.opencase-copy-case__item-title {
	font-weight: 500;
}

.opencase-copy-case__item-meta {
	margin-left: 8px;
	font-size: 0.85em;
	color: var(--color-text-lighter);
}
</style>
