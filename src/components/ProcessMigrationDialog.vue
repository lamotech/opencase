<template>
	<NcDialog :name="t('opencase', 'Flyt kørende processer')"
		size="normal"
		@closing="$emit('close')">
		<NcLoadingIcon v-if="loading" :size="32" />
		<div v-else class="process-migration">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-if="plan && plan.target">
				<p v-if="plan.running === 0">
					{{ t('opencase', 'Alle kørende processer bruger allerede den udgivne version (v{no}).', { no: plan.target.version_no }) }}
				</p>
				<template v-else>
					<p>
						{{ n('opencase',
							'{count} proces kører på en ældre version. Den kan flyttes til den udgivne version (v{no}), så resten af sagsbehandlingen følger den nye version.',
							'{count} processer kører på ældre versioner. De kan flyttes til den udgivne version (v{no}), så resten af sagsbehandlingen følger den nye version.',
							plan.running, { count: plan.running, no: plan.target.version_no }) }}
					</p>
					<div v-for="version in plan.versions" :key="version.version_id" class="process-migration__version">
						<h4>{{ n('opencase', 'v{no}: {count} proces', 'v{no}: {count} processer', version.count, { no: version.version_no, count: version.count }) }}</h4>
						<ul>
							<li v-for="step in version.steps" :key="step.id">
								<span>{{ step.name }} ({{ step.count }})</span>
								<span v-if="step.exists" class="process-migration__ok">{{ t('opencase', 'findes i ny version') }}</span>
								<span v-else class="process-migration__map">
									{{ t('opencase', 'fortsætter ved') }}
									<select v-model="mappings[step.id]">
										<option value="">{{ t('opencase', 'Vælg trin') }}</option>
										<option v-for="target in plan.target_steps" :key="target.id" :value="target.id">
											{{ target.name }}
										</option>
									</select>
								</span>
							</li>
						</ul>
					</div>
					<p class="process-migration__hint">
						{{ t('opencase', 'Åbne opgaver på trin, der findes i begge versioner, bevares. Processer, procesmotoren ikke kan flytte, bliver på deres version.') }}
					</p>
				</template>
			</template>
			<p v-else-if="plan">
				{{ t('opencase', 'Processen har ingen udgivet version.') }}
			</p>

			<template v-if="migrations.length">
				<h4>{{ t('opencase', 'Seneste flytninger') }}</h4>
				<ul class="process-migration__history">
					<li v-for="m in migrations" :key="m.id">
						<strong>v{{ m.to_version_no }}</strong>
						– {{ statusLabel(m) }}
						<span class="process-migration__meta">{{ formatDateTime(m.created_at) }}, {{ m.created_by }}</span>
						<ul v-if="m.errors.length" class="process-migration__errors">
							<li v-for="(e, i) in m.errors" :key="i">
								<router-link v-if="e.case_id" :to="{ path: '/case/' + e.case_id, hash: '#tab-processes' }">
									{{ e.case_number || e.case_id }}
								</router-link>: {{ e.error }}
							</li>
						</ul>
					</li>
				</ul>
			</template>
		</div>

		<template #actions>
			<NcButton :disabled="starting" @click="$emit('close')">
				{{ t('opencase', 'Luk') }}
			</NcButton>
			<NcButton v-if="canStart"
				type="primary"
				:disabled="starting || !allMapped"
				@click="start">
				<template #icon>
					<NcLoadingIcon v-if="starting" :size="16" />
				</template>
				{{ t('opencase', 'Flyt processerne') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'

/**
 * Move the running processes of a process to its published version: shows
 * which steps they are at, asks where processes at steps the new version
 * doesn't have should continue, and follows the progress.
 */
export default {
	name: 'ProcessMigrationDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard },

	props: {
		process: { type: Object, required: true },
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			plan: null,
			migrations: [],
			mappings: {},
			starting: false,
			error: '',
			pollTimer: null,
		}
	},

	computed: {
		unmappedSteps() {
			return (this.plan?.versions || []).flatMap(v => v.steps).filter(s => !s.exists)
		},
		allMapped() {
			return this.unmappedSteps.every(s => !!this.mappings[s.id])
		},
		canStart() {
			return this.plan?.target && this.plan.running > 0 && !this.plan.unfinished
		},
	},

	async mounted() {
		await this.load()
		this.loading = false
	},

	beforeUnmount() {
		clearTimeout(this.pollTimer)
	},

	methods: {
		async load() {
			try {
				const [plan, migrations] = await Promise.all([
					api.getProcessMigrationPlan(this.process.id),
					api.getProcessMigrations(this.process.id),
				])
				this.plan = plan
				this.migrations = migrations
				for (const step of this.unmappedSteps) {
					if (!(step.id in this.mappings)) {
						this.mappings[step.id] = ''
					}
				}
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke hente de kørende processer')
			}
			// A large migration runs in the background: follow its progress
			clearTimeout(this.pollTimer)
			if (this.migrations.some(m => m.status !== 'done')) {
				this.pollTimer = setTimeout(() => this.load(), 5000)
			}
		},

		async start() {
			this.starting = true
			this.error = ''
			try {
				const migration = await api.startProcessMigration(this.process.id, this.mappings)
				if (migration.status === 'done') {
					showSuccess(n('opencase', '{count} proces er flyttet', '{count} processer er flyttet', migration.migrated, { count: migration.migrated }))
				}
				await this.load()
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Processerne kunne ikke flyttes')
			} finally {
				this.starting = false
			}
		},

		statusLabel(m) {
			if (m.status === 'queued') return t('opencase', 'i kø ({total} processer)', { total: m.total })
			if (m.status === 'running') return t('opencase', 'i gang: {done} af {total}', { done: m.migrated + m.failed, total: m.total })
			return m.failed
				? t('opencase', '{migrated} flyttet, {failed} kunne ikke flyttes', { migrated: m.migrated, failed: m.failed })
				: t('opencase', '{migrated} flyttet', { migrated: m.migrated })
		},

		formatDateTime(iso) {
			return iso ? new Date(iso).toLocaleString('da-DK', { dateStyle: 'short', timeStyle: 'short' }) : ''
		},
	},
}
</script>

<style scoped>
.process-migration {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 8px 0;
}

.process-migration p {
	margin: 0;
}

.process-migration h4 {
	margin: 8px 0 4px;
	font-weight: 600;
}

.process-migration__version ul,
.process-migration__history {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.process-migration__version li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.process-migration__ok {
	color: var(--color-success-text, var(--color-success));
	font-size: 0.9em;
}

.process-migration__map {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.9em;
}

.process-migration__hint,
.process-migration__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.process-migration__errors {
	margin: 4px 0 0 16px;
	list-style: disc;
	font-size: 0.9em;
	color: var(--color-error-text, var(--color-error));
}
</style>
