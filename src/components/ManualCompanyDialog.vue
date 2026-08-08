<template>
	<NcDialog :name="t('opencase', 'Indtast virksomhed')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="add-company">
			<p class="add-company__hint">
				{{ t('opencase', 'Datafordeler-opslag er ikke tilgængeligt på denne installation. Indtast virksomhedens oplysninger manuelt.') }}
			</p>

			<!-- CVR -->
			<div class="add-company__field">
				<label>{{ t('opencase', 'CVR-nummer') }}</label>
				<NcTextField v-model="cvr" :placeholder="t('opencase', 'CVR-nummer')" />
				<p v-if="cvr.trim() !== '' && !cvrValid" class="add-company__error">
					{{ t('opencase', 'Ugyldigt CVR-nummer') }}
				</p>
			</div>

			<!-- P-nummer -->
			<div class="add-company__field">
				<label>{{ t('opencase', 'P-nummer') }}</label>
				<NcTextField v-model="pnumber" :placeholder="t('opencase', 'P-nummer (valgfrit)')" />
			</div>

			<!-- Name -->
			<div class="add-company__field">
				<label>{{ t('opencase', 'Navn') }} *</label>
				<NcTextField v-model="name" :placeholder="t('opencase', 'Virksomhedsnavn')" />
			</div>

			<!-- Address -->
			<div class="add-company__field">
				<label>{{ t('opencase', 'Vejnavn') }}</label>
				<NcTextField v-model="streetname" :placeholder="t('opencase', 'Vejnavn')" />
			</div>
			<div class="add-company__row">
				<div class="add-company__field">
					<label>{{ t('opencase', 'Husnummer') }}</label>
					<NcTextField v-model="housenumber" :placeholder="t('opencase', 'Husnr.')" />
				</div>
				<div class="add-company__field">
					<label>{{ t('opencase', 'Etage') }}</label>
					<NcTextField v-model="floor" :placeholder="t('opencase', 'Etage')" />
				</div>
				<div class="add-company__field">
					<label>{{ t('opencase', 'Dør') }}</label>
					<NcTextField v-model="door" :placeholder="t('opencase', 'Dør')" />
				</div>
			</div>
			<div class="add-company__row">
				<div class="add-company__field">
					<label>{{ t('opencase', 'Postnummer') }}</label>
					<NcTextField v-model="zipcode" :placeholder="t('opencase', 'Postnr.')" />
				</div>
				<div class="add-company__field add-company__field--wide">
					<label>{{ t('opencase', 'By') }}</label>
					<NcTextField v-model="zipdistrict" :placeholder="t('opencase', 'By')" />
				</div>
			</div>

			<!-- Contact -->
			<div class="add-company__row">
				<div class="add-company__field">
					<label>{{ t('opencase', 'Telefon') }}</label>
					<NcTextField v-model="phone" :placeholder="t('opencase', 'Telefon')" />
				</div>
				<div class="add-company__field">
					<label>{{ t('opencase', 'E-mail') }}</label>
					<NcTextField v-model="email" :placeholder="t('opencase', 'E-mail')" />
				</div>
			</div>
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

export default {
	name: 'ManualCompanyDialog',

	components: {
		NcDialog, NcButton, NcTextField,
	},

	emits: ['close', 'selected'],

	data() {
		return {
			cvr: '',
			pnumber: '',
			name: '',
			streetname: '',
			housenumber: '',
			floor: '',
			door: '',
			zipcode: '',
			zipdistrict: '',
			phone: '',
			email: '',
		}
	},

	computed: {
		cvrValid() {
			return /^\d{8}$/.test(this.cvr.trim())
		},
		canSubmit() {
			return this.name.trim() !== '' && (this.cvr.trim() === '' || this.cvrValid)
		},
	},

	methods: {
		submit() {
			if (!this.canSubmit) return
			this.$emit('selected', {
				cpr_cvr: this.cvr.trim(),
				pnumber: this.pnumber.trim() || null,
				name: this.name.trim(),
				streetname: this.streetname.trim(),
				housenumber: this.housenumber.trim(),
				floor: this.floor.trim(),
				door: this.door.trim(),
				zipcode: this.zipcode.trim(),
				zipdistrict: this.zipdistrict.trim(),
				phone: this.phone.trim(),
				email: this.email.trim(),
			})
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.add-company {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
	width: 100%;
}

.add-company__hint {
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 0;
}

.add-company__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.add-company__field label {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-lighter);
}

.add-company__error {
	color: var(--color-error);
	font-size: 0.85em;
	margin: 0;
}

.add-company__row {
	display: flex;
	gap: 12px;
}

.add-company__row .add-company__field {
	flex: 1;
}

.add-company__row .add-company__field--wide {
	flex: 2;
}
</style>
