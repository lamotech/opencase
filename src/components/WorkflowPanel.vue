<template>
	<div class="wf-panel">
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="workflow">
			<div class="wf-panel__header">
				<div class="wf-panel__meta">
					<span class="wf-panel__badge" :class="'wf-panel__badge--' + workflow.status">
						{{ statusLabel(workflow.status) }}
					</span>
					<span class="wf-panel__type">{{ typeLabel(workflow.type) }}</span>
				</div>
				<div v-if="workflow.deadline" class="wf-panel__deadline">
					<ClockOutlineIcon :size="14" />
					{{ t('opencase', 'Frist: {date}', { date: formatDate(workflow.deadline) }) }}
				</div>
				<NcButton v-if="workflow.status === 'active' && canCancel"
					type="tertiary"
					class="wf-panel__cancel"
					@click="cancelWorkflow">
					{{ t('opencase', 'Annuller workflow') }}
				</NcButton>
			</div>

			<div class="wf-panel__steps">
				<div v-for="step in workflow.steps"
					:key="step.id"
					class="wf-panel__step"
					:class="'wf-panel__step--' + step.status">
					<div class="wf-panel__step-icon">
						<CheckCircleIcon v-if="step.status === 'approved' || step.status === 'reviewed'" :size="18" />
						<CloseCircleIcon v-else-if="step.status === 'rejected'" :size="18" />
						<CircleOutlineIcon v-else :size="18" />
					</div>
					<div class="wf-panel__step-body">
						<span class="wf-panel__step-name">
							{{ step.sort_order }}. {{ step.display_name }}
						</span>
						<span v-if="step.deadline" class="wf-panel__step-deadline">
							{{ t('opencase', 'Frist: {date}', { date: formatDate(step.deadline) }) }}
						</span>
						<span v-if="step.status !== 'pending'" class="wf-panel__step-status">
							{{ stepStatusLabel(step.status) }}
							<template v-if="step.acted_at">· {{ formatDate(step.acted_at) }}</template>
						</span>
						<span v-if="step.comment" class="wf-panel__step-comment">
							"{{ step.comment }}"
						</span>
					</div>
					<NcButton v-if="isMyActiveStep(step)"
						type="primary"
						@click="openAction">
						{{ t('opencase', 'Handling') }}
					</NcButton>
				</div>
			</div>
		</template>

		<NcEmptyContent v-else
			:title="t('opencase', 'Intet aktivt workflow')"
			:description="t('opencase', 'Start et gennemse- eller godkendelsesworkflow')">
			<template #icon>
				<WorkflowIcon :size="48" />
			</template>
			<template v-if="canWrite" #action>
				<NcButton type="primary" @click="showCreate = true">
					{{ t('opencase', 'Opret workflow') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-if="pastWorkflows.length > 0" class="wf-panel__history">
			<h3 class="wf-panel__history-title">
				{{ t('opencase', 'Tidligere workflows') }}
			</h3>
			<div v-for="wf in pastWorkflows" :key="wf.id" class="wf-panel__history-item">
				<div class="wf-panel__history-header">
					<span class="wf-panel__badge" :class="'wf-panel__badge--' + wf.status">
						{{ statusLabel(wf.status) }}
					</span>
					<span class="wf-panel__type">{{ typeLabel(wf.type) }}</span>
					<span class="wf-panel__history-date">{{ formatDate(wf.created_at) }}</span>
				</div>
				<div class="wf-panel__history-steps">
					<span v-for="step in wf.steps" :key="step.id"
						class="wf-panel__history-step"
						:class="'wf-panel__history-step--' + step.status"
						:title="step.display_name + ' · ' + stepStatusLabel(step.status)">
						<CheckCircleIcon v-if="step.status === 'approved' || step.status === 'reviewed'" :size="14" />
						<CloseCircleIcon v-else-if="step.status === 'rejected'" :size="14" />
						<CircleOutlineIcon v-else :size="14" />
					</span>
				</div>
			</div>
		</div>

		<CreateWorkflowDialog v-if="showCreate"
			:document-id="documentId"
			@close="showCreate = false"
			@created="onCreated" />

		<WorkflowActionDialog v-if="showAction && workflow"
			:workflow-id="workflow.id"
			:workflow-type="workflow.type"
			@close="showAction = false"
			@acted="onActed" />
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircleIcon from 'vue-material-design-icons/CloseCircle.vue'
import CircleOutlineIcon from 'vue-material-design-icons/CircleOutline.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import WorkflowIcon from 'vue-material-design-icons/Sitemap.vue'

import CreateWorkflowDialog from './CreateWorkflowDialog.vue'
import WorkflowActionDialog from './WorkflowActionDialog.vue'
import api from '../services/api.js'
import { getCurrentUser } from '@nextcloud/auth'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'WorkflowPanel',
	components: {
		NcLoadingIcon, NcEmptyContent, NcButton,
		CheckCircleIcon, CloseCircleIcon, CircleOutlineIcon, ClockOutlineIcon, WorkflowIcon,
		CreateWorkflowDialog, WorkflowActionDialog,
	},

	props: {
		documentId: { type: [String, Number], required: true },
		canWrite: { type: Boolean, default: false },
	},

	data() {
		return {
			workflow: null,
			history: [],
			loading: false,
			showCreate: false,
			showAction: false,
		}
	},

	computed: {
		currentUserId() {
			return getCurrentUser()?.uid ?? ''
		},

		canCancel() {
			return this.workflow?.created_by === this.currentUserId
		},

		pastWorkflows() {
			return this.history.filter(wf => wf.status !== 'active')
		},
	},

	watch: {
		documentId: { immediate: true, handler() { this.load() } },
	},

	methods: {
		async load() {
			this.loading = true
			try {
				const [workflow, history] = await Promise.all([
					api.getActiveWorkflow(this.documentId).catch(() => null),
					api.getWorkflowHistory(this.documentId).catch(() => []),
				])
				this.workflow = workflow
				this.history = history ?? []
			} finally {
				this.loading = false
			}
		},

		isMyActiveStep(step) {
			return (
				this.workflow?.status === 'active'
				&& step.status === 'pending'
				&& step.user_id === this.currentUserId
				&& this.workflow.steps.find(s => s.status === 'pending')?.id === step.id
			)
		},

		openAction() {
			this.showAction = true
		},

		onCreated(workflow) {
			this.workflow = workflow
			this.showCreate = false
			showSuccess(t('opencase', 'Workflow oprettet'))
			this.$emit('workflow-changed', workflow)
			api.getWorkflowHistory(this.documentId).then(h => { this.history = h ?? [] }).catch(() => {})
		},

		async onActed() {
			this.showAction = false
			showSuccess(t('opencase', 'Handling registreret'))
			await this.load()
			this.$emit('workflow-changed', this.workflow)
		},

		async cancelWorkflow() {
			try {
				await api.cancelWorkflow(this.workflow.id)
				showSuccess(t('opencase', 'Workflow annulleret'))
				this.workflow = null
				this.$emit('workflow-changed', null)
				api.getWorkflowHistory(this.documentId).then(h => { this.history = h ?? [] }).catch(() => {})
			} catch (e) {
				showError(t('opencase', 'Kunne ikke annullere workflow'))
			}
		},

		statusLabel(status) {
			switch (status) {
			case 'active':    return t('opencase', 'Aktiv')
			case 'completed': return t('opencase', 'Fuldført')
			case 'rejected':  return t('opencase', 'Afvist')
			case 'cancelled': return t('opencase', 'Annulleret')
			default:          return status
			}
		},

		typeLabel(type) {
			return type === 'review'
				? t('opencase', 'Gennemse')
				: t('opencase', 'Godkendelse')
		},

		stepStatusLabel(status) {
			switch (status) {
			case 'reviewed': return t('opencase', 'Gennemset')
			case 'approved': return t('opencase', 'Godkendt')
			case 'rejected': return t('opencase', 'Afvist')
			default:         return status
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
.wf-panel {
	padding: 8px 0;
}

.wf-panel__header {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-bottom: 16px;
}

.wf-panel__meta {
	display: flex;
	align-items: center;
	gap: 10px;
}

.wf-panel__badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 600;
}

.wf-panel__badge--active    { background: var(--color-primary-element); color: #fff; }
.wf-panel__badge--completed { background: #d4edda; color: #155724; }
.wf-panel__badge--rejected  { background: #f8d7da; color: #721c24; }
.wf-panel__badge--cancelled { background: var(--color-background-dark); color: var(--color-text-lighter); }

.wf-panel__type {
	font-size: 0.9em;
	color: var(--color-text-lighter);
}

.wf-panel__deadline {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.wf-panel__cancel {
	align-self: flex-start;
	color: #c62828;
    background-color: var(--color-primary-element-light);
    border-color: var(--color-primary-element-light-hover);	
}

.wf-panel__steps {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.wf-panel__step {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 12px;
	border-radius: 6px;
	background: var(--color-background-hover);
}

.wf-panel__step--approved { border-left: 3px solid #28a745; }
.wf-panel__step--reviewed { border-left: 3px solid #28a745; }
.wf-panel__step--rejected { border-left: 3px solid var(--color-error); }
.wf-panel__step--pending  { border-left: 3px solid var(--color-border-dark); }

.wf-panel__step-icon {
	flex-shrink: 0;
	margin-top: 2px;
}

.wf-panel__step--approved .wf-panel__step-icon,
.wf-panel__step--reviewed .wf-panel__step-icon { color: #28a745; }
.wf-panel__step--rejected .wf-panel__step-icon { color: var(--color-error); }
.wf-panel__step--pending  .wf-panel__step-icon { color: var(--color-text-lighter); }

.wf-panel__step-body {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.wf-panel__step-name {
	font-weight: 600;
	font-size: 0.95em;
}

.wf-panel__step-deadline,
.wf-panel__step-status {
	font-size: 0.8em;
	color: var(--color-text-lighter);
}

.wf-panel__step-comment {
	font-size: 0.85em;
	font-style: italic;
	color: var(--color-text-lighter);
	margin-top: 2px;
}

.wf-panel__history {
	margin-top: 28px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.wf-panel__history-title {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-lighter);
	margin: 0 0 12px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.wf-panel__history-item {
	padding: 10px 12px;
	border-radius: 6px;
	background: var(--color-background-hover);
	margin-bottom: 8px;
}

.wf-panel__history-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.wf-panel__history-date {
	font-size: 0.8em;
	color: var(--color-text-lighter);
	margin-left: auto;
}

.wf-panel__history-steps {
	display: flex;
	gap: 4px;
}

.wf-panel__history-step {
	display: flex;
	align-items: center;
}

.wf-panel__history-step--approved,
.wf-panel__history-step--reviewed { color: #28a745; }
.wf-panel__history-step--rejected  { color: var(--color-error); }
.wf-panel__history-step--pending   { color: var(--color-text-lighter); }
</style>
