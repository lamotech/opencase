<template>
	<NcDialog :name="t('opencase', 'Statistik – {name}', { name: process.name })"
		size="large"
		@closing="$emit('close')">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<div v-else class="process-stats">
			<div class="process-stats__tiles">
				<div class="process-stats__tile">
					<span class="process-stats__value">{{ stats.running }}</span>
					<span>{{ t('opencase', 'Kører') }}</span>
				</div>
				<div class="process-stats__tile">
					<span class="process-stats__value">{{ stats.completed }}</span>
					<span>{{ t('opencase', 'Afsluttet') }}</span>
				</div>
				<div class="process-stats__tile">
					<span class="process-stats__value">{{ stats.cancelled }}</span>
					<span>{{ t('opencase', 'Annulleret') }}</span>
				</div>
				<div class="process-stats__tile" :class="{ 'process-stats__tile--alert': stats.failing > 0 }">
					<span class="process-stats__value">{{ stats.failing }}</span>
					<span>{{ t('opencase', 'Venter på fejlet trin') }}</span>
				</div>
				<div class="process-stats__tile">
					<span class="process-stats__value">{{ formatHours(stats.avg_duration_hours) }}</span>
					<span>{{ t('opencase', 'Gns. varighed') }}</span>
				</div>
			</div>

			<p v-if="stats.running_by_version.length" class="process-stats__versions">
				{{ t('opencase', 'Kørende pr. version:') }}
				<span v-for="v in stats.running_by_version" :key="v.version_no">v{{ v.version_no }}: {{ v.count }}</span>
			</p>

			<h3>{{ t('opencase', 'Opgaver') }}</h3>
			<p v-if="!stats.tasks.length" class="process-stats__empty">
				{{ t('opencase', 'Ingen opgaver endnu.') }}
			</p>
			<table v-else class="process-stats__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Opgave') }}</th>
						<th>{{ t('opencase', 'Åbne') }}</th>
						<th>{{ t('opencase', 'Over frist') }}</th>
						<th>{{ t('opencase', 'Udført') }}</th>
						<th>{{ t('opencase', 'Gns. tid') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="task in stats.tasks" :key="task.activity_id">
						<td>{{ task.name }}</td>
						<td>{{ task.open }}</td>
						<td :class="{ 'process-stats__alert': task.overdue > 0 }">
							{{ task.overdue }}
						</td>
						<td>{{ task.completed }}</td>
						<td>{{ formatHours(task.avg_hours) }}</td>
					</tr>
				</tbody>
			</table>

			<template v-if="stats.steps.length">
				<h3>{{ t('opencase', 'Automatiske trin') }}</h3>
				<table class="process-stats__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Trin') }}</th>
							<th>{{ t('opencase', 'Udført') }}</th>
							<th>{{ t('opencase', 'Fejlet') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="step in stats.steps" :key="step.element_id">
							<td>{{ step.name }}</td>
							<td>{{ step.done }}</td>
							<td :class="{ 'process-stats__alert': step.failed > 0 }">
								{{ step.failed }}
							</td>
						</tr>
					</tbody>
				</table>
			</template>
			<p class="process-stats__hint">
				{{ t('opencase', 'Tallene omfatter alle versioner af processen. Tid regnes fra opgaven blev oprettet, til den blev udført.') }}
			</p>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import api from '../services/api.js'

/**
 * Figures for a process: how many run and have ended, how long they take,
 * and per task and automatic step how much is waiting, overdue or failing.
 */
export default {
	name: 'ProcessStatisticsDialog',

	components: { NcDialog, NcLoadingIcon, NcNoteCard },

	props: {
		process: { type: Object, required: true },
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			error: '',
			stats: null,
		}
	},

	async mounted() {
		try {
			this.stats = await api.getProcessStatistics(this.process.id)
		} catch (e) {
			this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke hente statistik')
		} finally {
			this.loading = false
		}
	},

	methods: {
		formatHours(hours) {
			if (hours === null || hours === undefined) return '–'
			// t() escapes HTML, so no "<" in the text
			if (hours < 1) return t('opencase', 'under 1 time')
			if (hours < 48) return t('opencase', '{n} timer', { n: Math.round(hours) })
			return t('opencase', '{n} dage', { n: Math.round(hours / 24) })
		},
	},
}
</script>

<style scoped>
.process-stats {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.process-stats__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 8px;
}

.process-stats__tile {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
}

.process-stats__tile--alert {
	border-color: var(--color-error);
}

.process-stats__value {
	font-size: 1.6em;
	font-weight: 600;
	color: var(--color-main-text);
}

.process-stats__versions {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.process-stats h3 {
	margin: 8px 0 0;
	font-size: 1em;
	font-weight: 600;
}

.process-stats__table {
	width: 100%;
	border-collapse: collapse;
}

.process-stats__table th,
.process-stats__table td {
	padding: 6px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.process-stats__table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.process-stats__table td:not(:first-child),
.process-stats__table th:not(:first-child) {
	text-align: right;
}

.process-stats__alert {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.process-stats__empty,
.process-stats__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
