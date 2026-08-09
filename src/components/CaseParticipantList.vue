<template>
	<div class="opencase-participant-list">
		<!-- Toolbar -->
		<div v-if="canWrite" class="opencase-participant-list__header">
			<NcButton @click="showAddCompanyDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Tilføj virksomhed') }}
			</NcButton>
			<NcButton @click="showAddCitizenDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Tilføj borger') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent v-else-if="participants.length === 0"
			:title="t('opencase', 'Ingen parter')">
			<template #icon>
				<AccountMultipleIcon :size="48" />
			</template>
		</NcEmptyContent>
		<table v-else class="opencase-participant-list__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Rolle') }}</th>
					<th>{{ t('opencase', 'Navn') }}</th>
					<th>{{ t('opencase', 'CPR/CVR') }}</th>
					<th>{{ t('opencase', 'Adresse') }}</th>
					<th>{{ t('opencase', 'Telefon') }}</th>
					<th>{{ t('opencase', 'E-mail') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="participant in participants" :key="participant.id">
					<td>
						<span class="opencase-participant-list__role">{{ participant.role_name }}</span>
					</td>
					<td>{{ participant.name || '–' }}</td>
					<td class="opencase-participant-list__mono"><CprDisplay :value="participant.cpr_cvr" /></td>
					<td>
						<template v-if="participant.has_address_protection">
							<div v-if="!revealedAddresses[participant.id]"
								class="opencase-participant-list__protected-badge"
								@click="toggleAddress(participant.id)">
								{{ t('opencase', 'Beskyttet adresse') }}
							</div>
							<div v-else
								class="opencase-participant-list__protected-badge"
								@click="toggleAddress(participant.id)">
								{{ formatAddress(participant) }}
							</div>
						</template>
						<template v-else>
							{{ formatAddress(participant) }}
						</template>
					</td>
					<td>{{ participant.phone || '–' }}</td>
					<td>{{ participant.email || '–' }}</td>
					<td>
						<NcActions>
							<NcActionButton @click="deleteParticipant(participant)">
								<template #icon>
									<DeleteIcon :size="20" />
								</template>
								{{ t('opencase', 'Slet') }}
							</NcActionButton>
						</NcActions>
					</td>
				</tr>
			</tbody>
		</table>

		<AddCompanyToCaseDialog v-if="showAddCompanyDialog && companySearchEnabled"
			:case-id="caseId"
			@added="onAdded"
			@close="showAddCompanyDialog = false" />

		<AddCompanyManualToCaseDialog v-else-if="showAddCompanyDialog"
			:case-id="caseId"
			@added="onAdded"
			@close="showAddCompanyDialog = false" />

		<AddCitizenToCaseDialog v-if="showAddCitizenDialog && citizenSearchEnabled"
			:case-id="caseId"
			@added="onAdded"
			@close="showAddCitizenDialog = false" />

		<AddCitizenManualToCaseDialog v-else-if="showAddCitizenDialog"
			:case-id="caseId"
			@added="onAdded"
			@close="showAddCitizenDialog = false" />
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

import AddCompanyToCaseDialog from './AddCompanyToCaseDialog.vue'
import AddCompanyManualToCaseDialog from './AddCompanyManualToCaseDialog.vue'
import AddCitizenToCaseDialog from './AddCitizenToCaseDialog.vue'
import AddCitizenManualToCaseDialog from './AddCitizenManualToCaseDialog.vue'
import CprDisplay from './CprDisplay.vue'
import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import eventBus from '../utils/eventBus.js'

export default {
	name: 'CaseParticipantList',

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		NcActions,
		NcActionButton,
		AccountMultipleIcon,
		PlusIcon,
		DeleteIcon,
		AddCompanyToCaseDialog,
		AddCompanyManualToCaseDialog,
		AddCitizenToCaseDialog,
		AddCitizenManualToCaseDialog,
		CprDisplay,
	},

	props: {
		caseId: { type: [String, Number], default: null },
		canWrite: { type: Boolean, default: false },
	},

	data() {
		return {
			participants: [],
			loading: false,
			showAddCompanyDialog: false,
			showAddCitizenDialog: false,
			revealedAddresses: {},
		}
	},

	computed: {
		citizenSearchEnabled() {
			return this.$store.state.citizenSearchEnabled
		},
		companySearchEnabled() {
			return this.$store.state.companySearchEnabled
		},
	},

	mounted() {
		this._aiHandler = (caseId) => {
			if (String(caseId) === String(this.caseId)) this.load()
		}
		eventBus.on('ai:participants-changed', this._aiHandler)
	},

	beforeUnmount() {
		eventBus.off('ai:participants-changed', this._aiHandler)
	},

	watch: {
		caseId: {
			immediate: true,
			handler() { if (this.caseId) this.load() },
		},
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.participants = await api.getCaseParticipants(this.caseId)
				this.$emit('count-changed', this.participants.length)
			} finally {
				this.loading = false
			}
		},

		onAdded(participant) {
			this.participants.push(participant)
			this.$emit('count-changed', this.participants.length)
		},

		async deleteParticipant(participant) {
			if (!confirm(t('opencase', 'Er du sikker på, at du vil slette denne part?'))) return
			if (!this.caseId) {
				this.participants = this.participants.filter(p => p.id !== participant.id)
				this.$emit('count-changed', this.participants.length)
				return
			}
			try {
				await api.deleteCaseParticipant(this.caseId, participant.id)
				this.participants = this.participants.filter(p => p.id !== participant.id)
				this.$emit('count-changed', this.participants.length)
				showSuccess(t('opencase', 'Part slettet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke slette part'))
			}
		},

		formatAddress(participant) {
			const street = [participant.streetname, participant.housenumber, participant.floor, participant.door]
				.filter(Boolean).join(' ')
			const city = [participant.zipcode, participant.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
		},

		toggleAddress(id) {
			const revealing = !this.revealedAddresses[id]
			this.revealedAddresses[id] = revealing
			if (revealing) {
				const participant = this.participants.find(p => p.id === id)
				if (participant) {
					api.logAddressProtection(participant.cpr_cvr, this.formatAddress(participant)).catch(() => {})
				}
			}
		},
	},
}
</script>

<style scoped>
.opencase-participant-list__header {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-bottom: 12px;
}

.opencase-participant-list__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.95em;
}

.opencase-participant-list__table th,
.opencase-participant-list__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.opencase-participant-list__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.opencase-participant-list__table tbody tr:hover {
	background: var(--color-background-hover);
}

.opencase-participant-list__role {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
	font-weight: 600;
	white-space: nowrap;
}

.opencase-participant-list__mono {
	font-family: monospace;
}

.opencase-participant-list__protected-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	background: #fce4ec;
	color: #c62828;
	border: none;
	cursor: pointer;
	margin-right: 6px;
}

.opencase-participant-list__protected-badge:hover {
	opacity: 0.85;
}
</style>
