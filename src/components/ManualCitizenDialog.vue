<template>
	<NcDialog :name="t('opencase', 'Indtast borger')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="add-citizen">
			<p class="add-citizen__hint">
				{{ t('opencase', 'Datafordeler-opslag er ikke tilgængeligt på denne installation. Indtast borgerens oplysninger manuelt.') }}
			</p>

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
			<NcButton type="primary" :disabled="!canSubmit" @click="submit">
				{{ t('opencase', 'Vælg') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

export default {
	name: 'ManualCitizenDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcCheckboxRadioSwitch,
	},

	emits: ['close', 'selected'],

	data() {
		return {
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
		}
	},

	computed: {
		cprValid() {
			return /^\d{6}-?\d{4}$/.test(this.cpr.trim())
		},
		canSubmit() {
			return this.name.trim() !== '' && (this.cpr.trim() === '' || this.cprValid)
		},
	},

	methods: {
		submit() {
			if (!this.canSubmit) return
			this.$emit('selected', {
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
				has_address_protection: this.hasAddressProtection,
			})
			this.$emit('close')
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
