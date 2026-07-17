<template>
	<div class="opencase-widget">
		<NcLoadingIcon v-if="loading" :size="30" class="opencase-widget__loading" />

		<ul v-else-if="documents.length > 0" class="opencase-widget__list">
			<li v-for="doc in documents" :key="doc.id" class="opencase-widget__item">
				<a :href="documentUrl(doc.id)" class="opencase-widget__link">
					<span class="opencase-widget__title">{{ doc.title }}</span>
					<span class="opencase-widget__meta">
						<span v-if="doc.document_type" class="opencase-widget__type">{{ doc.document_type }}</span>
						<span class="opencase-widget__date">{{ formatDate(doc.received_date || doc.created_at) }}</span>
					</span>
				</a>
			</li>
		</ul>

		<p v-else class="opencase-widget__empty">
			{{ t('opencase', 'Ingen indkommende dokumenter') }}
		</p>

		<div class="opencase-widget__footer">
			<a :href="allInboundUrl" class="opencase-widget__view-all">
				{{ t('opencase', 'Vis alle') }}
			</a>
		</div>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'InboundDocumentsWidget',

	components: { NcLoadingIcon },

	data() {
		return {
			loading: true,
			documents: [],
		}
	},

	computed: {
		allInboundUrl() {
			return generateUrl('/apps/opencase/inbound')
		},
	},

	mounted() {
		this.fetchDocuments()
	},

	methods: {
		async fetchDocuments() {
			const url = generateOcsUrl('/apps/opencase/api/v1') + '/inbound-documents'
			try {
				const response = await axios.get(url, {
					params: { limit: 8, format: 'json' },
				})
				const data = response.data?.ocs?.data ?? response.data
				this.documents = data?.documents ?? []
			} catch (e) {
				console.error('[OpenCase inbound widget] failed', e?.response?.status, e?.message)
			} finally {
				this.loading = false
			}
		},

		documentUrl(id) {
			return generateUrl(`/apps/opencase/document/${id}`)
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				day: 'numeric',
				month: 'short',
			})
		},
	},
}
</script>

<style scoped>
.opencase-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.opencase-widget__loading {
	margin: 20px auto;
}

.opencase-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	flex: 1;
	overflow-y: auto;
}

.opencase-widget__item {
	border-bottom: 1px solid var(--color-border);
}

.opencase-widget__link {
	display: flex;
	flex-direction: column;
	padding: 8px 12px;
	text-decoration: none;
	color: var(--color-main-text);
	gap: 2px;
}

.opencase-widget__link:hover {
	background: var(--color-background-hover);
}

.opencase-widget__title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 500;
}

.opencase-widget__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.opencase-widget__type {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex: 1;
}

.opencase-widget__date {
	white-space: nowrap;
	flex-shrink: 0;
}

.opencase-widget__empty {
	padding: 20px 12px;
	color: var(--color-text-lighter);
	text-align: center;
	flex: 1;
}

.opencase-widget__footer {
	padding: 8px 12px;
	text-align: center;
	border-top: 1px solid var(--color-border);
	margin-top: auto;
}

.opencase-widget__view-all {
	color: var(--color-primary-element);
	text-decoration: none;
	font-weight: 500;
}

.opencase-widget__view-all:hover {
	text-decoration: underline;
}
</style>
