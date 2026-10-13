<template>
	<span :class="['oc-bulkupdate-status', `oc-bulkupdate-status--${status}`]">
		{{ label }}
	</span>
</template>

<script>
const LABELS = {
	created: 'Oprettet',
	pending: 'Afventer',
	processing: 'Behandler',
	processed: 'Behandlet',
	cancelled: 'Annulleret',
}

export default {
	name: 'BulkUpdateStatusBadge',
	props: {
		status: { type: String, required: true },
	},
	computed: {
		label() { return t('opencase', LABELS[this.status] ?? this.status) },
	},
}
</script>

<style scoped>
.oc-bulkupdate-status {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
}
.oc-bulkupdate-status--created    { background: #f5f5f5; color: #424242; }
.oc-bulkupdate-status--pending    { background: #fff8e1; color: #f57f17; }
.oc-bulkupdate-status--processing { background: #e3f2fd; color: #1565c0; }
.oc-bulkupdate-status--processed  { background: #e8f5e9; color: #1b5e20; }
.oc-bulkupdate-status--cancelled  { background: #ffebee; color: #b71c1c; }
</style>
