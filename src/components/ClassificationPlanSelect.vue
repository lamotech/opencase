<template>
	<NcSelect :model-value="selected"
		:options="options"
		:placeholder="t('opencase', 'Vælg emne')"
		:input-label="inputLabel"
		:disabled="disabled"
		@update:model-value="v => $emit('update:modelValue', v?.id || '')">
		<template #no-options>
			{{ t('opencase', 'Emneplanen er tom. En OpenCase-administrator kan tilføje emner under Konfiguration → Emneplan.') }}
		</template>
	</NcSelect>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { classificationPlanLabel } from '../utils/classificationPlanLabel.js'

/**
 * Picker for a case's Emneplan item (classification plan Emneplan).
 *
 * v-model is the item's uuid. Lists the active items of the Emneplan.
 */
export default {
	name: 'ClassificationPlanSelect',

	components: { NcSelect },

	props: {
		modelValue: {
			type: String,
			default: '',
		},
		/** Label of the current item, shown while the list loads or if the item has been deactivated. */
		currentLabel: {
			type: String,
			default: '',
		},
		inputLabel: {
			type: String,
			default: '',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	computed: {
		options() {
			return this.$store.state.classificationPlanItems.map(i => ({
				id: i.uuid,
				label: classificationPlanLabel(i.code, i.title),
			}))
		},
		selected() {
			if (!this.modelValue) {
				return null
			}
			return this.options.find(o => o.id === this.modelValue)
				?? { id: this.modelValue, label: this.currentLabel || this.modelValue }
		},
	},

	mounted() {
		this.$store.dispatch('fetchClassificationPlanItems').catch(() => {})
	},
}
</script>
