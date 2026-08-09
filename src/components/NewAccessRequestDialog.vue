<template>
	<NcDialog :name="t('opencase', 'Ny aktindsigtsanmodning')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="oc-new-ar">
			<label class="oc-new-ar__select-label">{{ t('opencase', 'Type') }}</label>
			<NcSelect v-model="form.type"
				:options="typeOptions"
				label="label"
				:placeholder="t('opencase', 'Vælg type...')"
				class="oc-new-ar__field" />

			<NcTextField v-model="form.subject"
				:label="t('opencase', 'Emne / hvad ønskes der aktindsigt i')"
				:required="true"
				class="oc-new-ar__field" />

			<NcTextField v-model="form.requester_name"
				:label="t('opencase', 'Anmoders navn')"
				class="oc-new-ar__field" />

			<NcTextField v-model="form.requester_email"
				:label="t('opencase', 'E-mail')"
				type="email"
				class="oc-new-ar__field" />

			<NcTextField v-model="form.requester_phone"
				:label="t('opencase', 'Telefon')"
				class="oc-new-ar__field" />

			<NcTextField v-model="form.requester_identifier"
				:label="t('opencase', 'CPR / CVR (valgfri)')"
				class="oc-new-ar__field" />

			<NcTextArea v-model="form.description"
				:label="t('opencase', 'Yderligere beskrivelse')"
				class="oc-new-ar__field" />

			<p v-if="form.type" class="oc-new-ar__deadline-hint">
				<ClockOutlineIcon :size="14" />
				{{ deadlineHint }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !form.subject || !form.type" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('opencase', 'Opret') }}
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'NewAccessRequestDialog',
	components: { NcDialog, NcButton, NcTextField, NcTextArea, NcSelect, NcLoadingIcon, ClockOutlineIcon },
	props: {
		caseId: { type: [Number, String], required: true },
	},
	emits: ['close', 'created'],
	data() {
		return {
			saving: false,
			form: {
				type: null,
				subject: '',
				requester_name: '',
				requester_email: '',
				requester_phone: '',
				requester_identifier: '',
				description: '',
			},
			typeOptions: [
				{ id: 'public_access',  label: t('opencase', 'Offentlighedsloven') },
				{ id: 'party_access',   label: t('opencase', 'Forvaltningsloven (partsaktindsigt)') },
				{ id: 'gdpr_access',    label: t('opencase', 'Databeskyttelsesindsigt (GDPR)') },
			],
		}
	},
	computed: {
		deadlineHint() {
			if (!this.form.type) return ''
			if (this.form.type.id === 'gdpr_access') {
				return t('opencase', 'Frist: 30 kalenderdage (GDPR art. 12)')
			}
			return t('opencase', 'Frist: 7 arbejdsdage (Offentlighedsloven §36)')
		},
	},
	methods: {
		async submit() {
			this.saving = true
			try {
				const req = await api.createAccessRequest(this.caseId, {
					type:                   this.form.type?.id,
					subject:                this.form.subject,
					requester_name:         this.form.requester_name,
					requester_email:        this.form.requester_email,
					requester_phone:        this.form.requester_phone,
					requester_identifier:   this.form.requester_identifier,
					description:            this.form.description,
				})
				this.$emit('created', req)
			} catch {
				showError(t('opencase', 'Kunne ikke oprette aktindsigtssagen'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.oc-new-ar__select-label { display: block; font-weight: 500; margin-bottom: 4px; font-size: 0.9em; }
.oc-new-ar__field { margin-bottom: 12px; }
.oc-new-ar__deadline-hint {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
