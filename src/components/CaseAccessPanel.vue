<template>
	<div class="opencase-access">
		<!-- Toolbar -->
		<div class="opencase-access__header">
			<NcButton v-if="canManage"
				type="primary"
				@click="showGrantDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Giv adgang') }}
			</NcButton>
		</div>

		<!-- Access profile table -->
		<div v-if="caseData" class="opencase-access__section">
			<h3 class="opencase-access__section-title">{{ t('opencase', 'Adgangsprofil') }}</h3>
			<table class="opencase-access__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Organisation') }}</th>
						<th>{{ t('opencase', 'KLE Nummer') }}</th>
						<th>{{ t('opencase', 'Følsomhed') }}</th>
						<th>{{ t('opencase', 'Antal brugere') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr :class="{ 'opencase-access__row--clickable': !profileUsersLoading }"
						@click="openProfileUsersDialog">
						<td>{{ caseData.organisation || '–' }}</td>
						<td>
							<template v-if="caseData.classification_code">
								{{ caseData.classification_code }}<template v-if="caseData.classification_title"> – {{ caseData.classification_title }}</template>
							</template>
							<template v-else>–</template>
						</td>
						<td>{{ caseData.sensitivity_title || caseData.sensitivity_key || '–' }}</td>
						<td>
							<button v-if="profileUsersLoading" class="opencase-access__count-btn" disabled>
								<NcLoadingIcon :size="16" />
							</button>
							<button v-else
								class="opencase-access__count-btn opencase-access__count-btn--clickable"
								@click.stop="openProfileUsersDialog">
								{{ profileUserCount }}
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<!-- Assigned users table -->
			<div class="opencase-access__section">
				<h3 class="opencase-access__section-title">{{ t('opencase', 'Tildelte brugere') }}</h3>
				<table class="opencase-access__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Bruger') }}</th>
							<th>{{ t('opencase', 'Rolle') }}</th>
							<th>{{ t('opencase', 'Adgang') }}</th>
							<th>{{ t('opencase', 'Udløber') }}</th>
							<th v-if="canManage" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="grant in grants" :key="grant.user_id"
							:class="{ 'opencase-access__row--expired': grant.is_expired }">
							<td class="opencase-access__name">
								{{ grant.display_name }}
								<span class="opencase-access__uid">{{ grant.user_id }}</span>
							</td>
							<td>
								<span v-if="grant.is_responsible" class="opencase-access__badge opencase-access__badge--responsible">
									{{ t('opencase', 'Ansvarlig') }}
								</span>
								<span v-else-if="grant.is_caseworker" class="opencase-access__badge opencase-access__badge--caseworker">
									{{ t('opencase', 'Sagsbehandler') }}
								</span>
								<span v-else class="opencase-access__badge opencase-access__badge--direct">
									{{ t('opencase', 'Direkte') }}
								</span>
							</td>
							<td>
								<span v-if="grant.can_write" class="opencase-access__badge opencase-access__badge--write">
									{{ t('opencase', 'Skriv') }}
								</span>
								<span v-else class="opencase-access__badge opencase-access__badge--read">
									{{ t('opencase', 'Læs') }}
								</span>
							</td>
							<td class="opencase-access__expires">
								<span v-if="grant.is_expired" class="opencase-access__expired-label">
									{{ t('opencase', 'Udløbet') }}
								</span>
								<span v-else-if="grant.expires_at">
									{{ formatDate(grant.expires_at) }}
								</span>
								<span v-else class="opencase-access__permanent">
									{{ t('opencase', 'Permanent') }}
								</span>
							</td>
							<td v-if="canManage" class="opencase-access__actions">
								<NcButton v-if="!grant.is_responsible && !grant.is_caseworker"
									type="error"
									:title="t('opencase', 'Fjern adgang')"
									@click="revoke(grant)">
									<template #icon>
										<CloseIcon :size="18" />
									</template>
								</NcButton>
							</td>
						</tr>

						<tr v-if="grants.length === 0">
							<td :colspan="canManage ? 5 : 4" class="opencase-access__empty">
								{{ t('opencase', 'Ingen adgangsrettigheder tildelt') }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<GrantAccessDialog v-if="showGrantDialog"
			:case-id="caseId"
			@close="showGrantDialog = false"
			@granted="onGranted" />

		<!-- Profile users dialog -->
		<NcDialog v-if="showProfileUsersDialog"
			:name="t('opencase', 'Brugere med adgangsprofil')"
			size="large"
			@closing="showProfileUsersDialog = false">
			<div class="opencase-access__dialog-content">
				<table class="opencase-access__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Bruger') }}</th>
							<th>{{ t('opencase', 'Adgang') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="u in profileUsers" :key="u.user_id">
							<td class="opencase-access__name">
								{{ u.display_name }}
								<span class="opencase-access__uid">{{ u.username }}</span>
							</td>
							<td>
								<span v-if="u.access_level === 'write'" class="opencase-access__badge opencase-access__badge--write">
									{{ t('opencase', 'Skriv') }}
								</span>
								<span v-else class="opencase-access__badge opencase-access__badge--read">
									{{ t('opencase', 'Læs') }}
								</span>
							</td>
						</tr>
						<tr v-if="profileUsers.length === 0">
							<td colspan="2" class="opencase-access__empty">
								{{ t('opencase', 'Ingen brugere tildelt denne adgangsprofil') }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../services/api.js'
import GrantAccessDialog from './GrantAccessDialog.vue'

export default {
	name: 'CaseAccessPanel',
	components: { NcButton, NcLoadingIcon, NcDialog, PlusIcon, CloseIcon, GrantAccessDialog },

	props: {
		caseId:    { type: Number, required: true },
		canManage: { type: Boolean, default: false },
		caseData:  { type: Object, default: null },
	},

	data() {
		return {
			grants: [],
			loading: false,
			showGrantDialog: false,
			profileUsers: [],
			profileUserCount: 0,
			profileUsersLoading: false,
			showProfileUsersDialog: false,
		}
	},

	mounted() {
		this.loadGrants()
		this.loadProfileUsers()
	},

	methods: {
		async loadGrants() {
			this.loading = true
			try {
				this.grants = await api.getCaseGrants(this.caseId)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse adgangsrettigheder'))
			} finally {
				this.loading = false
			}
		},

		async loadProfileUsers() {
			this.profileUsersLoading = true
			try {
				const result = await api.getCaseProfileUsers(this.caseId)
				this.profileUsers = result.users ?? []
				this.profileUserCount = result.count ?? 0
			} catch (e) {
				this.profileUsers = []
				this.profileUserCount = 0
			} finally {
				this.profileUsersLoading = false
			}
		},

		openProfileUsersDialog() {
			if (this.profileUsersLoading) return
			this.showProfileUsersDialog = true
		},

		async revoke(grant) {
			try {
				await api.revokeCaseAccess(this.caseId, grant.user_id)
				this.grants = this.grants.filter(g => g.user_id !== grant.user_id)
				showSuccess(t('opencase', 'Adgang fjernet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke fjerne adgang'))
			}
		},

		onGranted(grant) {
			this.showGrantDialog = false
			const idx = this.grants.findIndex(g => g.user_id === grant.user_id)
			if (idx >= 0) {
				this.grants[idx] = grant
			} else {
				this.grants.push(grant)
			}
			showSuccess(t('opencase', 'Adgang tildelt'))
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-access__header {
	margin-bottom: 16px;
}

.opencase-access__section {
	margin-bottom: 28px;
}

.opencase-access__section-title {
	font-size: 0.85em;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-maxcontrast);
	margin: 0 0 10px 0;
}

.opencase-access__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.95em;
}

.opencase-access__table th {
	text-align: left;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
	padding: 0 12px 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.opencase-access__table td {
	padding: 10px 12px 10px 0;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: middle;
}

.opencase-access__row--clickable {
	cursor: pointer;
}

.opencase-access__row--clickable:hover td {
	background: var(--color-background-hover);
}

.opencase-access__row--expired td {
	opacity: 0.5;
}

.opencase-access__name {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-access__uid {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.opencase-access__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
}

.opencase-access__badge--responsible {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.opencase-access__badge--caseworker {
	background: #e8f4e8;
	color: #2d6a2d;
}

.opencase-access__badge--direct {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.opencase-access__badge--write {
	background: #d4edda;
	color: #155724;
}

.opencase-access__badge--read {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.opencase-access__permanent {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.opencase-access__expired-label {
	color: var(--color-error);
	font-size: 0.85em;
}

.opencase-access__actions {
	text-align: right;
}

.opencase-access__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 24px 0 !important;
}

.opencase-access__count-btn {
	background: none;
	border: none;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	cursor: default;
}

.opencase-access__count-btn--clickable {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	cursor: pointer;
	transition: opacity 0.15s;
}

.opencase-access__count-btn--clickable:hover {
	opacity: 0.8;
}

.opencase-access__dialog-content {
	padding: 8px 0;
	overflow: hidden;
}
</style>
