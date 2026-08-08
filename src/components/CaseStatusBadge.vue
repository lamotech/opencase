<template>
	<span :class="['opencase-status-badge', `opencase-status-badge--${status}`]">
		{{ displayLabel }}
	</span>
</template>

<script>
const STATUS_LABELS = {
	open: 'Åben',
	closed: 'Lukket',
	archived: 'Arkiveret',
}

export default {
	name: 'CaseStatusBadge',
	props: {
		status: { type: [String, Number], required: true },
		// Optional override — pass the localized name from the API response
		// (used for document statuses where the name comes from the server)
		label: { type: String, default: null },
	},
	computed: {
		displayLabel() {
			if (this.label !== null) return this.label
			return t('opencase', STATUS_LABELS[this.status] || String(this.status))
		},
	},
}
</script>

<style scoped>
.opencase-status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
}

/* Case statuses */
.opencase-status-badge--open { background: #e8f5e9; color: #2e7d32; }
.opencase-status-badge--closed { background: #fce4ec; color: #c62828; }
.opencase-status-badge--archived { background: #e0e0e0; color: #616161; }

/* Document statuses (ID-based) */
.opencase-status-badge--1 { background: #fff3e0; color: #e65100; }  /* Draft / Kladde */
.opencase-status-badge--2 { background: #e3f2fd; color: #1565c0; }  /* Passive / Passiv */
.opencase-status-badge--3 { background: #ede7f6; color: #4527a0; }  /* Final / Endelig */
</style>
