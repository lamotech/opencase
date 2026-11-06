<template>
	<div class="process-designer">
		<NcLoadingIcon v-if="loading" :size="32" class="process-designer__loading" />

		<NcEmptyContent v-else-if="loadError" :name="loadError">
			<template #icon>
				<SitemapOutlineIcon :size="64" />
			</template>
			<template #action>
				<NcButton @click="$router.push({ name: 'processes' })">
					{{ t('opencase', 'Tilbage til processer') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else-if="process">
			<div class="process-designer__header">
				<div class="process-designer__title">
					<NcButton type="tertiary"
						:title="t('opencase', 'Tilbage til processer')"
						@click="$router.push({ name: 'processes' })">
						<template #icon>
							<ArrowLeftIcon :size="20" />
						</template>
					</NcButton>
					<div>
						<h2>
							{{ process.name }}
							<span v-if="!process.active" class="process-designer__badge process-designer__badge--inactive">
								{{ t('opencase', 'Inaktiv') }}
							</span>
						</h2>
						<code class="process-designer__key">{{ process.process_key }}</code>
					</div>
				</div>

				<div class="process-designer__actions">
					<select v-model="selectedVersionId"
						class="process-designer__version-select"
						:title="t('opencase', 'Version')"
						@change="onVersionSelected">
						<option v-for="v in process.versions" :key="v.id" :value="v.id">
							v{{ v.version_no }} – {{ statusLabel(v.status) }}
						</option>
					</select>

					<NcButton @click="showSettings = true">
						<template #icon>
							<CogIcon :size="16" />
						</template>
						{{ t('opencase', 'Indstillinger') }}
					</NcButton>

					<NcButton @click="showStatistics = true">
						<template #icon>
							<ChartBarIcon :size="16" />
						</template>
						{{ t('opencase', 'Statistik') }}
					</NcButton>

					<NcActions :force-menu="true">
						<NcActionButton v-if="isDraft" :close-after-click="true" @click="$refs.importInput.click()">
							<template #icon>
								<UploadIcon :size="20" />
							</template>
							{{ t('opencase', 'Importér BPMN-fil') }}
						</NcActionButton>
						<NcActionButton :close-after-click="true" @click="exportXml">
							<template #icon>
								<DownloadIcon :size="20" />
							</template>
							{{ t('opencase', 'Eksportér BPMN-fil') }}
						</NcActionButton>
						<NcActionButton v-if="!process.draft_version_id && !isDraft"
							:close-after-click="true"
							@click="createDraft(currentVersion.id)">
							<template #icon>
								<ContentCopyIcon :size="20" />
							</template>
							{{ t('opencase', 'Ny kladde ud fra denne version') }}
						</NcActionButton>
						<NcActionButton v-if="process.published_version_id"
							:close-after-click="true"
							@click="showMigration = true">
							<template #icon>
								<SwapHorizontalIcon :size="20" />
							</template>
							{{ t('opencase', 'Flyt kørende processer til v{no}', { no: process.published_version_no }) }}
						</NcActionButton>
						<NcActionButton v-if="isDraft && process.versions.length > 1"
							:close-after-click="true"
							@click="deleteDraft">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('opencase', 'Slet kladde') }}
						</NcActionButton>
						<NcActionButton v-if="neverPublished"
							:close-after-click="true"
							@click="deleteProcess">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('opencase', 'Slet proces') }}
						</NcActionButton>
					</NcActions>

					<template v-if="isDraft">
						<NcButton :disabled="!dirty || saving" @click="save">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="16" />
								<ContentSaveIcon v-else :size="16" />
							</template>
							{{ t('opencase', 'Gem') }}
						</NcButton>
						<NcButton type="primary" :disabled="saving" @click="openPublish">
							<template #icon>
								<PublishIcon :size="16" />
							</template>
							{{ t('opencase', 'Udgiv') }}
						</NcButton>
					</template>
					<NcButton v-else-if="!process.draft_version_id" type="primary" @click="createDraft(null)">
						<template #icon>
							<PencilIcon :size="16" />
						</template>
						{{ t('opencase', 'Rediger (ny kladde)') }}
					</NcButton>
					<NcButton v-else type="primary" @click="openVersion(process.draft_version_id)">
						<template #icon>
							<PencilIcon :size="16" />
						</template>
						{{ t('opencase', 'Åbn kladde') }}
					</NcButton>
				</div>
			</div>

			<div v-if="currentVersion" class="process-designer__status">
				<template v-if="isDraft">
					{{ t('opencase', 'Kladde – ændringer træder først i kraft, når kladden udgives.') }}
					<span v-if="dirty" class="process-designer__unsaved">{{ t('opencase', 'Ikke gemt') }}</span>
				</template>
				<template v-else>
					{{ currentVersion.status === 'published'
						? t('opencase', 'Udgivet {date} af {user}. Nye processer startes på denne version.', { date: formatDate(currentVersion.published_at), user: currentVersion.published_by || '' })
						: t('opencase', 'Tidligere version, udgivet {date} af {user}. Processer, der allerede kører på den, fortsætter uændret.', { date: formatDate(currentVersion.published_at), user: currentVersion.published_by || '' }) }}
					<span v-if="currentVersion.change_note" class="process-designer__note">
						„{{ currentVersion.change_note }}"
					</span>
				</template>
			</div>

			<div class="process-designer__body">
				<div ref="canvas" class="process-designer__canvas" />
				<ProcessPropertiesPanel class="process-designer__panel"
					:element="selectedElement"
					:revision="elementRevision"
					:modeler="bpmnInstance"
					:read-only="!isDraft"
					:org-units="orgUnits"
					:topics="topics"
					:templates="templates"
					:document-categories="documentCategories"
					:case-types="caseTypes"
					:processes="otherProcesses"
					:custom-fields="customFields"
					:current-process-id="process.id" />
			</div>

			<input ref="importInput"
				type="file"
				accept=".bpmn,.xml,.bpmn20.xml"
				class="process-designer__file-input"
				@change="importXml">
		</template>

		<ProcessStatisticsDialog v-if="showStatistics"
			:process="process"
			@close="showStatistics = false" />

		<ProcessMigrationDialog v-if="showMigration"
			:process="process"
			@close="showMigration = false" />

		<ProcessSettingsDialog v-if="showSettings"
			:process="process"
			:case-types="caseTypes"
			:org-units="orgUnits"
			@close="showSettings = false"
			@saved="onSettingsSaved" />

		<NcDialog v-if="showPublish"
			:name="t('opencase', 'Udgiv version {no}', { no: currentVersion.version_no })"
			size="normal"
			@closing="showPublish = false">
			<NcLoadingIcon v-if="validating" :size="32" />
			<template v-else>
				<NcNoteCard v-if="publishProblems.length" type="error">
					<p>{{ t('opencase', 'Kladden kan ikke udgives endnu:') }}</p>
					<ul class="process-designer__problems">
						<li v-for="(problem, i) in publishProblems" :key="i">
							{{ problem }}
						</li>
					</ul>
				</NcNoteCard>
				<template v-else>
					<NcNoteCard v-if="publishWarnings.length" type="warning">
						<ul class="process-designer__problems">
							<li v-for="(warning, i) in publishWarnings" :key="i">
								{{ warning }}
							</li>
						</ul>
					</NcNoteCard>
					<p>
						{{ process.published_version_no
							? t('opencase', 'Version {no} afløser version {old}. Nye processer startes på den nye version; processer, der allerede kører, fortsætter på den gamle.', { no: currentVersion.version_no, old: process.published_version_no })
							: t('opencase', 'Når versionen er udgivet, kan processen startes på sager, der matcher afgrænsningen.') }}
					</p>
					<label for="process-change-note" class="process-designer__label">{{ t('opencase', 'Hvad er ændret? (valgfri)') }}</label>
					<textarea id="process-change-note"
						v-model="changeNote"
						rows="3"
						class="process-designer__textarea" />
				</template>
				<NcNoteCard v-if="publishError" type="error">
					{{ publishError }}
				</NcNoteCard>
			</template>
			<template #actions>
				<NcButton :disabled="publishing" @click="showPublish = false">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="validating || publishing || publishProblems.length > 0"
					@click="publish">
					<template #icon>
						<NcLoadingIcon v-if="publishing" :size="16" />
					</template>
					{{ t('opencase', 'Udgiv') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { markRaw } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError, showSuccess } from '@nextcloud/dialogs'

import Modeler from 'bpmn-js/lib/Modeler.js'
import NavigatedViewer from 'bpmn-js/lib/NavigatedViewer.js'
import 'bpmn-js/dist/assets/diagram-js.css'
import 'bpmn-js/dist/assets/bpmn-js.css'
import 'bpmn-js/dist/assets/bpmn-font/css/bpmn-embedded.css'

import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PublishIcon from 'vue-material-design-icons/Publish.vue'
import SitemapOutlineIcon from 'vue-material-design-icons/SitemapOutline.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'

import ProcessPropertiesPanel from '../components/ProcessPropertiesPanel.vue'
import ProcessSettingsDialog from '../components/ProcessSettingsDialog.vue'
import ProcessStatisticsDialog from '../components/ProcessStatisticsDialog.vue'
import ProcessMigrationDialog from '../components/ProcessMigrationDialog.vue'
import ChartBarIcon from 'vue-material-design-icons/ChartBar.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import flowableModdle from '../process/flowableModdle.json'
import danishTranslateModule from '../process/danishTranslate.js'
import api from '../services/api.js'

/**
 * Process designer: bpmn-js modeler for the draft version of a process,
 * and a read-only viewer for published and earlier versions.
 */
export default {
	name: 'ProcessDesignerView',

	components: {
		NcButton,
		NcActions,
		NcActionButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		NcNoteCard,
		ArrowLeftIcon,
		CogIcon,
		ContentCopyIcon,
		ContentSaveIcon,
		DeleteIcon,
		DownloadIcon,
		PencilIcon,
		PublishIcon,
		SitemapOutlineIcon,
		UploadIcon,
		ProcessPropertiesPanel,
		ProcessSettingsDialog,
		ProcessStatisticsDialog,
		ProcessMigrationDialog,
		ChartBarIcon,
		SwapHorizontalIcon,
	},

	beforeRouteLeave() {
		if (this.dirty && !window.confirm(t('opencase', 'Kladden har ændringer, der ikke er gemt. Vil du forlade siden alligevel?'))) {
			return false
		}
	},

	props: {
		id: { type: [String, Number], required: true },
	},

	data() {
		return {
			loading: true,
			loadError: '',
			process: null,
			currentVersion: null,
			selectedVersionId: null,
			bpmnInstance: null,
			selectedElement: null,
			elementRevision: 0,
			dirty: false,
			saving: false,
			caseTypes: [],
			orgUnits: [],
			topics: [],
			customFields: [],
			templates: [],
			documentCategories: [],
			otherProcesses: [],
			showSettings: false,
			showStatistics: false,
			showMigration: false,
			showPublish: false,
			validating: false,
			publishing: false,
			publishProblems: [],
			publishWarnings: [],
			publishError: '',
			changeNote: '',
		}
	},

	computed: {
		isDraft() {
			return this.currentVersion?.status === 'draft'
		},
		neverPublished() {
			return this.process?.versions.every(v => !v.published_at)
		},
	},

	async mounted() {
		window.addEventListener('beforeunload', this.onBeforeUnload)
		window.addEventListener('keydown', this.onKeyDown)
		this.loadReferenceData()
		try {
			this.process = await api.getProcess(this.id)
		} catch (e) {
			this.loadError = e.response?.status === 404
				? t('opencase', 'Processen findes ikke')
				: t('opencase', 'Kunne ikke hente processen')
			this.loading = false
			return
		}
		this.loading = false
		const versionId = this.process.draft_version_id || this.process.published_version_id || this.process.versions[0]?.id
		await this.$nextTick()
		await this.openVersion(versionId)
	},

	beforeUnmount() {
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		window.removeEventListener('keydown', this.onKeyDown)
		this.bpmnInstance?.destroy()
	},

	methods: {
		async loadReferenceData() {
			try {
				const [caseTypes, orgs] = await Promise.all([api.getCaseTypes(), api.getOrgTree()])
				this.caseTypes = caseTypes
				this.orgUnits = orgs
			} catch (e) {
				// pickers stay empty; stored values are still shown
			}
			try {
				const [topics, templates, categories, processes] = await Promise.all([
					api.getProcessTopics(),
					api.getTemplates(),
					api.getDocumentCategories(),
					api.getProcesses(),
				])
				this.topics = topics
				this.templates = templates
				this.documentCategories = categories.filter(c => !c.expired)
				this.otherProcesses = processes.filter(p => p.active).map(p => ({ id: p.id, name: p.name }))
			} catch (e) {
				// service task actions can't be chosen until reloaded
			}
			try {
				this.customFields = await api.getCustomFields()
			} catch (e) {
				// "Udfyld tillægsfelt" can't pick a field until reloaded
			}
		},

		async openVersion(versionId) {
			if (!versionId) return
			try {
				this.currentVersion = await api.getProcessVersion(this.process.id, versionId)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente versionen'))
				this.selectedVersionId = this.currentVersion?.id ?? null
				return
			}
			this.selectedVersionId = this.currentVersion.id
			await this.showDiagram(this.currentVersion.bpmn_xml, this.currentVersion.status === 'draft')
		},

		async showDiagram(xml, editable) {
			this.bpmnInstance?.destroy()
			this.selectedElement = null
			this.dirty = false

			const options = {
				container: this.$refs.canvas,
				moddleExtensions: { flowable: flowableModdle },
				// Danish menus and tooltips
				additionalModules: [danishTranslateModule],
			}
			const instance = editable ? new Modeler(options) : new NavigatedViewer(options)
			this.bpmnInstance = markRaw(instance)

			try {
				await instance.importXML(xml)
				instance.get('canvas').zoom('fit-viewport', 'auto')
			} catch (e) {
				showError(t('opencase', 'Diagrammet kunne ikke vises: {error}', { error: e.message }))
			}

			instance.on('selection.changed', ({ newSelection }) => {
				this.selectedElement = newSelection.length === 1 ? markRaw(newSelection[0]) : null
			})
			instance.on('element.changed', ({ element }) => {
				if (element === this.selectedElement) {
					this.elementRevision++
				}
			})
			if (editable) {
				instance.on('commandStack.changed', () => {
					this.dirty = true
				})
			}
		},

		onVersionSelected() {
			if (this.dirty && !window.confirm(t('opencase', 'Kladden har ændringer, der ikke er gemt. Vil du kassere dem?'))) {
				this.selectedVersionId = this.currentVersion.id
				return
			}
			this.openVersion(this.selectedVersionId)
		},

		async save() {
			if (!this.isDraft || this.saving) return true
			this.saving = true
			try {
				const { xml } = await this.bpmnInstance.saveXML({ format: true })
				const saved = await api.saveProcessDraft(this.process.id, this.currentVersion.id, xml, this.currentVersion.checksum)
				this.currentVersion = saved
				this.dirty = false
				return true
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke gemme kladden'))
				return false
			} finally {
				this.saving = false
			}
		},

		async openPublish() {
			if (this.dirty && !(await this.save())) {
				return
			}
			this.publishProblems = []
			this.publishWarnings = []
			this.publishError = ''
			this.changeNote = ''
			this.showPublish = true
			this.validating = true
			try {
				const result = await api.validateProcessDraft(this.process.id, this.currentVersion.id)
				this.publishProblems = result.problems
				this.publishWarnings = result.warnings
			} catch (e) {
				this.publishError = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke kontrollere kladden')
			} finally {
				this.validating = false
			}
		},

		async publish() {
			this.publishing = true
			this.publishError = ''
			try {
				const result = await api.publishProcessDraft(this.process.id, this.currentVersion.id, this.changeNote)
				this.process = result.process
				this.showPublish = false
				showSuccess(t('opencase', 'Version {no} er udgivet', { no: result.version.version_no }))
				await this.openVersion(result.version.id)
			} catch (e) {
				this.publishError = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke udgive kladden')
			} finally {
				this.publishing = false
			}
		},

		async createDraft(fromVersionId) {
			try {
				const draft = await api.createProcessDraft(this.process.id, fromVersionId)
				this.process = await api.getProcess(this.process.id)
				await this.openVersion(draft.id)
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke oprette en kladde'))
			}
		},

		async deleteDraft() {
			if (!window.confirm(t('opencase', 'Vil du slette kladden? Ændringerne i den går tabt.'))) return
			try {
				await api.deleteProcessDraft(this.process.id, this.currentVersion.id)
				this.dirty = false
				this.process = await api.getProcess(this.process.id)
				await this.openVersion(this.process.published_version_id || this.process.versions[0]?.id)
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke slette kladden'))
			}
		},

		async deleteProcess() {
			if (!window.confirm(t('opencase', 'Vil du slette processen "{name}"?', { name: this.process.name }))) return
			try {
				await api.deleteProcess(this.process.id)
				this.dirty = false
				this.$router.push({ name: 'processes' })
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke slette processen'))
			}
		},

		onSettingsSaved(process) {
			this.process = process
			this.showSettings = false
		},

		async exportXml() {
			const { xml } = await this.bpmnInstance.saveXML({ format: true })
			const blob = new Blob([xml], { type: 'application/xml' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `${this.process.process_key}-v${this.currentVersion.version_no}.bpmn`
			a.click()
			URL.revokeObjectURL(url)
		},

		async importXml(event) {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) return
			const xml = await file.text()
			try {
				// Replaces the diagram; the server sets the process id on save
				await this.bpmnInstance.importXML(xml)
				this.bpmnInstance.get('canvas').zoom('fit-viewport', 'auto')
				this.selectedElement = null
				this.dirty = true
			} catch (e) {
				showError(t('opencase', 'Filen kunne ikke indlæses: {error}', { error: e.message }))
			}
		},

		statusLabel(status) {
			return {
				draft: t('opencase', 'kladde'),
				published: t('opencase', 'udgivet'),
				superseded: t('opencase', 'tidligere'),
			}[status] || status
		},

		formatDate(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleString('da-DK', { dateStyle: 'short', timeStyle: 'short' })
		},

		onBeforeUnload(event) {
			if (this.dirty) {
				event.preventDefault()
				event.returnValue = ''
			}
		},

		onKeyDown(event) {
			if ((event.ctrlKey || event.metaKey) && event.key === 's' && this.isDraft) {
				event.preventDefault()
				if (this.dirty) {
					this.save()
				}
			}
		},
	},
}
</script>

<style scoped>
.process-designer {
	padding: 20px;
	display: flex;
	flex-direction: column;
	height: 100%;
	box-sizing: border-box;
}

.process-designer__loading {
	display: block;
	margin: 48px auto;
}

.process-designer__header {
	/* Title above the actions, so nothing sits under the global topbar buttons (top right) */
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}

.process-designer__title {
	display: flex;
	align-items: flex-start;
	gap: 4px;
}

.process-designer__title h2 {
	font-size: 1.4em;
	font-weight: 600;
	margin: 0 0 2px;
	display: flex;
	align-items: center;
	gap: 8px;
}

.process-designer__key {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.process-designer__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.process-designer__version-select {
	min-width: 160px;
	margin: 0;
}

.process-designer__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.6em;
	font-weight: normal;
}

.process-designer__badge--inactive {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.process-designer__status {
	margin: 12px 0;
	color: var(--color-text-lighter);
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
}

.process-designer__unsaved {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: 600;
}

.process-designer__note {
	font-style: italic;
}

.process-designer__body {
	flex: 1;
	display: flex;
	min-height: 480px;
	height: calc(100vh - 270px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background: var(--color-main-background);
}

.process-designer__canvas {
	flex: 1;
	position: relative;
	/* bpmn-js draws dark lines; keep the canvas light also in dark mode */
	background: #fff;
	color: #000;
}

.process-designer__panel {
	width: 300px;
	flex-shrink: 0;
	border-left: 1px solid var(--color-border);
}

.process-designer__file-input {
	display: none;
}

.process-designer__problems {
	margin: 4px 0 0 20px;
	list-style: disc;
}

.process-designer__label {
	display: block;
	font-weight: 600;
	margin: 12px 0 4px;
}

.process-designer__textarea {
	width: 100%;
	resize: vertical;
}
</style>
