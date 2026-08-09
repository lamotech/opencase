<template>
	<div class="opencase-case-info">
		<div class="opencase-case-info__grid">
			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Sagsnummer') }}</label>
				<span>{{ caseData.case_number }}</span>
			</div>
			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Ansvarlig') }}</label>
				<span>{{ caseData.responsible_user_display_name || '–' }}</span>
			</div>

			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Titel') }}</label>
				<span>{{ caseData.title }}</span>
			</div>
			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Organisation') }}</label>
				<span>{{ caseData.organisation }}</span>
			</div>

			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
				<span>{{ caseData.insight_level_name || '–' }}</span>
			</div>
			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'KLE-nummer') }}</label>
				<span>{{ caseData.classification_code }}<template v-if="caseData.classification_title"> – {{ caseData.classification_title }}</template></span>
			</div>

			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Oprettet') }}</label>
				<span>{{ formatDate(caseData.created_at) }}</span>
			</div>
			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Handlingsfacet') }}</label>
				<span>
					<template v-if="caseData.classification_facet_code">{{ caseData.classification_facet_code }} – {{ caseData.classification_facet_title }}</template>
					<template v-else>–</template>
				</span>
			</div>

			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Oprettet af') }}</label>
				<span>{{ caseData.created_by_display_name || caseData.created_by }}</span>
			</div>
			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Følsomhed') }}</label>
				<span>{{ caseData.sensitivity_title || caseData.sensitivity_key }}</span>
			</div>

			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Ansvarlig') }}</label>
				<span>{{ caseData.responsible_user_display_name || '–' }}</span>
			</div>
			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Status') }}</label>
				<CaseStatusBadge :status="caseData.status_class" :label="caseData.status_name" />
			</div>

			<div class="opencase-case-info__field">
				<label>{{ t('opencase', 'Sidst opdateret') }}</label>
				<span>{{ formatDate(caseData.updated_at) }}</span>
			</div>
			<div class="opencase-case-info__field">
			</div>

			<div v-if="caseData.summary" class="opencase-case-info__field opencase-case-info__field--full">
				<label>{{ t('opencase', 'Sagsresume') }}</label>
				<!-- eslint-disable-next-line vue/no-v-html -- rich text authored in the app's own TipTap editor -->
				<div class="opencase-case-info__summary" v-html="caseData.summary" />
			</div>
		</div>
	</div>
</template>

<script>
import CaseStatusBadge from './CaseStatusBadge.vue'

export default {
	name: 'CaseInfoPanel',
	components: { CaseStatusBadge },
	props: {
		caseData: { type: Object, required: true },
	},
	methods: {
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.opencase-case-info__grid {
	display: grid; grid-template-columns: 1fr 1fr; gap: 18px 32px;
}
.opencase-case-info__field {
	display: flex; flex-direction: column; gap: 4px;
}
.opencase-case-info__field--full { grid-column: 1 / -1; }
.opencase-case-info__field label {
	font-size: 0.8em; font-weight: 600; text-transform: uppercase;
	letter-spacing: 0.04em; color: var(--color-text-maxcontrast);
}
.opencase-case-info__mono { font-family: monospace; }
.opencase-case-info__path {
	background: var(--color-background-dark); padding: 6px 10px;
	border-radius: 4px; font-size: 0.9em; word-break: break-all;
}
.opencase-case-info__summary {
	font-size: 0.95em;
	line-height: 1.6;
	color: var(--color-main-text);
	overflow-wrap: break-word;
}
</style>

<style>
/* Unscoped: the summary is rendered from stored HTML */
.opencase-case-info__summary p {
	margin: 0 0 8px 0;
}

.opencase-case-info__summary h1 {
	font-size: 1.5em;
	font-weight: 700;
	margin: 14px 0 6px 0;
}

.opencase-case-info__summary h2 {
	font-size: 1.2em;
	font-weight: 700;
	margin: 12px 0 6px 0;
}

.opencase-case-info__summary h3 {
	font-size: 1.05em;
	font-weight: 700;
	margin: 10px 0 4px 0;
}

.opencase-case-info__summary ul,
.opencase-case-info__summary ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.opencase-case-info__summary blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}
</style>
