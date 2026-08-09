<template>
	<div class="reminder-list">
		<div class="reminder-list__header">
			<h3 class="reminder-list__title">
				{{ t('opencase', 'Påmindelser') }}
				<span v-if="reminders.length > 0" class="reminder-list__count">({{ reminders.length }})</span>
			</h3>
		</div>

		<NcLoadingIcon v-if="loading" :size="28" />

		<p v-else-if="reminders.length === 0" class="reminder-list__empty">
			{{ t('opencase', 'Ingen påmindelser') }}
		</p>

		<div v-else class="reminder-list__items">
			<div v-for="reminder in reminders"
				:key="reminder.id"
				class="reminder-list__item"
				:class="{ 'reminder-list__item--completed': reminder.status === 'completed' }">
				<div class="reminder-list__item-main">
					<span class="reminder-list__item-title">{{ reminder.title }}</span>
					<span v-if="reminder.deadline" class="reminder-list__item-deadline"
						:class="{ 'reminder-list__item-deadline--overdue': isOverdue(reminder) }">
						<CalendarClockIcon :size="14" />
						{{ formatDate(reminder.deadline) }}
					</span>
					<span class="reminder-list__item-responsible">
						<AccountIcon :size="14" />
						{{ reminder.responsible_user ? reminder.responsible_user.display_name : '–' }}
					</span>
				</div>

				<div v-if="isResponsible(reminder)" class="reminder-list__item-actions">
					<NcButton v-if="reminder.status === 'active'"
						type="success"
						size="small"
						:disabled="updating === reminder.id"
						@click="markCompleted(reminder)">
						<template #icon>
							<CheckIcon :size="16" />
						</template>
						{{ t('opencase', 'Afslut') }}
					</NcButton>
					<NcButton v-else
						type="secondary"
						size="small"
						:disabled="updating === reminder.id"
						@click="markActive(reminder)">
						{{ t('opencase', 'Genåbn') }}
					</NcButton>

					<div class="reminder-list__reassign">
						<NcSelect v-model="reassignSelections[reminder.id]"
							:options="userOptions[reminder.id] || []"
							:filterable="false"
							:placeholder="t('opencase', 'Overfør til...')"
							:loading="userSearchLoading[reminder.id]"
							class="reminder-list__reassign-select"
							@search="q => onUserSearch(reminder.id, q)" />
						<NcButton type="secondary"
							size="small"
							:disabled="!reassignSelections[reminder.id] || updating === reminder.id"
							@click="reassign(reminder)">
							{{ t('opencase', 'Overfør') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'ReminderList',

	components: { NcButton, NcLoadingIcon, NcSelect, AccountIcon, CalendarClockIcon, CheckIcon },

	props: {
		reminders: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['updated'],

	data() {
		return {
			updating: null,
			reassignSelections: {},
			userOptions: {},
			userSearchLoading: {},
			searchTimeouts: {},
		}
	},

	computed: {
		currentUserId() {
			return getCurrentUser()?.uid ?? ''
		},
	},

	methods: {
		isResponsible(reminder) {
			return reminder.responsible_user_id === this.currentUserId
		},

		isOverdue(reminder) {
			if (!reminder.deadline || reminder.status === 'completed') return false
			return new Date(reminder.deadline) < new Date()
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},

		async markCompleted(reminder) {
			this.updating = reminder.id
			try {
				const updated = await api.updateReminder(reminder.id, { status: 'completed' })
				this.$emit('updated', updated)
				showSuccess(t('opencase', 'Påmindelse afsluttet'))
			} catch {
				showError(t('opencase', 'Kunne ikke opdatere påmindelse'))
			} finally {
				this.updating = null
			}
		},

		async markActive(reminder) {
			this.updating = reminder.id
			try {
				const updated = await api.updateReminder(reminder.id, { status: 'active' })
				this.$emit('updated', updated)
				showSuccess(t('opencase', 'Påmindelse genåbnet'))
			} catch {
				showError(t('opencase', 'Kunne ikke opdatere påmindelse'))
			} finally {
				this.updating = null
			}
		},

		async reassign(reminder) {
			const selected = this.reassignSelections[reminder.id]
			if (!selected) return
			this.updating = reminder.id
			try {
				const updated = await api.updateReminder(reminder.id, {
					responsible_user_id: selected.id,
				})
				this.$emit('updated', updated)
				this.reassignSelections[reminder.id] = null
				this.userOptions[reminder.id] = []
				showSuccess(t('opencase', 'Påmindelse overført'))
			} catch {
				showError(t('opencase', 'Kunne ikke overføre påmindelse'))
			} finally {
				this.updating = null
			}
		},

		onUserSearch(reminderId, query) {
			clearTimeout(this.searchTimeouts[reminderId])
			if (!query || query.length < 2) {
				this.userOptions[reminderId] = []
				this.userSearchLoading[reminderId] = false
				return
			}
			this.userSearchLoading[reminderId] = true
			this.searchTimeouts[reminderId] = setTimeout(async () => {
				try {
					const results = await api.searchCaseworkerUsers(query)
					this.userOptions[reminderId] = results
				} catch {
					this.userOptions[reminderId] = []
				} finally {
					this.userSearchLoading[reminderId] = false
				}
			}, 300)
		},
	},
}
</script>

<style scoped>
.reminder-list {
	margin-top: 24px;
}

.reminder-list__header {
	display: flex;
	align-items: center;
	margin-bottom: 10px;
}

.reminder-list__title {
	font-size: 1em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin: 0;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.reminder-list__count {
	font-weight: 400;
	margin-left: 4px;
}

.reminder-list__empty {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin: 0;
}

.reminder-list__items {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.reminder-list__item {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 12px 14px;
	background: var(--color-main-background);
	transition: background 0.15s;
}

.reminder-list__item--completed {
	opacity: 0.6;
}

.reminder-list__item-main {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.reminder-list__item-title {
	font-weight: 600;
	flex: 1 1 200px;
}

.reminder-list__item-deadline {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.reminder-list__item-deadline--overdue {
	color: var(--color-error);
	font-weight: 600;
}

.reminder-list__item-responsible {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.875em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.reminder-list__item-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.reminder-list__reassign {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 1;
}

.reminder-list__reassign-select {
	min-width: 180px;
	flex: 1;
}
</style>
