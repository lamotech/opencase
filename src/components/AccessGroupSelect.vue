<template>
	<NcSelect :model-value="selected"
		:options="options"
		:placeholder="t('opencase', 'Vælg adgangsgruppe')"
		:input-label="inputLabel"
		:disabled="disabled"
		@update:model-value="v => $emit('update:modelValue', v?.id || '')">
		<template #no-options>
			{{ t('opencase', 'Ingen adgangsgrupper. En administrator skal tilføje Nextcloud-grupper under Indstillinger → Administration → OpenCase → Adgangsprofiler.') }}
		</template>
	</NcSelect>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'

/**
 * Picker for a case's access group (access profile type Grupper).
 *
 * v-model is the Nextcloud group id. Lists the registered access groups,
 * the ones the current user can write to first; the others are marked,
 * since a case moved to one of them is out of the user's reach unless
 * they are its responsible user.
 */
export default {
	name: 'AccessGroupSelect',

	components: { NcSelect },

	props: {
		modelValue: {
			type: String,
			default: '',
		},
		/** Label of the current group, shown while the list loads or if the group is no longer registered. */
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
			return [...this.$store.state.accessGroups]
				.sort((a, b) => Number(b.can_write) - Number(a.can_write))
				.map(g => ({
					id: g.group_id,
					label: g.can_write
						? g.group_name
						: t('opencase', '{name} (ikke medlem)', { name: g.group_name }),
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
		this.$store.dispatch('fetchAccessGroups')
	},
}
</script>
