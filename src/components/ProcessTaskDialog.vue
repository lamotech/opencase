<template>
	<NcDialog :name="task.name || t('opencase', 'Udfør opgave')"
		size="normal"
		@closing="$emit('close')">
		<div class="process-task-dialog">
			<p v-if="task.due_date" class="process-task-dialog__due">
				{{ t('opencase', 'Frist: {date}', { date: formatDate(task.due_date) }) }}
			</p>

			<template v-if="task.outcomes.length">
				<label class="process-task-dialog__label">{{ t('opencase', 'Udfald') }} *</label>
				<div class="process-task-dialog__outcomes">
					<NcCheckboxRadioSwitch v-for="o in task.outcomes"
						:key="o.id"
						v-model="outcome"
						:value="o.id"
						name="process-task-outcome"
						type="radio">
						{{ o.name }}
					</NcCheckboxRadioSwitch>
				</div>
			</template>

			<div v-for="field in task.form_fields" :key="field.id" class="process-task-dialog__field">
				<NcCheckboxRadioSwitch v-if="field.type === 'boolean'" v-model="values[field.id]">
					{{ field.name }}
				</NcCheckboxRadioSwitch>
				<template v-else>
					<label :for="'process-task-field-' + field.id" class="process-task-dialog__label">
						{{ field.name }}<template v-if="field.required">
							*
						</template>
					</label>
					<NcSelect v-if="field.type === 'user'"
						:input-id="'process-task-field-' + field.id"
						:model-value="userOption(field.id)"
						:options="userOptions"
						:filterable="false"
						:loading="searchingUsers"
						:placeholder="t('opencase', 'Søg efter navn eller brugernavn')"
						@search="onUserSearch"
						@update:model-value="v => values[field.id] = v?.id || ''" />
					<select v-else-if="field.type === 'enum'"
						:id="'process-task-field-' + field.id"
						v-model="values[field.id]">
						<option value="" />
						<option v-for="o in field.options" :key="o.id" :value="o.id">
							{{ o.name }}
						</option>
					</select>
					<input v-else
						:id="'process-task-field-' + field.id"
						v-model="values[field.id]"
						:type="field.type === 'long' ? 'number' : field.type === 'date' ? 'date' : 'text'"
						:step="field.type === 'long' ? 1 : undefined">
				</template>
			</div>

			<label for="process-task-comment" class="process-task-dialog__label">{{ t('opencase', 'Bemærkning (valgfri)') }}</label>
			<textarea id="process-task-comment"
				v-model="comment"
				rows="3"
				class="process-task-dialog__textarea" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="saving || (task.outcomes.length > 0 && !outcome) || missingRequired"
				@click="complete">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="16" />
				</template>
				{{ t('opencase', 'Udfør') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import api from '../services/api.js'

/**
 * Complete a process task: choose its outcome (when the task has
 * outcomes) and add an optional comment.
 */
export default {
	name: 'ProcessTaskDialog',

	components: { NcDialog, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect },

	props: {
		task: { type: Object, required: true },
	},

	emits: ['close', 'completed'],

	data() {
		return {
			// Booleans start as "no"; other fields empty
			values: Object.fromEntries((this.task.form_fields || []).map(f => [f.id, f.type === 'boolean' ? false : ''])),
			outcome: '',
			comment: '',
			saving: false,
			error: '',
			// Employee fields: search results, and the chosen employees by user id
			userOptions: [],
			chosenUsers: {},
			searchingUsers: false,
		}
	},

	computed: {
		missingRequired() {
			return (this.task.form_fields || []).some(f => f.required && f.type !== 'boolean' && String(this.values[f.id] ?? '').trim() === '')
		},
	},

	methods: {
		userOption(fieldId) {
			const uid = this.values[fieldId]
			return uid ? (this.chosenUsers[uid] || this.userOptions.find(o => o.id === uid) || { id: uid, label: uid }) : null
		},
		onUserSearch(query) {
			clearTimeout(this.searchTimer)
			if (!query || query.trim().length < 2) return
			this.searchTimer = setTimeout(async () => {
				this.searchingUsers = true
				try {
					const employees = await api.searchEmployees({ q: query.trim() })
					this.userOptions = employees
						.filter(e => e.user_id)
						.map(e => ({ id: e.user_id, label: `${e.personname || e.username} (${e.user_id})` }))
					for (const o of this.userOptions) this.chosenUsers[o.id] ??= o
				} catch (e) {
					this.userOptions = []
				} finally {
					this.searchingUsers = false
				}
			}, 300)
		},
		async complete() {
			this.saving = true
			this.error = ''
			try {
				const task = await api.completeProcessTask(this.task.id, this.outcome || null, this.comment, this.values)
				this.$emit('completed', task)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Opgaven kunne ikke udføres')
			} finally {
				this.saving = false
			}
		},
		formatDate(iso) {
			return new Date(iso).toLocaleDateString('da-DK')
		},
	},
}
</script>

<style scoped>
.process-task-dialog {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;
}

.process-task-dialog__due {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.process-task-dialog__label {
	font-weight: 600;
	margin-top: 8px;
}

.process-task-dialog__outcomes {
	display: flex;
	flex-direction: column;
}

.process-task-dialog__field {
	display: flex;
	flex-direction: column;
}

.process-task-dialog__field input,
.process-task-dialog__field select {
	width: 100%;
	box-sizing: border-box;
}

.process-task-dialog__textarea {
	width: 100%;
	resize: vertical;
}
</style>
