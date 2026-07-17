<template>
	<NcDialog :name="t('opencase', 'Digital post')"
		size="large"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-dp-dialog">
			<!-- Kan besvares -->
			<div class="opencase-dp-dialog__option">
				<NcCheckboxRadioSwitch v-model="canBeAnswered">
					{{ t('opencase', 'Kan besvares') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Filer -->
			<div class="opencase-dp-dialog__section">
				<h3 class="opencase-dp-dialog__section-title">{{ t('opencase', 'Filer') }}</h3>

				<NcLoadingIcon v-if="filesLoading" :size="32" class="opencase-dp-dialog__loading" />

				<p v-else-if="files.length === 0" class="opencase-dp-dialog__empty">
					{{ t('opencase', 'Ingen filer') }}
				</p>

				<table v-else class="opencase-dp-dialog__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Hoveddokument') }}</th>
							<th>{{ t('opencase', 'Filnavn') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="file in files"
							:key="file.id"
							class="opencase-dp-dialog__file-row"
							@click="mainFileId = file.id">
							<td class="opencase-dp-dialog__radio-cell">
								<input type="radio"
									v-model="mainFileId"
									:value="file.id"
									class="opencase-dp-dialog__radio"
									@click.stop />
							</td>
							<td>{{ file.virtual_filename || file.original_filename }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Modtagere -->
			<div class="opencase-dp-dialog__section">
				<h3 class="opencase-dp-dialog__section-title">{{ t('opencase', 'Modtagere') }}</h3>

				<NcLoadingIcon v-if="receiversLoading" :size="32" class="opencase-dp-dialog__loading" />

				<p v-else-if="receivers.length === 0" class="opencase-dp-dialog__empty">
					{{ t('opencase', 'Ingen modtagere') }}
				</p>

				<table v-else class="opencase-dp-dialog__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'CPR/CVR') }}</th>
							<th>{{ t('opencase', 'Adresse') }}</th>
							<th>{{ t('opencase', 'Tilmeldt digital post') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="receiver in receivers" :key="receiver.id">
							<td>{{ receiver.name || '–' }}</td>
							<td class="opencase-dp-dialog__mono"><CprDisplay :value="receiver.cpr_cvr" /></td>
							<td>
								<template v-if="receiver.has_address_protection">
									<div v-if="!revealedAddresses[receiver.id]"
										class="opencase-dp-dialog__protected-badge"
										@click="revealedAddresses[receiver.id] = true">
										{{ t('opencase', 'Beskyttet adresse') }}
									</div>
									<div v-else
										class="opencase-dp-dialog__protected-badge"
										@click="revealedAddresses[receiver.id] = false">
										{{ formatAddress(receiver) }}
									</div>
								</template>
								<template v-else>
									{{ formatAddress(receiver) }}
								</template>
							</td>
							<td>
								<span v-if="receiver.dpStatus === 'loading'" class="opencase-dp-dialog__status">
									<NcLoadingIcon :size="18" />
								</span>
								<span v-else-if="receiver.dpStatus === 'yes'" class="opencase-dp-dialog__status opencase-dp-dialog__status--yes">
									<CheckIcon :size="16" />
									{{ t('opencase', 'Ja') }}
								</span>
								<span v-else-if="receiver.dpStatus === 'no'" class="opencase-dp-dialog__status opencase-dp-dialog__status--no">
									<CloseIcon :size="16" />
									{{ t('opencase', 'Nej') }}
								</span>
								<span v-else class="opencase-dp-dialog__status opencase-dp-dialog__status--unknown">
									{{ t('opencase', 'Ukendt') }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="sending || filesLoading || receiversLoading" @click="send">
				<template v-if="sending" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencase', 'Send') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import api from '../services/api.js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import CprDisplay from './CprDisplay.vue'

// Contact role ID for "Receiver" / "Modtager" (seeded with id=2)
const RECEIVER_ROLE_ID = 2

export default {
	name: 'DigitalPostDialog',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		CheckIcon,
		CloseIcon,
		CprDisplay,
	},

	props: {
		documentId: { type: [String, Number], required: true },
	},

	emits: ['close', 'send'],

	data() {
		return {
			canBeAnswered: true,
			files: [],
			filesLoading: true,
			mainFileId: null,
			receivers: [],
			receiversLoading: true,
			sending: false,
			revealedAddresses: {},
		}
	},

	mounted() {
		this.loadFiles()
		this.loadReceivers()
	},

	methods: {
		async loadFiles() {
			this.filesLoading = true
			try {
				this.files = await api.getFilesByDocument(this.documentId)
				if (this.files.length > 0) {
					this.mainFileId = this.files[0].id
				}
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse filer'))
			} finally {
				this.filesLoading = false
			}
		},

		async loadReceivers() {
			this.receiversLoading = true
			try {
				const contacts = await api.getDocumentContacts(this.documentId)
				this.receivers = contacts
					.filter(c => c.contactrole_id === RECEIVER_ROLE_ID)
					.map(c => ({ ...c, dpStatus: 'unknown' }))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse modtagere'))
			} finally {
				this.receiversLoading = false
			}

			// Asynchronously check digital post status for each receiver with a CPR/CVR
			this.receivers.forEach((receiver, index) => {
				if (receiver.cpr_cvr) {
					this.receivers[index] = { ...receiver, dpStatus: 'loading' }
					api.checkDigitalPost(receiver.cpr_cvr)
						.then(data => {
							this.receivers[index] = {
								...this.receivers[index],
								dpStatus: data.has_subscription ? 'yes' : 'no',
							}
						})
						.catch(() => {
							this.receivers[index] = {
								...this.receivers[index],
								dpStatus: 'unknown',
							}
						})
				}
			})
		},

		formatAddress(contact) {
			const street = [contact.streetname, contact.housenumber, contact.floor, contact.door]
				.filter(Boolean).join(' ')
			const city = [contact.zipcode, contact.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
		},

		async send() {
			this.sending = true
			try {
				const payload = {
					can_be_replied: this.canBeAnswered,
					main_file_id: this.mainFileId,
					receivers: this.receivers.map(r => ({
						document_contact_id: r.id,
						cpr_cvr: r.cpr_cvr || null,
						receiver_name: r.name || null,
						dp_subscription: r.dpStatus === 'yes',
					})),
				}
				await api.createDigitalPostShipment(this.documentId, payload)
				showSuccess(t('opencase', 'Digital post forsendelse oprettet'))
				this.$emit('close')
			} catch (e) {
				showError(t('opencase', 'Kunne ikke afsende digital post'))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-dp-dialog {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 8px 0;
}

.opencase-dp-dialog__option {
	display: flex;
	align-items: center;
}

.opencase-dp-dialog__section-title {
	font-weight: 600;
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	font-size: 0.85em;
}

.opencase-dp-dialog__loading {
	margin: 16px auto;
}

.opencase-dp-dialog__empty {
	color: var(--color-text-lighter);
	font-style: italic;
}

.opencase-dp-dialog__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.95em;
}

.opencase-dp-dialog__table th,
.opencase-dp-dialog__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.opencase-dp-dialog__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.opencase-dp-dialog__table tbody tr:hover {
	background: var(--color-background-hover);
}

.opencase-dp-dialog__file-row {
	cursor: pointer;
}

.opencase-dp-dialog__radio-cell {
	width: 40px;
	text-align: center;
}

.opencase-dp-dialog__radio {
	display: block;
	margin: 0 auto;
	width: 16px;
	height: 16px !important;
	min-height: 16px !important;
	cursor: pointer;
	accent-color: var(--color-primary-element);
}

.opencase-dp-dialog__mono {
	font-family: monospace;
}

.opencase-dp-dialog__status {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.opencase-dp-dialog__status--yes {
	color: #2d7d2d;
	font-weight: 600;
}

.opencase-dp-dialog__status--no {
	color: #c0392b;
	font-weight: 600;
}

.opencase-dp-dialog__status--unknown {
	color: var(--color-text-lighter);
}

.opencase-dp-dialog__protected-badge {
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
}

.opencase-dp-dialog__protected-badge:hover {
	opacity: 0.85;
}
</style>
