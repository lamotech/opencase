<template>
	<div class="opencase-inbound">
		<h2>{{ t('opencase', 'Indkommende dokumenter') }}</h2>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent v-else-if="documents.length === 0"
			:title="t('opencase', 'Ingen indkommende dokumenter')"
			:description="t('opencase', 'Dokumenter modtaget via Digital Post vises her.')">
			<template #icon>
				<InboxArrowDownIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<table class="opencase-inbound__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Titel') }}</th>
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'Organisation') }}</th>
						<th>{{ t('opencase', 'Modtaget') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="doc in documents"
						:key="doc.id"
						class="opencase-inbound__row"
						@click="$router.push({ name: 'document-detail', params: { id: doc.id } })">
						<td class="opencase-inbound__title">{{ doc.title }}</td>
						<td class="opencase-inbound__type">{{ doc.document_type }}</td>
						<td class="opencase-inbound__org">{{ doc.org_name }}</td>
						<td class="opencase-inbound__date">{{ formatDate(doc.received_date || doc.created_at) }}</td>
					</tr>
				</tbody>
			</table>

			<div v-if="total > limit" class="opencase-inbound__pagination">
				<NcButton :disabled="offset === 0" @click="prevPage">
					{{ t('opencase', 'Forrige') }}
				</NcButton>
				<span class="opencase-inbound__page-info">
					{{ offset + 1 }}–{{ Math.min(offset + limit, total) }} / {{ total }}
				</span>
				<NcButton :disabled="offset + limit >= total" @click="nextPage">
					{{ t('opencase', 'Næste') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import InboxArrowDownIcon from 'vue-material-design-icons/InboxArrowDown.vue'
import api from '../services/api.js'

export default {
	name: 'InboundDocumentsView',

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		NcButton,
		InboxArrowDownIcon,
	},

	data() {
		return {
			documents: [],
			total: 0,
			loading: true,
			limit: 50,
			offset: 0,
		}
	},

	async mounted() {
		await this.fetchDocuments()
	},

	methods: {
		async fetchDocuments() {
			this.loading = true
			try {
				const result = await api.getInboundDocuments({ limit: this.limit, offset: this.offset })
				this.documents = result.documents ?? []
				this.total = result.total ?? 0
			} finally {
				this.loading = false
			}
		},

		async prevPage() {
			this.offset = Math.max(0, this.offset - this.limit)
			await this.fetchDocuments()
		},

		async nextPage() {
			this.offset += this.limit
			await this.fetchDocuments()
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-inbound {
	padding: 20px;
	max-width: 1000px;
}

.opencase-inbound h2 {
	margin: 0 0 16px;
	font-size: 1.4em;
}

.opencase-inbound__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-inbound__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
}

.opencase-inbound__row {
	cursor: pointer;
}

.opencase-inbound__row:hover {
	background: var(--color-background-hover);
}

.opencase-inbound__row td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.opencase-inbound__title {
	font-weight: 500;
}

.opencase-inbound__type {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.opencase-inbound__org {
	color: var(--color-text-lighter);
}

.opencase-inbound__date {
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.opencase-inbound__pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
	justify-content: center;
}

.opencase-inbound__page-info {
	color: var(--color-text-lighter);
	min-width: 100px;
	text-align: center;
}
</style>
