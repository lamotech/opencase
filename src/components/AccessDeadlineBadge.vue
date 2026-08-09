<template>
	<span :class="['oc-deadline-badge', `oc-deadline-badge--${colour}`]" :title="deadlineFormatted">
		<ClockOutlineIcon :size="14" class="oc-deadline-badge__icon" />
		{{ daysLabel }}
	</span>
</template>

<script>
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'

export default {
	name: 'AccessDeadlineBadge',
	components: { ClockOutlineIcon },
	props: {
		deadlineAt:  { type: String, required: true },
		colour:      { type: String, default: 'green' }, // green | yellow | red
	},
	computed: {
		deadlineDate() { return new Date(this.deadlineAt) },
		deadlineFormatted() {
			return this.deadlineDate.toLocaleDateString('da-DK', { day: '2-digit', month: '2-digit', year: 'numeric' })
		},
		daysLeft() {
			const diff = this.deadlineDate - new Date()
			return Math.ceil(diff / (1000 * 60 * 60 * 24))
		},
		daysLabel() {
			if (this.colour === 'red') {
				const overdue = Math.abs(this.daysLeft)
				return t('opencase', '{n} dag(e) overskredet', { n: overdue })
			}
			return t('opencase', '{n} dag(e) tilbage', { n: this.daysLeft })
		},
	},
}
</script>

<style scoped>
.oc-deadline-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
}
.oc-deadline-badge--green  { background: #e8f5e9; color: #2e7d32; }
.oc-deadline-badge--yellow { background: #fff8e1; color: #f57f17; }
.oc-deadline-badge--red    { background: #ffebee; color: #b71c1c; }
.oc-deadline-badge__icon   { vertical-align: middle; }
</style>
