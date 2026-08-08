<template>
	<NcDialog :name="t('opencase', 'Ny påmindelse')"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">
		<div class="reminder-dialog">
			<div class="reminder-dialog__field">
				<label for="reminder-title">{{ t('opencase', 'Titel') }} *</label>
				<NcTextField id="reminder-title"
					v-model="form.title"
					:placeholder="t('opencase', 'Beskriv påmindelsen')" />
			</div>

			<div class="reminder-dialog__field">
				<label for="reminder-deadline">{{ t('opencase', 'Frist') }}</label>
				<input id="reminder-deadline"
					v-model="form.deadline"
					type="datetime-local"
					class="reminder-dialog__datetime" />
			</div>

			<div class="reminder-dialog__field">
				<label>{{ t('opencase', 'Ansvarlig') }}</label>
				<div class="reminder-dialog__user-row">
					<NcSelect v-model="selectedUser"
						:options="userOptions"
						:filterable="false"
						:placeholder="t('opencase', 'Søg efter bruger...')"
						:loading="usersLoading"
						class="reminder-dialog__user-select"
						@search="onUserSearch" />
					<NcButton type="secondary" :disabled="!selectedUser" @click="applyUser">
						{{ t('opencase', 'Vælg') }}
					</NcButton>
				</div>
				<span v-if="form.responsibleDisplayName" class="reminder-dialog__chosen-user">
					{{ form.responsibleDisplayName }}
				</span>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !form.title.trim()" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencase', 'Gem') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'ReminderDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon },

	props: {
		entity: {
			type: String,
			required: true, // 'case' | 'document'
		},
		entityKey: {
			type: [String, Number],
			required: true,
		},
	},

	emits: ['close', 'created'],

	data() {
		const currentUser = getCurrentUser()
		return {
			form: {
				title: '',
				deadline: '',
				responsibleUserId: currentUser?.uid ?? '',
				responsibleDisplayName: currentUser?.displayName ?? currentUser?.uid ?? '',
			},
			selectedUser: null,
			userOptions: [],
			usersLoading: false,
			searchTimeout: null,
			saving: false,
		}
	},

	methods: {
		onUserSearch(query) {
			clearTimeout(this.searchTimeout)
			if (!query || query.length < 2) {
				this.userOptions = []
				this.usersLoading = false
				return
			}
			this.usersLoading = true
			this.searchTimeout = setTimeout(async () => {
				try {
					const results = await api.searchCaseworkerUsers(query)
					this.userOptions = results
				} catch {
					this.userOptions = []
				} finally {
					this.usersLoading = false
				}
			}, 300)
		},

		applyUser() {
			if (!this.selectedUser) return
			this.form.responsibleUserId = this.selectedUser.id
			this.form.responsibleDisplayName = this.selectedUser.label
			this.selectedUser = null
			this.userOptions = []
		},

		async save() {
			const title = this.form.title.trim()
			if (!title) return

			this.saving = true
			try {
				const payload = {
					title,
					deadline: this.form.deadline || null,
					responsible_user_id: this.form.responsibleUserId || null,
				}
				const reminder = this.entity === 'document'
					? await api.createDocumentReminder(this.entityKey, payload)
					: await api.createReminder(this.entityKey, payload)
				showSuccess(t('opencase', 'Påmindelse oprettet'))
				this.$emit('created', reminder)
			} catch {
				showError(t('opencase', 'Kunne ikke oprette påmindelse'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.reminder-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.reminder-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.reminder-dialog__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.reminder-dialog__datetime {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	padding: 8px 12px;
	font-size: 0.95em;
	background: var(--color-main-background);
	color: var(--color-main-text);
	width: 100%;
	box-sizing: border-box;
}

.reminder-dialog__datetime:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.reminder-dialog__user-row {
	display: flex;
	gap: 8px;
	align-items: center;
}

.reminder-dialog__user-select {
	flex: 1;
}

.reminder-dialog__chosen-user {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	padding: 2px 0;
}
</style>
