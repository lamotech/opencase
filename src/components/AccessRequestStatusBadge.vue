<template>
	<span :class="['oc-access-status', `oc-access-status--${status}`]">
		{{ label }}
	</span>
</template>

<script>
const LABELS = {
	received:       'Modtaget',
	collecting:     'Indsamler',
	reviewing:      'Vurderer',
	redacting:      'Maskerer',
	approval:       'Godkendelse',
	sent:           'Afsendt',
	rejected:       'Afslået',
	partly_rejected: 'Delvist afslået',
	closed:         'Afsluttet',
}

export default {
	name: 'AccessRequestStatusBadge',
	props: {
		status: { type: String, required: true },
	},
	computed: {
		label() { return t('opencase', LABELS[this.status] ?? this.status) },
	},
}
</script>

<style scoped>
.oc-access-status {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
}
.oc-access-status--received       { background: #e3f2fd; color: #1565c0; }
.oc-access-status--collecting     { background: #fff8e1; color: #f57f17; }
.oc-access-status--reviewing      { background: #fff3e0; color: #e65100; }
.oc-access-status--redacting      { background: #fce4ec; color: #880e4f; }
.oc-access-status--approval       { background: #ede7f6; color: #4527a0; }
.oc-access-status--sent           { background: #e8f5e9; color: #1b5e20; }
.oc-access-status--rejected       { background: #ffebee; color: #b71c1c; }
.oc-access-status--partly_rejected{ background: #fff9c4; color: #827717; }
.oc-access-status--closed         { background: #f5f5f5; color: #424242; }
</style>
