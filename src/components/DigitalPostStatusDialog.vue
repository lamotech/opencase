<template>
	<NcDialog :name="t('opencase', 'Digital post – status')"
		size="large"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-dp-status">
			<!-- Shipment header -->
			<div class="opencase-dp-status__header">
				<div class="opencase-dp-status__meta">
					<span class="opencase-dp-status__badge" :class="statusClass">
						{{ localizeStatus(shipment.status) }}
					</span>
					<span class="opencase-dp-status__date">
						{{ t('opencase', 'Oprettet {date}', { date: formatDate(shipment.created_at) }) }}
					</span>
				</div>
				<NcButton :aria-label="t('opencase', 'Opdater')" @click="refresh">
					<template #icon>
						<RefreshIcon :size="18" />
					</template>
					{{ t('opencase', 'Opdater') }}
				</NcButton>
			</div>

			<!-- Additional documents -->
			<div class="opencase-dp-status__section">
				<h3 class="opencase-dp-status__section-title">{{ t('opencase', 'Yderligere dokumenter') }}</h3>
				<p v-if="additionalFiles.length === 0" class="opencase-dp-status__empty">
					{{ t('opencase', 'Ingen yderligere dokumenter') }}
				</p>
				<table v-else class="opencase-dp-status__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Filnavn') }}</th>
							<th>{{ t('opencase', 'PDF') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="file in additionalFiles" :key="file.id">
							<td>{{ file.file_name }}</td>
							<td>
								<NcButton v-if="file.has_pdf"
									type="tertiary"
									:aria-label="t('opencase', 'Download')"
									@click="downloadFile(file)">
									<template #icon>
										<DownloadIcon :size="18" />
									</template>
									{{ t('opencase', 'Download') }}
								</NcButton>
								<span v-else class="opencase-dp-status__pending">–</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Receivers -->
			<div class="opencase-dp-status__section">
				<h3 class="opencase-dp-status__section-title">{{ t('opencase', 'Modtagere') }}</h3>
				<NcLoadingIcon v-if="loading" :size="32" class="opencase-dp-status__loading" />
				<p v-else-if="receivers.length === 0" class="opencase-dp-status__empty">
					{{ t('opencase', 'Ingen modtagere') }}
				</p>
				<table v-else class="opencase-dp-status__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'CPR/CVR') }}</th>
							<th>{{ t('opencase', 'Status') }}</th>
							<th>{{ t('opencase', 'PDF') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="receiver in receivers" :key="receiver.id">
							<td>{{ receiver.receiver_name || '–' }}</td>
							<td class="opencase-dp-status__mono"><CprDisplay :value="receiver.cpr_cvr" /></td>
							<td>
								<span class="opencase-dp-status__badge" :class="receiverStatusClass(receiver.status)">
									{{ localizeStatus(receiver.status) }}
								</span>
							</td>
							<td>
								<NcButton v-if="receiver.has_pdf"
									type="tertiary"
									:aria-label="t('opencase', 'Download')"
									@click="downloadReceiverFile(receiver)">
									<template #icon>
										<DownloadIcon :size="18" />
									</template>
									{{ t('opencase', 'Download') }}
								</NcButton>
								<span v-else class="opencase-dp-status__pending">–</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Luk') }}
			</NcButton>
			<!-- 
			<NcButton type="secondary" @click="$emit('new-shipment')">
				{{ t('opencase', 'Ny forsendelse') }}
			</NcButton>
			 -->
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import api from '../services/api.js'
import { showError } from '@nextcloud/dialogs'
import CprDisplay from './CprDisplay.vue'

const IN_PROGRESS_STATUSES = ['Pending', 'Generating PDFs', 'Sending']

export default {
	name: 'DigitalPostStatusDialog',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		DownloadIcon,
		RefreshIcon,
		CprDisplay,
	},

	props: {
		documentId: { type: [String, Number], required: true },
		initialShipment: { type: Object, required: true },
	},

	emits: ['close', 'new-shipment'],

	data() {
		return {
			loading: false,
			shipment: { ...this.initialShipment.shipment },
			additionalFiles: (this.initialShipment.files || []).filter(f => !f.is_main),
			receivers: this.initialShipment.receivers || [],
			pollTimer: null,
		}
	},

	computed: {
		statusClass() {
			return this.receiverStatusClass(this.shipment.status)
		},
	},

	mounted() {
		if (IN_PROGRESS_STATUSES.includes(this.shipment.status)) {
			this.schedulePoll()
		}
	},

	beforeUnmount() {
		this.clearPoll()
	},

	methods: {
		async refresh() {
			this.loading = true
			try {
				const data = await api.getLatestDigitalPostShipment(this.documentId)
				if (data.shipment) {
					this.shipment = data.shipment
					this.additionalFiles = (data.files || []).filter(f => !f.is_main)
					this.receivers = data.receivers || []
				}
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente status'))
			} finally {
				this.loading = false
			}

			if (IN_PROGRESS_STATUSES.includes(this.shipment.status)) {
				this.schedulePoll()
			} else {
				this.clearPoll()
			}
		},

		schedulePoll() {
			this.clearPoll()
			this.pollTimer = setTimeout(() => this.refresh(), 10000)
		},

		clearPoll() {
			if (this.pollTimer) {
				clearTimeout(this.pollTimer)
				this.pollTimer = null
			}
		},

		async downloadFile(file) {
			const name = file.file_name.replace(/\.[^.]+$/, '') + '.pdf'
			try {
				await api.downloadDpShipmentFile(file.id, name)
			} catch (e) {
				showError(t('opencase', 'Download fejlede'))
			}
		},

		async downloadReceiverFile(receiver) {
			const filename = (receiver.receiver_name || receiver.cpr_cvr || String(receiver.id)) + '.pdf'
			try {
				await api.downloadDpReceiverFile(receiver.id, filename)
			} catch (e) {
				showError(t('opencase', 'Download fejlede'))
			}
		},

		localizeStatus(status) {
			const map = {
				'Pending':                 t('opencase', 'dp_status_Pending'),
				'Generating PDFs':         t('opencase', 'dp_status_Generating PDFs'),
				'PDF Generated':           t('opencase', 'dp_status_PDF Generated'),
				'PDF Generation Failed':   t('opencase', 'dp_status_PDF Generation Failed'),
				'Sending':                 t('opencase', 'dp_status_Sending'),
				'Sent':                    t('opencase', 'dp_status_Sent'),
				'Send Failed':             t('opencase', 'dp_status_Send Failed'),
			}
			return map[status] ?? status
		},

		receiverStatusClass(status) {
			if (status === 'Sent') return 'opencase-dp-status__badge--sent'
			if (status === 'PDF Generation Failed' || status === 'Send Failed') return 'opencase-dp-status__badge--failed'
			return 'opencase-dp-status__badge--progress'
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.opencase-dp-status {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 8px 0;
}

.opencase-dp-status__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.opencase-dp-status__meta {
	display: flex;
	align-items: center;
	gap: 10px;
}

.opencase-dp-status__date {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.opencase-dp-status__section-title {
	font-weight: 600;
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	font-size: 0.85em;
}

.opencase-dp-status__loading { margin: 16px auto; }

.opencase-dp-status__empty {
	color: var(--color-text-lighter);
	font-style: italic;
}

.opencase-dp-status__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.95em;
}

.opencase-dp-status__table th,
.opencase-dp-status__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.opencase-dp-status__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.opencase-dp-status__table tbody tr:hover {
	background: var(--color-background-hover);
}

.opencase-dp-status__mono { font-family: monospace; }

.opencase-dp-status__pending { color: var(--color-text-lighter); }

.opencase-dp-status__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.85em;
	font-weight: 600;
}

.opencase-dp-status__badge--sent {
	background: #e6f4ea;
	color: #2d7d2d;
}

.opencase-dp-status__badge--failed {
	background: #fdecea;
	color: #c0392b;
}

.opencase-dp-status__badge--progress {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}
</style>
