<template>
	<div class="opencase-caseworker-list">
		<div v-if="canWrite" class="opencase-caseworker-list__header">
			<NcButton @click="showAddDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Tilføj sagsbehandler') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent v-else-if="caseworkers.length === 0"
			:title="t('opencase', 'Ingen sagsbehandlere')">
			<template #icon>
				<AccountMultipleIcon :size="48" />
			</template>
		</NcEmptyContent>
		<table v-else class="opencase-caseworker-list__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Bruger') }}</th>
					<th>{{ t('opencase', 'Rolle') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="cw in caseworkers" :key="cw.user_id">
					<td>
						<div class="opencase-caseworker-list__user">
							{{ cw.display_name }}
							<span class="opencase-caseworker-list__uid">{{ cw.user_id }}</span>
						</div>
					</td>
					<td>
						<span :class="['opencase-caseworker-list__badge', cw.role === 'responsible' ? 'opencase-caseworker-list__badge--responsible' : 'opencase-caseworker-list__badge--other']">
							{{ cw.role === 'responsible' ? t('opencase', 'Ansvarlig') : t('opencase', 'Anden sagsbehandler') }}
						</span>
					</td>
					<td class="opencase-caseworker-list__actions">
						<NcButton v-if="cw.role !== 'responsible'"
							type="error"
							:title="t('opencase', 'Fjern sagsbehandler')"
							@click="removeCaseworker(cw)">
							<template #icon>
								<CloseIcon :size="18" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Add caseworker dialog -->
		<NcDialog v-if="showAddDialog"
			:name="t('opencase', 'Tilføj sagsbehandler')"
			@update:open="v => !v && (showAddDialog = false)">
			<div class="opencase-caseworker-list__dialog-body">
				<div class="opencase-caseworker-list__field">
					<label>{{ t('opencase', 'Bruger') }} *</label>
					<NcSelect v-model="selectedUser"
						:options="userOptions"
						:loading="userSearchLoading"
						:filterable="false"
						:placeholder="t('opencase', 'Søg efter bruger')"
						@search="onUserSearch" />
					<span v-if="addError" class="opencase-caseworker-list__error">{{ addError }}</span>
				</div>
			</div>
			<template #actions>
				<NcButton @click="showAddDialog = false">{{ t('opencase', 'Annuller') }}</NcButton>
				<NcButton type="primary" :disabled="saving" @click="addCaseworker">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('opencase', 'Tilføj') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'CaseCaseworkerList',

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		NcDialog,
		NcSelect,
		AccountMultipleIcon,
		PlusIcon,
		CloseIcon,
	},

	props: {
		caseId: { type: [String, Number], required: true },
		canWrite: { type: Boolean, default: false },
	},

	data() {
		return {
			caseworkers: [],
			loading: false,
			showAddDialog: false,
			selectedUser: null,
			userOptions: [],
			userSearchLoading: false,
			userSearchTimeout: null,
			saving: false,
			addError: '',
		}
	},

	watch: {
		caseId: {
			immediate: true,
			handler() { this.load() },
		},
		showAddDialog(val) {
			if (!val) {
				this.selectedUser = null
				this.userOptions = []
				this.addError = ''
			}
		},
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.caseworkers = await api.getCaseworkers(this.caseId)
				this.$emit('count-changed', this.caseworkers.filter(c => c.role !== 'responsible').length)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse sagsbehandlere'))
			} finally {
				this.loading = false
			}
		},

		onUserSearch(query) {
			clearTimeout(this.userSearchTimeout)
			if (!query || query.length < 2) return
			this.userSearchLoading = true
			this.userSearchTimeout = setTimeout(async () => {
				try {
					this.userOptions = await api.searchCaseworkerUsers(query)
				} catch (e) {
					// silently ignore
				} finally {
					this.userSearchLoading = false
				}
			}, 300)
		},

		async addCaseworker() {
			this.addError = ''
			if (!this.selectedUser) {
				this.addError = t('opencase', 'Vælg en bruger')
				return
			}
			this.saving = true
			try {
				const cw = await api.addCaseworker(this.caseId, this.selectedUser.id)
				this.caseworkers.push(cw)
				this.$emit('count-changed', this.caseworkers.filter(c => c.role !== 'responsible').length)
				this.showAddDialog = false
				showSuccess(t('opencase', 'Sagsbehandler tilføjet'))
			} catch (e) {
				const msg = e.response?.data?.ocs?.data?.error
				this.addError = msg || t('opencase', 'Kunne ikke tilføje sagsbehandler')
			} finally {
				this.saving = false
			}
		},

		async removeCaseworker(cw) {
			if (!confirm(t('opencase', 'Er du sikker på, at du vil fjerne denne sagsbehandler?'))) return
			try {
				await api.removeCaseworker(this.caseId, cw.user_id)
				this.caseworkers = this.caseworkers.filter(c => c.user_id !== cw.user_id)
				this.$emit('count-changed', this.caseworkers.filter(c => c.role !== 'responsible').length)
				showSuccess(t('opencase', 'Sagsbehandler fjernet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke fjerne sagsbehandler'))
			}
		},
	},
}
</script>

<style scoped>
.opencase-caseworker-list__header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}

.opencase-caseworker-list__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.95em;
}

.opencase-caseworker-list__table th {
	text-align: left;
	font-size: 0.85em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-lighter);
	padding: 0 12px 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.opencase-caseworker-list__table td {
	padding: 10px 12px 10px 0;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: middle;
}

.opencase-caseworker-list__table tbody tr:hover {
	background: var(--color-background-hover);
}

.opencase-caseworker-list__user {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-caseworker-list__uid {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.opencase-caseworker-list__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
}

.opencase-caseworker-list__badge--responsible {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.opencase-caseworker-list__badge--other {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.opencase-caseworker-list__actions {
	text-align: right;
}

.opencase-caseworker-list__dialog-body {
	padding: 8px 0;
}

.opencase-caseworker-list__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.opencase-caseworker-list__field > label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-caseworker-list__error {
	color: var(--color-error);
	font-size: 0.85em;
}
</style>
