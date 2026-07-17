<template>
	<NcDialog :name="t('opencase', 'Opret workflow')"
		@update:open="v => !v && $emit('close')">
		<div class="wf-create">
			<div class="wf-create__field">
				<label>{{ t('opencase', 'Type') }}</label>
				<div class="wf-create__radios">
					<label>
						<input v-model="type" type="radio" value="review" />
						{{ t('opencase', 'Gennemse') }}
					</label>
					<label>
						<input v-model="type" type="radio" value="approval" />
						{{ t('opencase', 'Godkendelse') }}
					</label>
				</div>
			</div>

			<div class="wf-create__field">
				<label>{{ t('opencase', 'Frist (valgfri)') }}</label>
				<input v-model="deadline"
					type="datetime-local"
					class="wf-create__datetime" />
				<span v-if="deadline" class="wf-create__deadline-preview">
					{{ formatDeadline(deadline) }}
				</span>
			</div>

			<div class="wf-create__field">
				<label>{{ t('opencase', 'Brugere (i rækkefølge)') }}</label>
				<div class="wf-create__user-search">
					<NcSelect v-model="selectedUser"
						:options="userOptions"
						:filterable="false"
						:placeholder="t('opencase', 'Søg efter bruger...')"
						:loading="usersLoading"
						@search="onUserSearch" />
					<NcButton :disabled="!selectedUser" @click="addUser">
						{{ t('opencase', 'Tilføj') }}
					</NcButton>
				</div>

				<div v-if="users.length > 0" class="wf-create__user-list">
					<div v-for="(u, index) in users"
						:key="u.uid"
						class="wf-create__user-item">
						<span class="wf-create__user-order">{{ index + 1 }}</span>
						<span class="wf-create__user-name">{{ u.displayName }}</span>
						<div class="wf-create__user-actions">
							<NcButton :disabled="index === 0"
								type="tertiary"
								@click="moveUp(index)">
								↑
							</NcButton>
							<NcButton :disabled="index === users.length - 1"
								type="tertiary"
								@click="moveDown(index)">
								↓
							</NcButton>
							<NcButton type="tertiary" @click="removeUser(index)">
								✕
							</NcButton>
						</div>
					</div>
				</div>
				<p v-else class="wf-create__hint">
					{{ t('opencase', 'Tilføj mindst én bruger') }}
				</p>
			</div>

			<p v-if="error" class="wf-create__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton :disabled="!canSubmit || saving" type="primary" @click="submit">
				<template v-if="saving">{{ t('opencase', 'Opretter...') }}</template>
				<template v-else>{{ t('opencase', 'Opret workflow') }}</template>
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import api from '../services/api.js'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'CreateWorkflowDialog',
	components: { NcDialog, NcButton, NcSelect },

	props: {
		documentId: { type: [String, Number], required: true },
	},

	emits: ['close', 'created'],

	data() {
		return {
			type: 'review',
			deadline: '',
			users: [],
			selectedUser: null,
			userOptions: [],
			usersLoading: false,
			searchTimeout: null,
			saving: false,
			error: null,
		}
	},

	computed: {
		canSubmit() {
			return this.users.length > 0 && this.type !== ''
		},
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
					// Filter out users already added to the list
					this.userOptions = results.filter(u => !this.users.find(w => w.uid === u.id))
				} catch (e) {
					this.userOptions = []
				} finally {
					this.usersLoading = false
				}
			}, 300)
		},

		addUser() {
			if (!this.selectedUser) return
			const uid = this.selectedUser.id
			if (this.users.find(u => u.uid === uid)) return
			this.users.push({ uid, displayName: this.selectedUser.label })
			this.selectedUser = null
			this.userOptions = []
		},

		removeUser(index) {
			this.users.splice(index, 1)
		},

		moveUp(index) {
			if (index === 0) return
			const tmp = this.users[index - 1]
			this.users[index - 1] = this.users[index]
			this.users[index] = tmp
		},

		moveDown(index) {
			if (index === this.users.length - 1) return
			const tmp = this.users[index + 1]
			this.users[index + 1] = this.users[index]
			this.users[index] = tmp
		},

		formatDeadline(val) {
			if (!val) return ''
			return new Date(val).toLocaleString('da-DK', {
				year: 'numeric', month: 'long', day: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},

		async submit() {
			this.error = null
			this.saving = true
			try {
				const workflow = await api.createWorkflow(this.documentId, {
					type: this.type,
					deadline: this.deadline || null,
					user_ids: this.users.map(u => u.uid),
				})
				this.$emit('created', workflow)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.message || e.message
				showError(this.error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.wf-create {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
}

.wf-create__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.wf-create__field > label {
	font-weight: 600;
	font-size: 0.9em;
}

.wf-create__radios {
	display: flex;
	gap: 16px;
}

.wf-create__radios label {
	font-weight: normal;
	display: flex;
	align-items: center;
	gap: 6px;
}

.wf-create__datetime {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	padding: 6px 10px;
	font-size: 1em;
	background: var(--color-main-background);
	color: var(--color-main-text);
	/* wide enough to always show date + time side by side */
	min-width: 220px;
	width: 100%;
	box-sizing: border-box;
}

.wf-create__deadline-preview {
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.wf-create__user-search {
	display: flex;
	gap: 8px;
	align-items: center;
}

.wf-create__user-search .v-select {
	flex: 1;
}

.wf-create__user-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.wf-create__user-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.wf-create__user-order {
	color: var(--color-text-lighter);
	font-size: 0.85em;
	width: 18px;
	text-align: center;
}

.wf-create__user-name {
	flex: 1;
}

.wf-create__user-actions {
	display: flex;
	gap: 2px;
}

.wf-create__hint {
	color: var(--color-text-lighter);
	font-size: 0.85em;
}

.wf-create__error {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
