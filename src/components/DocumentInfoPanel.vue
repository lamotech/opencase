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
			<template v-if="document.updated_in_sapa">
				<label>{{ t('opencase', 'Overført til SAPA') }}</label>
				<span>{{ formatDate(document.updated_in_sapa) }}</span>
			</template>
		</div>

		<div v-if="document.summary" class="opencase-doc-info__field opencase-doc-info__field--full">
			<label>{{ t('opencase', 'Dokumentresume') }}</label>
			<!-- eslint-disable-next-line vue/no-v-html -- rich text authored in the app's own TipTap editor -->
			<div class="opencase-doc-info__summary" v-html="document.summary" />
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

.opencase-doc-info__summary {
	font-size: 0.95em;
	line-height: 1.6;
	color: var(--color-main-text);
	overflow-wrap: break-word;
}
</style>

<style>
/* Unscoped: the summary is rendered from stored HTML */
.opencase-doc-info__summary p {
	margin: 0 0 8px 0;
}

.opencase-doc-info__summary h1 {
	font-size: 1.5em;
	font-weight: 700;
	margin: 14px 0 6px 0;
}

.opencase-doc-info__summary h2 {
	font-size: 1.2em;
	font-weight: 700;
	margin: 12px 0 6px 0;
}

.opencase-doc-info__summary h3 {
	font-size: 1.05em;
	font-weight: 700;
	margin: 10px 0 4px 0;
}

.opencase-doc-info__summary ul,
.opencase-doc-info__summary ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.opencase-doc-info__summary blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}
</style>
