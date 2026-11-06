<template>
	<div class="case-processes">
		<div class="case-processes__header">
			<p class="case-processes__intro">
				{{ t('opencase', 'Processer styrer sagsbehandlingen trin for trin. Opgaverne vises her og under Foreslået arbejde på forsiden.') }}
			</p>
			<NcButton v-if="canStart"
				type="primary"
				@click="openStartDialog">
				<template #icon>
					<PlayIcon :size="16" />
				</template>
				{{ t('opencase', 'Start proces') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="case-processes__loading" />

		<NcEmptyContent v-else-if="processes.length === 0"
			:name="t('opencase', 'Ingen processer på sagen')">
			<template #icon>
				<SitemapOutlineIcon :size="64" />
			</template>
			<template #description>
				{{ canStart
					? t('opencase', 'Start en proces for at komme i gang.')
					: t('opencase', 'Der er ingen processer, der kan startes på denne sag.') }}
			</template>
		</NcEmptyContent>

		<template v-else>
			<div v-for="process in processes"
				:key="process.id"
				class="case-processes__card">
				<div class="case-processes__card-header">
					<div>
						<h3>
							{{ process.name }}
							<span class="case-processes__version">v{{ process.version_no }}</span>
							<span class="case-processes__badge" :class="'case-processes__badge--' + process.status">
								{{ statusLabel(process.status) }}
							</span>
						</h3>
						<p class="case-processes__meta">
							{{ t('opencase', 'Startet {date} af {user}', { date: formatDateTime(process.started_at), user: process.started_by_name || '' }) }}
							<template v-if="process.status === 'completed'">
								· {{ t('opencase', 'Afsluttet {date}', { date: formatDateTime(process.ended_at) }) }}
							</template>
							<template v-else-if="process.status === 'cancelled'">
								· {{ t('opencase', 'Annulleret {date}', { date: formatDateTime(process.ended_at) }) }}<template v-if="process.ended_by_name">
									{{ t('opencase', 'af {user}', { user: process.ended_by_name }) }}
								</template><template v-if="process.end_reason">
									: {{ process.end_reason }}
								</template>
							</template>
						</p>
					</div>
					<div class="case-processes__card-actions">
						<NcButton type="tertiary" @click="toggleDiagram(process)">
							<template #icon>
								<SitemapOutlineIcon :size="16" />
							</template>
							{{ diagrams[process.id] ? t('opencase', 'Skjul diagram') : t('opencase', 'Vis diagram') }}
						</NcButton>
						<NcButton v-if="canWrite && process.status === 'running'"
							type="tertiary"
							@click="cancelling = process">
							<template #icon>
								<CloseCircleOutlineIcon :size="16" />
							</template>
							{{ t('opencase', 'Annullér') }}
						</NcButton>
					</div>
				</div>

				<NcNoteCard v-if="process.error_message" type="error">
					<p>{{ t('opencase', 'Et automatisk trin fejlede, og processen venter: {error}', { error: process.error_message }, undefined, { escape: false }) }}</p>
					<NcButton v-if="canWrite"
						:disabled="retryingId === process.id"
						@click="retry(process)">
						<template #icon>
							<NcLoadingIcon v-if="retryingId === process.id" :size="16" />
							<RefreshIcon v-else :size="16" />
						</template>
						{{ t('opencase', 'Prøv igen') }}
					</NcButton>
				</NcNoteCard>
				<NcNoteCard v-else-if="process.retrying_step" type="warning">
					<p>{{ t('opencase', 'Det automatiske trin "{step}" fejlede {date} og prøves igen automatisk: {error}', { step: process.retrying_step.name, date: formatDateTime(process.retrying_step.failed_at), error: process.retrying_step.error || '' }, undefined, { escape: false }) }}</p>
				</NcNoteCard>

				<ProcessDiagramViewer v-if="diagrams[process.id]"
					class="case-processes__diagram"
					:xml="diagrams[process.id].bpmn_xml"
					:active-activities="diagrams[process.id].active_activities"
					:completed-activities="diagrams[process.id].completed_activities" />

				<table v-if="openTasks(process).length" class="case-processes__tasks">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Opgave') }}</th>
							<th>{{ t('opencase', 'Ansvarlig') }}</th>
							<th>{{ t('opencase', 'Frist') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="task in openTasks(process)" :key="task.id">
							<td class="case-processes__task-name">
								{{ task.name }}
							</td>
							<td>
								<template v-if="task.assignee_name">
									{{ task.assignee_name }}
								</template>
								<span v-else class="case-processes__unassigned">
									{{ task.candidate_orgs.length
										? t('opencase', 'Ikke taget – {orgs}', { orgs: task.candidate_orgs.map(o => o.name).join(', ') })
										: t('opencase', 'Ikke taget') }}
								</span>
							</td>
							<td :class="{ 'case-processes__overdue': isOverdue(task.due_date) }">
								{{ task.due_date ? formatDate(task.due_date) : '–' }}
							</td>
							<td class="case-processes__task-actions">
								<NcButton v-if="task.can_claim"
									:disabled="busyTaskId === task.id"
									@click="claim(task)">
									{{ t('opencase', 'Tag opgaven') }}
								</NcButton>
								<NcButton v-if="task.can_complete"
									type="primary"
									:disabled="busyTaskId === task.id"
									@click="completing = task">
									{{ t('opencase', 'Udfør') }}
								</NcButton>
								<NcButton v-if="task.can_release"
									type="tertiary"
									:disabled="busyTaskId === task.id"
									@click="release(task)">
									{{ t('opencase', 'Slip') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<details v-if="history(process).length" class="case-processes__history">
					<summary>{{ t('opencase', 'Udførte trin ({count})', { count: history(process).length }) }}</summary>
					<ul>
						<li v-for="item in history(process)" :key="item.key">
							<!-- Automatic step -->
							<template v-if="item.step">
								<strong>{{ item.step.name }}</strong>
								<span class="case-processes__auto">{{ t('opencase', 'Automatisk') }}</span>
								<template v-if="item.step.status === 'failed'">
									– <span class="case-processes__overdue">{{ t('opencase', 'fejlede {date}: {error}', { date: formatDateTime(item.step.finished_at || item.step.created_at), error: item.step.error || '' }) }}</span>
								</template>
								<template v-else-if="item.step.status === 'done'">
									– {{ t('opencase', '{action} som {user} {date}', { action: item.step.action, user: item.step.acted_as_name || '', date: formatDateTime(item.step.finished_at) }) }}
								</template>
								<template v-else-if="item.step.status === 'waiting' && item.step.wait">
									– {{ t('opencase', 'venter til {until} (seneste aktivitet {last})', { until: formatDateTime(item.step.wait.waiting_until), last: formatDateTime(item.step.wait.last_activity) }) }}
								</template>
								<template v-else-if="item.step.status === 'waiting'">
									– {{ t('opencase', 'venter') }}
								</template>
								<template v-else>
									– {{ t('opencase', 'kører') }}
								</template>
								<router-link v-if="item.step.linked_case" :to="{ path: '/case/' + item.step.linked_case.id }" class="case-processes__link">
									→ {{ item.step.linked_case.case_number }}
								</router-link>
								<p v-if="item.step.status === 'done' && item.step.wait && item.step.wait.inactive_since" class="case-processes__comment">
									{{ t('opencase', 'Ingen aktivitet siden {date}', { date: formatDateTime(item.step.wait.inactive_since) }) }}
								</p>
								<p v-if="item.step.info" class="case-processes__comment">
									{{ item.step.info }}
								</p>
								<p v-if="item.step.warning" class="case-processes__warning">
									{{ item.step.warning }}
								</p>
							</template>
							<template v-else>
								<strong>{{ item.task.name }}</strong>
								<template v-if="item.task.status === 'cancelled'">
									– {{ t('opencase', 'afbrudt {date}', { date: formatDateTime(item.task.completed_at) }) }}
								</template>
								<template v-else>
									– {{ item.task.completed_by_name
										? t('opencase', 'udført af {user} {date}', { user: item.task.completed_by_name, date: formatDateTime(item.task.completed_at) })
										: t('opencase', 'udført {date}', { date: formatDateTime(item.task.completed_at) }) }}
								</template>
								<span v-if="item.task.outcome_label" class="case-processes__outcome">{{ item.task.outcome_label }}</span>
								<dl v-if="item.task.form_values && item.task.form_values.length" class="case-processes__values">
									<template v-for="v in item.task.form_values" :key="v.label">
										<dt>{{ v.label }}</dt>
										<dd>{{ v.value }}</dd>
									</template>
								</dl>
								<p v-if="item.task.comment" class="case-processes__comment">
									{{ item.task.comment }}
								</p>
							</template>
						</li>
					</ul>
				</details>
			</div>
		</template>

		<!-- Start process -->
		<NcDialog v-if="showStartDialog"
			:name="t('opencase', 'Start proces')"
			size="normal"
			@closing="showStartDialog = false">
			<div class="case-processes__start">
				<NcCheckboxRadioSwitch v-for="p in available"
					:key="p.id"
					v-model="selectedProcessId"
					:value="String(p.id)"
					name="case-process-start"
					type="radio">
					<strong>{{ p.name }}</strong>
					<span v-if="p.description" class="case-processes__start-description">{{ p.description }}</span>
				</NcCheckboxRadioSwitch>
				<NcNoteCard v-if="startError" type="error">
					{{ startError }}
				</NcNoteCard>
			</div>
			<template #actions>
				<NcButton :disabled="starting" @click="showStartDialog = false">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="primary" :disabled="!selectedProcessId || starting" @click="startProcess">
					<template #icon>
						<NcLoadingIcon v-if="starting" :size="16" />
					</template>
					{{ t('opencase', 'Start') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Cancel process -->
		<NcDialog v-if="cancelling"
			:name="t('opencase', 'Annullér {name}', { name: cancelling.name })"
			size="normal"
			@closing="cancelling = null">
			<p>{{ t('opencase', 'Processen stoppes, og dens åbne opgaver lukkes. Det kan ikke fortrydes.') }}</p>
			<label for="case-process-cancel-reason" class="case-processes__label">{{ t('opencase', 'Begrundelse') }} *</label>
			<textarea id="case-process-cancel-reason"
				v-model="cancelReason"
				rows="3"
				class="case-processes__textarea" />
			<template #actions>
				<NcButton :disabled="cancellingBusy" @click="cancelling = null">
					{{ t('opencase', 'Fortryd') }}
				</NcButton>
				<NcButton type="error" :disabled="!cancelReason.trim() || cancellingBusy" @click="cancelProcess">
					{{ t('opencase', 'Annullér processen') }}
				</NcButton>
			</template>
		</NcDialog>

		<ProcessTaskDialog v-if="completing"
			:task="completing"
			@close="completing = null"
			@completed="onTaskCompleted" />
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showError, showSuccess } from '@nextcloud/dialogs'

import CloseCircleOutlineIcon from 'vue-material-design-icons/CloseCircleOutline.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import SitemapOutlineIcon from 'vue-material-design-icons/SitemapOutline.vue'

import ProcessTaskDialog from './ProcessTaskDialog.vue'
import api from '../services/api.js'

/**
 * "Processer" tab on a case: processes started on the case, their tasks,
 * and starting/cancelling processes.
 */
export default {
	name: 'CaseProcessPanel',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		CloseCircleOutlineIcon,
		PlayIcon,
		RefreshIcon,
		SitemapOutlineIcon,
		ProcessTaskDialog,
		// bpmn-js is large; only load it when a diagram is shown
		ProcessDiagramViewer: defineAsyncComponent(() => import('./ProcessDiagramViewer.vue')),
	},

	props: {
		caseId: { type: [String, Number], required: true },
		canWrite: { type: Boolean, default: false },
		isClosed: { type: Boolean, default: false },
	},

	// case-changed: automatic steps have run, so the case, its documents and
	// journal notes may have changed
	emits: ['count-changed', 'case-changed'],

	data() {
		return {
			loading: true,
			processes: [],
			available: [],
			diagrams: {},
			showStartDialog: false,
			selectedProcessId: '',
			starting: false,
			startError: '',
			cancelling: null,
			cancelReason: '',
			cancellingBusy: false,
			retryingId: null,
			// ids of automatic steps seen done, to notice new ones (null until first load)
			doneStepIds: null,
			pollTimer: null,
			completing: null,
			busyTaskId: null,
		}
	},

	computed: {
		canStart() {
			return this.canWrite && !this.isClosed && this.available.length > 0
		},
	},

	watch: {
		caseId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		clearTimeout(this.pollTimer)
	},

	methods: {
		async load() {
			try {
				const data = await api.getCaseProcesses(this.caseId)
				this.processes = data.processes
				this.available = data.available
				this.checkForNewSteps()
				this.schedulePoll()
				this.$emit('count-changed', this.processes.filter(p => p.status === 'running').length || null)
				// Keep open diagrams up to date
				for (const id of Object.keys(this.diagrams)) {
					this.loadDiagram(Number(id))
				}
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente processer'))
			} finally {
				this.loading = false
			}
		},

		openTasks(process) {
			return process.tasks.filter(t => t.status === 'open')
		},

		/**
		 * Tell the case page when automatic steps have run since the last
		 * load — inline after an action here, or by the background job.
		 */
		/**
		 * While a running process has no open task, it is waiting for an
		 * automatic step or a timer handled in the background: look again
		 * shortly, so the result shows without reloading the page.
		 */
		schedulePoll() {
			clearTimeout(this.pollTimer)
			// Not while a step waits (for inactivity, a document or an approval)
			const waiting = this.processes.some(p => p.status === 'running'
				&& !p.tasks.some(t => t.status === 'open')
				&& !(p.steps || []).some(st => st.status === 'waiting'))
			if (waiting) {
				this.pollTimer = setTimeout(() => this.load(), 15000)
			}
		},

		checkForNewSteps() {
			const done = new Set(this.processes.flatMap(p => (p.steps || []).filter(s => s.status === 'done').map(s => s.id)))
			const previous = this.doneStepIds
			this.doneStepIds = done
			if (previous !== null && [...done].some(id => !previous.has(id))) {
				this.$emit('case-changed')
			}
		},

		/**
		 * Completed and cancelled tasks plus automatic steps, newest first.
		 *
		 * @param {object} process a process from the API
		 */
		history(process) {
			const tasks = process.tasks.filter(t => t.status !== 'open')
				.map(t => ({ key: 't' + t.id, at: t.completed_at || t.created_at, task: t }))
			const steps = (process.steps || [])
				.map(s => ({ key: 's' + s.id, at: s.finished_at || s.created_at, step: s }))
			return [...tasks, ...steps].sort((a, b) => new Date(b.at) - new Date(a.at))
		},

		async retry(process) {
			this.retryingId = process.id
			try {
				await api.retryCaseProcess(this.caseId, process.id)
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Trinnet kunne ikke prøves igen'))
			} finally {
				this.retryingId = null
				await this.load()
			}
		},

		async toggleDiagram(process) {
			if (this.diagrams[process.id]) {
				delete this.diagrams[process.id]
				return
			}
			await this.loadDiagram(process.id)
		},

		async loadDiagram(processId) {
			try {
				this.diagrams[processId] = await api.getCaseProcessDiagram(this.caseId, processId)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente diagrammet'))
			}
		},

		openStartDialog() {
			this.selectedProcessId = this.available.length === 1 ? String(this.available[0].id) : ''
			this.startError = ''
			this.showStartDialog = true
		},

		async startProcess() {
			this.starting = true
			this.startError = ''
			try {
				await api.startCaseProcess(this.caseId, Number(this.selectedProcessId))
				this.showStartDialog = false
				showSuccess(t('opencase', 'Processen er startet'))
				await this.load()
			} catch (e) {
				this.startError = e.response?.data?.ocs?.data?.error || t('opencase', 'Processen kunne ikke startes')
			} finally {
				this.starting = false
			}
		},

		async cancelProcess() {
			this.cancellingBusy = true
			try {
				await api.cancelCaseProcess(this.caseId, this.cancelling.id, this.cancelReason)
				this.cancelling = null
				this.cancelReason = ''
				await this.load()
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Processen kunne ikke annulleres'))
			} finally {
				this.cancellingBusy = false
			}
		},

		async claim(task) {
			await this.taskAction(task, () => api.claimProcessTask(task.id), t('opencase', 'Opgaven kunne ikke tages'))
		},

		async release(task) {
			await this.taskAction(task, () => api.releaseProcessTask(task.id), t('opencase', 'Opgaven kunne ikke slippes'))
		},

		async taskAction(task, action, errorMessage) {
			this.busyTaskId = task.id
			try {
				await action()
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || errorMessage)
			} finally {
				this.busyTaskId = null
				await this.load()
			}
		},

		async onTaskCompleted() {
			this.completing = null
			showSuccess(t('opencase', 'Opgaven er udført'))
			await this.load()
		},

		statusLabel(status) {
			return {
				running: t('opencase', 'Kører'),
				completed: t('opencase', 'Afsluttet'),
				cancelled: t('opencase', 'Annulleret'),
			}[status] || status
		},

		isOverdue(iso) {
			return !!iso && new Date(iso) < new Date()
		},

		formatDate(iso) {
			return iso ? new Date(iso).toLocaleDateString('da-DK') : ''
		},

		formatDateTime(iso) {
			return iso ? new Date(iso).toLocaleString('da-DK', { dateStyle: 'short', timeStyle: 'short' }) : ''
		},
	},
}
</script>

<style scoped>
.case-processes {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.case-processes__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.case-processes__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.case-processes__loading {
	display: block;
	margin: 24px auto;
}

.case-processes__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.case-processes__card-header {
	display: flex;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.case-processes__card-header h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}

.case-processes__version {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.case-processes__meta {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-processes__card-actions {
	display: flex;
	gap: 4px;
	align-items: flex-start;
}

.case-processes__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.75em;
	font-weight: normal;
}

.case-processes__badge--running {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.case-processes__badge--completed {
	background: var(--color-success);
	color: var(--color-primary-element-text, #fff);
}

.case-processes__badge--cancelled {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.case-processes__tasks {
	width: 100%;
	border-collapse: collapse;
}

.case-processes__tasks th,
.case-processes__tasks td {
	padding: 6px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.case-processes__tasks th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-processes__task-name {
	font-weight: 600;
}

.case-processes__task-actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}

.case-processes__unassigned {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.case-processes__overdue {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.case-processes__history summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.case-processes__history ul {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.case-processes__outcome {
	margin-left: 8px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-size: 0.85em;
}

.case-processes__auto {
	margin-left: 6px;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.8em;
}

.case-processes__values {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 12px;
	margin: 4px 0 0;
	font-size: 0.9em;
}

.case-processes__values dt {
	color: var(--color-text-maxcontrast);
}

.case-processes__values dd {
	margin: 0;
}

.case-processes__link {
	margin-left: 6px;
}

.case-processes__warning {
	margin: 2px 0 0;
	color: var(--color-warning-text, var(--color-warning));
}

.case-processes__comment {
	margin: 2px 0 0;
	color: var(--color-text-lighter);
	white-space: pre-wrap;
}

.case-processes__start {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 0;
}

.case-processes__start-description {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-processes__label {
	display: block;
	font-weight: 600;
	margin: 12px 0 4px;
}

.case-processes__textarea {
	width: 100%;
	resize: vertical;
}
</style>
