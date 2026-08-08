<template>
	<div class="opencase-contact-list">
		<!-- Toolbar -->
		<div v-if="canWrite" class="opencase-contact-list__header">
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
		<NcEmptyContent v-else-if="contacts.length === 0"
			:title="t('opencase', 'Ingen afsendere eller modtagere')">
			<template #icon>
				<AccountMultipleIcon :size="48" />
			</template>
		</NcEmptyContent>
		<table v-else class="opencase-contact-list__table">
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
				<tr v-for="contact in contacts" :key="contact.id">
					<td>
						<span class="opencase-contact-list__role">{{ contact.role_name }}</span>
					</td>
					<td>{{ contact.name || '–' }}</td>
					<td class="opencase-contact-list__mono"><CprDisplay :value="contact.cpr_cvr" /></td>
					<td>
						<template v-if="contact.has_address_protection">
							<div v-if="!revealedAddresses[contact.id]"
								class="opencase-contact-list__protected-badge"
								@click="toggleAddress(contact.id)">
								{{ t('opencase', 'Beskyttet adresse') }}
							</div>
							<div v-else
								class="opencase-contact-list__protected-badge"
								@click="toggleAddress(contact.id)">
								{{ formatAddress(contact) }}
							</div>
						</template>
						<template v-else>
							{{ formatAddress(contact) }}
						</template>
					</td>
					<td>{{ contact.phone || '–' }}</td>
					<td>{{ contact.email || '–' }}</td>
					<td>
						<NcActions>
							<NcActionButton @click="deleteContact(contact)">
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

		<AddCompanyDialog v-if="showAddCompanyDialog && companySearchEnabled"
			:document-id="documentId"
			@added="onAdded"
			@close="showAddCompanyDialog = false" />

		<AddCompanyManualDialog v-else-if="showAddCompanyDialog"
			:document-id="documentId"
			@added="onAdded"
			@close="showAddCompanyDialog = false" />

		<AddCitizenDialog v-if="showAddCitizenDialog && citizenSearchEnabled"
			:document-id="documentId"
			@added="onAdded"
			@close="showAddCitizenDialog = false" />

		<AddCitizenManualDialog v-else-if="showAddCitizenDialog"
			:document-id="documentId"
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

import AddCompanyDialog from './AddCompanyDialog.vue'
import AddCompanyManualDialog from './AddCompanyManualDialog.vue'
import AddCitizenDialog from './AddCitizenDialog.vue'
import AddCitizenManualDialog from './AddCitizenManualDialog.vue'
import CprDisplay from './CprDisplay.vue'
import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import eventBus from '../utils/eventBus.js'

export default {
	name: 'DocumentContactList',

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		NcActions,
		NcActionButton,
		AccountMultipleIcon,
		PlusIcon,
		DeleteIcon,
		AddCompanyDialog,
		AddCompanyManualDialog,
		AddCitizenDialog,
		AddCitizenManualDialog,
		CprDisplay,
	},

	props: {
		documentId: { type: [String, Number], default: null },
		canWrite: { type: Boolean, default: false },
	},

	data() {
		return {
			contacts: [],
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

	watch: {
		documentId: {
			immediate: true,
			handler() { if (this.documentId) this.load() },
		},
	},

	mounted() {
		this._aiContactsHandler = (docId) => {
			if (String(docId) === String(this.documentId)) this.load()
		}
		eventBus.on('ai:contacts-changed', this._aiContactsHandler)
	},

	beforeUnmount() {
		eventBus.off('ai:contacts-changed', this._aiContactsHandler)
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.contacts = await api.getDocumentContacts(this.documentId)
				this.$emit('count-changed', this.contacts.length)
			} finally {
				this.loading = false
			}
		},

		onAdded(contact) {
			this.contacts.push(contact)
			this.$emit('count-changed', this.contacts.length)
		},

		async deleteContact(contact) {
			if (!confirm(t('opencase', 'Er du sikker på, at du vil slette denne kontakt?'))) return
			if (!this.documentId) {
				this.contacts = this.contacts.filter(c => c.id !== contact.id)
				this.$emit('count-changed', this.contacts.length)
				return
			}
			try {
				await api.deleteDocumentContact(this.documentId, contact.id)
				this.contacts = this.contacts.filter(c => c.id !== contact.id)
				this.$emit('count-changed', this.contacts.length)
				showSuccess(t('opencase', 'Kontakt slettet'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke slette kontakt'))
			}
		},

		formatAddress(contact) {
			const street = [contact.streetname, contact.housenumber, contact.floor, contact.door]
				.filter(Boolean).join(' ')
			const city = [contact.zipcode, contact.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
		},

		toggleAddress(id) {
			const revealing = !this.revealedAddresses[id]
			this.revealedAddresses[id] = revealing
			if (revealing) {
				const contact = this.contacts.find(c => c.id === id)
				if (contact) {
					api.logAddressProtection(contact.cpr_cvr, this.formatAddress(contact)).catch(() => {})
				}
			}
		},
	},
}
</script>

<style scoped>
.opencase-contact-list__header {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-bottom: 12px;
}

.opencase-contact-list__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.95em;
}

.opencase-contact-list__table th,
.opencase-contact-list__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.opencase-contact-list__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.opencase-contact-list__table tbody tr:hover {
	background: var(--color-background-hover);
}

.opencase-contact-list__role {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
	font-weight: 600;
	white-space: nowrap;
}

.opencase-contact-list__mono {
	font-family: monospace;
}

.opencase-contact-list__protected-badge {
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

.opencase-contact-list__protected-badge:hover {
	opacity: 0.85;
}
</style>
