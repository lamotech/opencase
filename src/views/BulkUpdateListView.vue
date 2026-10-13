<template>
	<div class="opencase-bulkupdate">
		<div class="opencase-bulkupdate__header">
			<h2>{{ pageTitle }}</h2>
			<NcButton type="primary" @click="$router.push({ name: createRouteName })">
				{{ t('opencase', 'Ny Masseredigering') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" class="opencase-bulkupdate__loading" />

		<NcEmptyContent v-else-if="jobs.length === 0"
			:title="t('opencase', 'Ingen masseredigeringer endnu')">
			<template #icon>
				<PlaylistEditIcon :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="opencase-bulkupdate__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Titel') }}</th>
					<th>{{ t('opencase', 'Antal') }}</th>
					<th>{{ t('opencase', 'Succes') }}</th>
					<th>{{ t('opencase', 'Fejlet') }}</th>
					<th>{{ t('opencase', 'Status') }}</th>
					<th>{{ t('opencase', 'Oprettet') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="job in jobs" :key="job.id">
					<td class="opencase-bulkupdate__title">
						<button type="button" class="opencase-bulkupdate__title-link" @click="detailsJob = job">
							{{ job.title }}
						</button>
					</td>
					<td>{{ job.total_count }}</td>
					<td>{{ job.success_count }}</td>
					<td>
						<button v-if="job.failed_count > 0"
							type="button"
							class="opencase-bulkupdate__failed-link"
							@click="openFailedItems(job)">
							{{ job.failed_count }}
						</button>
						<span v-else>{{ job.failed_count }}</span>
					</td>
					<td>
						<BulkUpdateStatusBadge :status="job.status" />
					</td>
					<td class="opencase-bulkupdate__date">{{ formatDate(job.created_at) }}</td>
					<td>
						<NcButton v-if="canCancel(job)" type="tertiary" @click="cancelJob(job)">
							{{ t('opencase', 'Annuller') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Failed items dialog -->
		<NcDialog v-if="failedItemsJob"
			:name="failedItemsDialogTitle"
			size="large"
			@closing="failedItemsJob = null">
			<NcLoadingIcon v-if="failedItemsLoading" :size="32" class="opencase-bulkupdate__loading" />
			<table v-else class="opencase-bulkupdate__failed-table">
				<thead>
					<tr>
						<th>{{ failedItemsJob.entity === 'document' ? t('opencase', 'Dokumentnummer') : t('opencase', 'Sagsnummer') }}</th>
						<th>{{ t('opencase', 'Fejlmeddelelse') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="fi in failedItems" :key="fi.entity_id">
						<td class="opencase-bulkupdate__mono">
							<a :href="itemUrl(fi.entity_id)" target="_blank" rel="noopener noreferrer">
								{{ fi.case_number || fi.entity_id }}
							</a>
						</td>
						<td>{{ fi.error_msg }}</td>
					</tr>
				</tbody>
			</table>
			<template #actions>
				<NcButton type="tertiary" @click="failedItemsJob = null">
					{{ t('opencase', 'Luk') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Job details dialog -->
		<NcDialog v-if="detailsJob"
			:name="t('opencase', 'Masseredigering') + ' — ' + detailsJob.title"
			@closing="detailsJob = null">
			<p><strong>{{ t('opencase', 'Titel') }}:</strong> {{ detailsJob.title }}</p>
			<p>
				<strong>{{ detailsJob.entity === 'document' ? t('opencase', 'Antal dokumenter') : t('opencase', 'Antal sager') }}:</strong>
				{{ detailsJob.total_count }}
			</p>
			<p>{{ t('opencase', 'Felter der opdateres:') }}</p>
			<ul>
				<li v-for="f in detailsFields" :key="f.key">
					<strong>{{ f.label }}:</strong> {{ f.value }}
				</li>
			</ul>
			<template #actions>
				<NcButton type="tertiary" @click="detailsJob = null">
					{{ t('opencase', 'Luk') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import PlaylistEditIcon from 'vue-material-design-icons/PlaylistEdit.vue'

import BulkUpdateStatusBadge from '../components/BulkUpdateStatusBadge.vue'
import api from '../services/api.js'
import { showError } from '@nextcloud/dialogs'

const UPDATE_FIELD_LABELS = {
	title: 'Titel',
	organisation: 'Organisation',
	classification_code: 'KLE-nummer',
	sensitivity: 'Følsomhed',
	classification_facet_uuid: 'Handlingsfacet',
	insight_level_id: 'Indsigtsgrad',
	responsible_user_id: 'Ansvarlig',
	summary: 'Sagsresume',
	document_type: 'Dokumenttype',
	status: 'Status',
	document_date: 'Dokumentdato',
	received_date: 'Modtaget dato',
	registered_date: 'Registreret dato',
}

export default {
	name: 'BulkUpdateListView',

	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		PlaylistEditIcon,
		BulkUpdateStatusBadge,
	},

	props: {
		entity: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			jobs: [],
			failedItemsJob: null,
			failedItems: [],
			failedItemsLoading: false,
			detailsJob: null,
		}
	},

	computed: {
		pageTitle() {
			return this.entity === 'document'
				? t('opencase', 'Masseredigering af dokumenter')
				: t('opencase', 'Masseredigering af sager')
		},

		createRouteName() {
			return this.entity === 'document' ? 'bulkupdate-document-create' : 'bulkupdate-case-create'
		},

		failedItemsDialogTitle() {
			const label = this.failedItemsJob.entity === 'document'
				? t('opencase', 'Fejlede dokumenter')
				: t('opencase', 'Fejlede sager')
			return label + ' — ' + this.failedItemsJob.title
		},

		/**
		 * detailsJob.updates as { key, label, value } — organisation/KLE/
		 * sensitivity/facet/insight-level/responsible-user are stored as
		 * raw ids/keys and resolved back to a display label via reference
		 * data already loaded into the store; title/summary are shown as-is.
		 */
		detailsFields() {
			if (!this.detailsJob) return []
			const updates = this.detailsJob.updates || {}
			return Object.keys(updates).map(key => ({
				key,
				label: t('opencase', UPDATE_FIELD_LABELS[key] || key),
				value: this.resolveFieldValue(key, updates[key]),
			}))
		},
	},

	watch: {
		entity() {
			this.load()
		},
	},

	async mounted() {
		await this.load()
		// Reference data to resolve raw updates.* ids/keys into display labels
		// for the details dialog.
		this.$store.dispatch('fetchOrganisations').catch(() => {})
		this.$store.dispatch('fetchClassificationSubjects').catch(() => {})
		this.$store.dispatch('fetchSensitivities').catch(() => {})
		this.$store.dispatch('fetchClassificationFacets').catch(() => {})
		this.$store.dispatch('fetchInsightLevels').catch(() => {})
		this.$store.dispatch('fetchDocumentStatuses').catch(() => {})
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.jobs = await api.getBulkUpdates(this.entity)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse masseredigeringer'))
			} finally {
				this.loading = false
			}
		},

		canCancel(job) {
			return ['created', 'pending', 'processing'].includes(job.status)
		},

		async cancelJob(job) {
			if (!confirm(t('opencase', 'Er du sikker på, at du vil annullere masseredigeringen "{title}"?', { title: job.title }))) {
				return
			}
			try {
				const updated = await api.cancelBulkUpdate(job.id)
				job.status = updated.status
			} catch (e) {
				showError(t('opencase', 'Kunne ikke annullere masseredigeringen'))
			}
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			})
		},

		async openFailedItems(job) {
			this.failedItemsJob = job
			this.failedItemsLoading = true
			try {
				this.failedItems = await api.getFailedBulkUpdateItems(job.id)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse fejlede sager'))
				this.failedItems = []
			} finally {
				this.failedItemsLoading = false
			}
		},

		itemUrl(entityId) {
			const routeName = this.failedItemsJob?.entity === 'document' ? 'document-detail' : 'case-detail'
			return this.$router.resolve({ name: routeName, params: { id: entityId } }).href
		},

		resolveFieldValue(key, value) {
			switch (key) {
			case 'classification_code': {
				const match = this.$store.state.classificationSubjects.find(s => s.code === value)
				return match ? `${match.code} – ${match.title}` : value
			}
			case 'sensitivity': {
				const match = this.$store.state.sensitivities.find(s => s.key === value)
				return match ? (match.title || match.key) : value
			}
			case 'classification_facet_uuid': {
				const match = this.$store.state.classificationFacets.find(f => f.uuid === value)
				return match ? (match.code ? `${match.code} – ${match.title}` : match.title) : value
			}
			case 'insight_level_id': {
				const match = this.$store.state.insightLevels.find(l => l.id === value || l.id === Number(value))
				return match ? match.name : value
			}
			case 'status': {
				const match = this.$store.state.documentStatuses.find(s => s.id === value || s.id === Number(value))
				return match ? match.name : value
			}
			default:
				// title, organisation (stored as its name already), responsible_user_id
				// (shown as the raw uid), document_type, dates, summary
				return value
			}
		},
	},
}
</script>

<style scoped>
.opencase-bulkupdate {
	padding: 20px;
	max-width: 1000px;
}

.opencase-bulkupdate__header {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
	margin-bottom: 20px;
}

.opencase-bulkupdate__header h2 {
	margin: 0;
	font-size: 1.4em;
}

.opencase-bulkupdate__loading {
	margin: 60px auto;
}

.opencase-bulkupdate__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-bulkupdate__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.opencase-bulkupdate__table td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.opencase-bulkupdate__title {
	font-weight: 500;
}

.opencase-bulkupdate__title-link {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	font-weight: 500;
	color: var(--color-main-text);
	text-decoration: underline;
	cursor: pointer;
	text-align: left;
}

.opencase-bulkupdate__title-link:hover,
.opencase-bulkupdate__title-link:focus-visible {
	color: var(--color-primary-element);
}

.opencase-bulkupdate__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
}

.opencase-bulkupdate__failed-link {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	color: #e9322d;
	font-weight: 600;
	text-decoration: underline;
	cursor: pointer;
}

.opencase-bulkupdate__failed-table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-bulkupdate__failed-table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
}

.opencase-bulkupdate__failed-table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: top;
}

.opencase-bulkupdate__mono {
	font-family: var(--font-monospace, monospace);
	white-space: nowrap;
	color: var(--color-primary-element);
}

.opencase-bulkupdate__mono a {
	color: inherit;
	text-decoration: none;
}

.opencase-bulkupdate__mono a:hover,
.opencase-bulkupdate__mono a:focus-visible {
	text-decoration: underline;
}
</style>
