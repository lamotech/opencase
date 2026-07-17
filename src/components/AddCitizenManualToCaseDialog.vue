<template>
	<NcDialog :name="t('opencase', 'Tilføj borger (manuel)')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="add-citizen">
			<p class="add-citizen__hint">
				{{ t('opencase', 'Datafordeler-opslag er ikke tilgængeligt på denne installation. Indtast borgerens oplysninger manuelt.') }}
			</p>

			<!-- Role dropdown -->
			<div class="add-citizen__field">
				<label>{{ t('opencase', 'Rolle') }} *</label>
				<NcSelect v-model="selectedRole"
					:options="roleOptions"
					:placeholder="t('opencase', 'Vælg rolle')"
					:loading="rolesLoading" />
			</div>

			<!-- CPR -->
			<div class="add-citizen__field">
				<label>{{ t('opencase', 'CPR-nummer') }}</label>
				<NcTextField v-model="cpr" :placeholder="t('opencase', 'CPR-nummer')" />
				<p v-if="cpr.trim() !== '' && !cprValid" class="add-citizen__error">
					{{ t('opencase', 'Ugyldigt CPR-nummer') }}
				</p>
			</div>

			<!-- Name -->
			<div class="add-citizen__field">
				<label>{{ t('opencase', 'Navn') }} *</label>
				<NcTextField v-model="name" :placeholder="t('opencase', 'Fulde navn')" />
			</div>

			<!-- Address -->
			<div class="add-citizen__field">
				<label>{{ t('opencase', 'Vejnavn') }}</label>
				<NcTextField v-model="streetname" :placeholder="t('opencase', 'Vejnavn')" />
			</div>
			<div class="add-citizen__row">
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Husnummer') }}</label>
					<NcTextField v-model="housenumber" :placeholder="t('opencase', 'Husnr.')" />
				</div>
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Etage') }}</label>
					<NcTextField v-model="floor" :placeholder="t('opencase', 'Etage')" />
				</div>
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Dør') }}</label>
					<NcTextField v-model="door" :placeholder="t('opencase', 'Dør')" />
				</div>
			</div>
			<div class="add-citizen__row">
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Postnummer') }}</label>
					<NcTextField v-model="zipcode" :placeholder="t('opencase', 'Postnr.')" />
				</div>
				<div class="add-citizen__field add-citizen__field--wide">
					<label>{{ t('opencase', 'By') }}</label>
					<NcTextField v-model="zipdistrict" :placeholder="t('opencase', 'By')" />
				</div>
			</div>

			<!-- Contact -->
			<div class="add-citizen__row">
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Telefon') }}</label>
					<NcTextField v-model="phone" :placeholder="t('opencase', 'Telefon')" />
				</div>
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'E-mail') }}</label>
					<NcTextField v-model="email" :placeholder="t('opencase', 'E-mail')" />
				</div>
			</div>

			<NcCheckboxRadioSwitch v-model:checked="hasAddressProtection">
				{{ t('opencase', 'Adressebeskyttelse') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Annuller') }}</NcButton>
			<NcButton type="primary" :disabled="!canSubmit || adding" @click="addCitizen">
				<template #icon>
					<NcLoadingIcon v-if="adding" :size="20" />
					<PlusIcon v-else :size="18" />
				</template>
				{{ t('opencase', 'Tilføj') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'AddCitizenManualToCaseDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon, NcCheckboxRadioSwitch,
		PlusIcon,
	},

	props: {
		caseId: { type: [String, Number], required: true },
	},

	emits: ['close', 'added'],

	data() {
		return {
			selectedRole: null,
			roleOptions: [],
			rolesLoading: false,
			cpr: '',
			name: '',
			streetname: '',
			housenumber: '',
			floor: '',
			door: '',
			zipcode: '',
			zipdistrict: '',
			phone: '',
			email: '',
			hasAddressProtection: false,
			adding: false,
		}
	},

	computed: {
		cprValid() {
			return /^\d{6}-?\d{4}$/.test(this.cpr.trim())
		},
		canSubmit() {
			return !!this.selectedRole
				&& this.name.trim() !== ''
				&& (this.cpr.trim() === '' || this.cprValid)
		},
	},

	async created() {
		this.rolesLoading = true
		try {
			const roles = await api.getParticipantRoles()
			this.roleOptions = roles.map(r => ({ id: r.id, label: r.name }))
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente roller'))
		} finally {
			this.rolesLoading = false
		}
	},

	methods: {
		async addCitizen() {
			if (!this.canSubmit) return
			this.adding = true
			try {
				const participant = await api.createCaseParticipant(this.caseId, {
					participantrole_id: this.selectedRole.id,
					cpr_cvr: this.cpr.trim(),
					name: this.name.trim(),
					streetname: this.streetname.trim(),
					housenumber: this.housenumber.trim(),
					floor: this.floor.trim(),
					door: this.door.trim(),
					zipcode: this.zipcode.trim(),
					zipdistrict: this.zipdistrict.trim(),
					phone: this.phone.trim(),
					email: this.email.trim(),
					has_address_protection: this.hasAddressProtection ? 1 : 0,
				})
				this.$emit('added', participant)
				this.$emit('close')
			} catch (e) {
				if (e.response?.status === 409) {
					showError(t('opencase', 'Denne borger er allerede tilføjet med den valgte rolle'))
				} else {
					showError(t('opencase', 'Kunne ikke tilføje borger'))
				}
			} finally {
				this.adding = false
			}
		},
	},
}
</script>

<style scoped>
.add-citizen {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
	width: 100%;
}

.add-citizen__hint {
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 0;
}

.add-citizen__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.add-citizen__field label {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-lighter);
}

.add-citizen__error {
	color: var(--color-error);
	font-size: 0.85em;
	margin: 0;
}

.add-citizen__row {
	display: flex;
	gap: 12px;
}

.add-citizen__row .add-citizen__field {
	flex: 1;
}

.add-citizen__row .add-citizen__field--wide {
	flex: 2;
}
</style>
