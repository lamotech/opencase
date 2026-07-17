<template>
	<span v-if="!normalizedValue">–</span>
	<span v-else-if="isCpr"
		class="occ-cpr-display"
		:class="{ 'occ-cpr-display--revealed': revealed }"
		role="button"
		tabindex="0"
		:title="revealed ? t('opencase', 'Klik for at skjule CPR-nummer') : t('opencase', 'Klik for at se fuldt CPR-nummer')"
		@click.stop="revealed = !revealed"
		@keydown.enter.stop="revealed = !revealed"
		@keydown.space.prevent.stop="revealed = !revealed">
		{{ revealed ? normalizedValue : maskedValue }}
	</span>
	<span v-else>{{ normalizedValue }}</span>
</template>

<script>
import { isCpr, maskCpr } from '../utils/cprMask.js'

export default {
	name: 'CprDisplay',

	props: {
		value: { type: String, default: '' },
	},

	data() {
		return { revealed: false }
	},

	computed: {
		normalizedValue() {
			return this.value ? String(this.value).trim() : ''
		},
		isCpr() {
			return isCpr(this.normalizedValue)
		},
		maskedValue() {
			return maskCpr(this.normalizedValue)
		},
	},
}
</script>

<style scoped>
.occ-cpr-display {
	font-family: var(--font-monospace, monospace);
	cursor: pointer;
	text-decoration: underline dotted var(--color-text-lighter);
	user-select: none;
	white-space: nowrap;
}

.occ-cpr-display:hover {
	opacity: 0.75;
}

.occ-cpr-display:focus-visible {
	outline: 2px solid var(--color-primary-element);
	border-radius: 2px;
}
</style>
