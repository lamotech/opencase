<template>
	<div v-if="fields.length" class="opencase-custom-fields">
		<h3>{{ t('opencase', 'Tillægsfelter') }}</h3>

		<div class="opencase-custom-fields__grid">
			<div v-for="field in fields" :key="field.id" class="opencase-custom-fields__field">
				<label>{{ field.label }}</label>
				<span>{{ displayValue(field) }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import api from '../services/api.js'

export default {
	name: 'CustomFieldsPanel',
	props: {
		caseId: { type: Number, required: true },
	},
	data() {
		return {
			fields: [],
		}
	},
	watch: {
		caseId: {
			immediate: true,
			handler() {
				this.fetchFields()
			},
		},
	},
	methods: {
		async fetchFields() {
			try {
				this.fields = await api.getCaseCustomFields(this.caseId)
			} catch (e) {
				this.fields = []
			}
		},
		displayValue(field) {
			if (field.value === null || field.value === undefined || field.value === '') {
				return '–'
			}
			if (field.field_type === 'checkbox') {
				return field.value ? t('opencase', 'Ja') : t('opencase', 'Nej')
			}
			if (field.field_type === 'date') {
				return new Date(field.value).toLocaleDateString('da-DK', { year: 'numeric', month: 'long', day: 'numeric' })
			}
			return field.value
		},
	},
}
</script>

<style scoped>
.opencase-custom-fields {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
.opencase-custom-fields h3 {
	margin: 0 0 8px 0; font-size: 1em; font-weight: 600;
}
.opencase-custom-fields__grid {
	display: grid; grid-template-columns: 1fr 1fr; gap: 18px 32px;
}
.opencase-custom-fields__field {
	display: flex; flex-direction: column; gap: 4px;
}
.opencase-custom-fields__field label {
	font-size: 0.8em; font-weight: 600; text-transform: uppercase;
	letter-spacing: 0.04em; color: var(--color-text-maxcontrast);
}
</style>
