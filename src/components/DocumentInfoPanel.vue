<template>
	<div class="opencase-doc-info__grid">
		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Dokumentnummer') }}</label>
			<span class="opencase-doc-info__mono">{{ document.document_number || '–' }}</span>
		</div>
		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Sag') }}</label>
			<router-link v-if="parentCase"
				:to="{ name: 'case-detail', params: { id: String(parentCase.id) } }"
				class="opencase-doc-info__case-link">
				{{ parentCase.case_number }} – {{ parentCase.title }}
			</router-link>
			<span v-else>–</span>
		</div>

		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Titel') }}</label>
			<span>{{ document.title }}</span>
		</div>
		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Dokumentdato') }}</label>
			<span>{{ formatSimpleDate(document.document_date) }}</span>
		</div>

		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Dokumentkategori') }}</label>
			<span>{{ document.document_category_name || '–' }}</span>
		</div>
		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Modtaget dato') }}</label>
			<span>{{ formatSimpleDate(document.received_date) }}</span>
		</div>

		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Dokumenttype') }}</label>
			<span>{{ document.document_type || '–' }}</span>
		</div>
		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Registreret dato') }}</label>
			<span>{{ formatSimpleDate(document.registered_date) }}</span>
		</div>

		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
			<span>{{ document.insight_level_name || '–' }}</span>
		</div>
		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Status') }}</label>
			<CaseStatusBadge :status="String(document.status)" :label="document.status_name" />
		</div>

		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Oprettet') }}</label>
			<span>{{ formatDate(document.created_at) }}</span>
		</div>
		<div class="opencase-doc-info__field">
		</div>
		
		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Oprettet af') }}</label>
			<span>{{ document.created_by_display_name || document.created_by }}</span>
		</div>
		<div class="opencase-doc-info__field">
		</div>

		<div class="opencase-doc-info__field">
			<label>{{ t('opencase', 'Sidst opdateret') }}</label>
			<span>{{ formatDate(document.updated_at) }}</span>
		</div>
		<div class="opencase-doc-info__field">
		</div>

	</div>
</template>

<script>
import CaseStatusBadge from './CaseStatusBadge.vue'

export default {
	name: 'DocumentInfoPanel',
	components: { CaseStatusBadge },
	props: {
		document: { type: Object, required: true },
		parentCase: { type: Object, default: null },
	},
	methods: {
		formatDate(dateStr) {
			if (!dateStr) return '–'
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'long', day: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},
		formatSimpleDate(dateStr) {
			if (!dateStr) return '–'
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'long', day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-doc-info__grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 18px 32px;
}

.opencase-doc-info__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-doc-info__field label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.opencase-doc-info__field--full {
	grid-column: 1 / -1;
}

.opencase-doc-info__case-link {
	font-weight: 500;
	color: var(--color-primary-element);
	text-decoration: none;
}

.opencase-doc-info__case-link:hover {
	text-decoration: underline;
}

.opencase-doc-info__mono {
	font-family: monospace;
}
</style>
