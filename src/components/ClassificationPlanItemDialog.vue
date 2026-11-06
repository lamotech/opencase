<template>
	<NcDialog :name="item ? t('opencase', 'Rediger emne') : t('opencase', 'Nyt emne')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="oc-plan-item">
			<NcTextField v-model="form.title"
				:label="t('opencase', 'Emne')"
				:required="true" />
			<NcTextField v-model="form.code"
				:label="t('opencase', 'Kode (valgfri)')"
				:helper-text="t('opencase', 'Uden kode bruges emnets navn.')" />
			<div class="oc-plan-item__field">
				<label>{{ t('opencase', 'Slettefrist') }}</label>
				<NcSelect v-model="selectedDeadline"
					:options="deadlineOptions"
					:clearable="true"
					:placeholder="t('opencase', 'Ingen slettefrist')" />
			</div>
			<NcTextArea v-model="form.description"
				:label="t('opencase', 'Beskrivelse')"
				resize="vertical"
				rows="4" />
			<NcCheckboxRadioSwitch v-model="form.active" type="switch">
				{{ t('opencase', 'Aktiv') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !form.title.trim()" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('opencase', 'Gem') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

const errorMessage = (e, fallback) => e?.response?.data?.ocs?.data?.error ?? fallback

/**
 * Create or edit an Emneplan item.
 */
export default {
	name: 'ClassificationPlanItemDialog',
	components: { NcDialog, NcButton, NcTextField, NcTextArea, NcSelect, NcCheckboxRadioSwitch, NcLoadingIcon },
	props: {
		// Item to edit; null creates a new one
		item: { type: Object, default: null },
		// Slettefrister to choose from: [{ id, code, name, expired }]
		deletionDeadlines: { type: Array, default: () => [] },
	},
	emits: ['close', 'saved'],
	data() {
		const i = this.item
		return {
			saving: false,
			form: {
				code: i?.code ?? '',
				title: i?.title ?? '',
				description: i?.description ?? '',
				active: i?.active ?? true,
			},
			selectedDeadline: null,
		}
	},
	computed: {
		// Expired slettefrister can't be picked, except the one the item already has
		deadlineOptions() {
			return this.deletionDeadlines
				.filter(d => !d.expired || d.id === this.item?.deletion_deadline_id)
				.map(d => ({ id: d.id, label: d.name }))
		},
	},
	watch: {
		deadlineOptions: {
			immediate: true,
			handler(options) {
				if (!this.selectedDeadline && this.item?.deletion_deadline_id) {
					this.selectedDeadline = options.find(o => o.id === this.item.deletion_deadline_id) ?? null
				}
			},
		},
	},
	methods: {
		async save() {
			this.saving = true
			try {
				const payload = { ...this.form, deletion_deadline_id: this.selectedDeadline?.id ?? '' }
				const saved = this.item
					? await api.updateClassificationPlanItem(this.item.uuid, payload)
					: await api.createClassificationPlanItem(payload)
				this.$emit('saved', saved)
			} catch (e) {
				showError(errorMessage(e, t('opencase', 'Emnet kunne ikke gemmes')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.oc-plan-item { display: flex; flex-direction: column; gap: 10px; }
.oc-plan-item__field { display: flex; flex-direction: column; gap: 4px; }
.oc-plan-item__field label { font-weight: 500; font-size: 0.9em; }
</style>
