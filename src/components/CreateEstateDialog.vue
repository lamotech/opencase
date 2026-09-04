<template>
	<NcDialog :name="mode === 'edit' ? t('opencase', 'Rediger ejendom') : t('opencase', 'Opret ejendom')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="create-estate">
			<div class="create-estate__field">
				<label>{{ t('opencase', 'Type') }} *</label>
				<NcSelect v-model="type"
					:options="typeOptions"
					:disabled="mode === 'edit'"
					:clearable="false" />
			</div>

			<div class="create-estate__row">
				<div class="create-estate__field">
					<label>{{ t('opencase', 'BFE Nummer') }}</label>
					<NcTextField v-model="bfenummer" :placeholder="t('opencase', 'BFE Nummer')" />
				</div>
				<div class="create-estate__field create-estate__field--wide">
					<label>{{ t('opencase', 'Beliggenhedsadresse') }}</label>
					<NcTextField v-model="locationAddress" :placeholder="t('opencase', 'Beliggenhedsadresse')" />
				</div>
			</div>

			<!-- Ejerlejlighed-only fields -->
			<template v-if="type === 'Ejerlejlighed'">
				<div class="create-estate__row">
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Etage') }}</label>
						<NcTextField v-model="floor" :placeholder="t('opencase', 'Etage')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Dør') }}</label>
						<NcTextField v-model="door" :placeholder="t('opencase', 'Dør')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Ejerlejlighedsnummer') }}</label>
						<NcTextField v-model="apartmentNumber" :placeholder="t('opencase', 'Ejerlejlighedsnummer')" />
					</div>
				</div>
				<div class="create-estate__row">
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Fordelingstal') }}</label>
						<div class="create-estate__fraction">
							<NcTextField v-model="allocFactorNom" :placeholder="t('opencase', 'Tæller')" />
							<span>/</span>
							<NcTextField v-model="allocFactorDenom" :placeholder="t('opencase', 'Nævner')" />
						</div>
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Areal') }}</label>
						<NcTextField v-model="area" :placeholder="t('opencase', 'Areal')" />
					</div>
				</div>
			</template>

			<!-- Samlet fast ejendom fields — shown directly for that type, or grouped
			     in a "Samlet fast ejendom" section (the enclosing aggregated
			     estate, with its own BFE/address) for the other two types -->
			<template v-if="type === 'Samlet fast ejendom'">
				<div class="create-estate__row">
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Kommunekode') }}</label>
						<NcTextField v-model="municipalityCode" :placeholder="t('opencase', 'Kommunekode')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Vejnavn') }}</label>
						<NcTextField v-model="streetname" :placeholder="t('opencase', 'Vejnavn')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Vejkode') }}</label>
						<NcTextField v-model="roadCode" :placeholder="t('opencase', 'Vejkode')" />
					</div>
				</div>
				<div class="create-estate__row">
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Husnummer') }}</label>
						<NcTextField v-model="housenumber" :placeholder="t('opencase', 'Husnummer')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Postnummer') }}</label>
						<NcTextField v-model="zipcode" :placeholder="t('opencase', 'Postnummer')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Postdistrikt') }}</label>
						<NcTextField v-model="zipdistrict" :placeholder="t('opencase', 'Postdistrikt')" />
					</div>
				</div>
				<div class="create-estate__row">
					<div class="create-estate__field create-estate__field--wide">
						<label>{{ t('opencase', 'Supplerende bynavn') }}</label>
						<NcTextField v-model="additionalCityName" :placeholder="t('opencase', 'Supplerende bynavn')" />
					</div>
				</div>
			</template>

			<div v-else class="create-estate__section">
				<h3>{{ t('opencase', 'Samlet fast ejendom') }}</h3>
				<div class="create-estate__row">
					<div class="create-estate__field">
						<label>{{ t('opencase', 'BFE Nummer') }}</label>
						<NcTextField v-model="sectionBfenummer" :placeholder="t('opencase', 'BFE Nummer')" />
					</div>
					<div class="create-estate__field create-estate__field--wide">
						<label>{{ t('opencase', 'Beliggenhedsadresse') }}</label>
						<NcTextField v-model="sectionLocationAddress" :placeholder="t('opencase', 'Beliggenhedsadresse')" />
					</div>
				</div>
				<div class="create-estate__row">
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Kommunekode') }}</label>
						<NcTextField v-model="municipalityCode" :placeholder="t('opencase', 'Kommunekode')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Vejnavn') }}</label>
						<NcTextField v-model="streetname" :placeholder="t('opencase', 'Vejnavn')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Vejkode') }}</label>
						<NcTextField v-model="roadCode" :placeholder="t('opencase', 'Vejkode')" />
					</div>
				</div>
				<div class="create-estate__row">
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Husnummer') }}</label>
						<NcTextField v-model="housenumber" :placeholder="t('opencase', 'Husnummer')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Postnummer') }}</label>
						<NcTextField v-model="zipcode" :placeholder="t('opencase', 'Postnummer')" />
					</div>
					<div class="create-estate__field">
						<label>{{ t('opencase', 'Postdistrikt') }}</label>
						<NcTextField v-model="zipdistrict" :placeholder="t('opencase', 'Postdistrikt')" />
					</div>
				</div>
				<div class="create-estate__row">
					<div class="create-estate__field create-estate__field--wide">
						<label>{{ t('opencase', 'Supplerende bynavn') }}</label>
						<NcTextField v-model="additionalCityName" :placeholder="t('opencase', 'Supplerende bynavn')" />
					</div>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Annuller') }}</NcButton>
			<NcButton type="primary" :disabled="!canSubmit || creating" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="creating" :size="20" />
					<PlusIcon v-else :size="18" />
				</template>
				{{ mode === 'edit' ? t('opencase', 'Gem') : t('opencase', 'Opret') }}
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
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'CreateEstateDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon, PlusIcon,
	},

	props: {
		mode: { type: String, default: 'create' }, // 'create' | 'edit'
		estateId: { type: [String, Number], default: null }, // required for mode='edit'
		initialEstate: { type: Object, default: null }, // required for mode='edit' — same shape as api.getEstateById()
	},

	emits: ['close', 'created', 'updated'],

	data() {
		return {
			typeOptions: ['Samlet fast ejendom', 'Ejerlejlighed', 'Bygning på fremmed grund'],
			type: 'Samlet fast ejendom',

			// Top-level BFE/address — the estate's own (aggregated estate for
			// type=Samlet fast ejendom, apartment/building otherwise).
			bfenummer: '',
			locationAddress: '',

			// The enclosing "Samlet fast ejendom" section's own BFE/address —
			// only used/shown when type !== 'Samlet fast ejendom'.
			sectionBfenummer: '',
			sectionLocationAddress: '',

			// Aggregated estate descriptive fields — describe whichever
			// aggregated estate is relevant (top-level or section, above).
			municipalityCode: '',
			streetname: '',
			roadCode: '',
			housenumber: '',
			zipcode: '',
			zipdistrict: '',
			additionalCityName: '',

			// Ejerlejlighed-only fields
			floor: '',
			door: '',
			apartmentNumber: '',
			allocFactorNom: '',
			allocFactorDenom: '',
			area: '',

			creating: false,
		}
	},

	computed: {
		canSubmit() {
			return /^\d+$/.test(this.bfenummer.trim())
		},
	},

	created() {
		if (this.mode === 'edit' && this.initialEstate) {
			this.populateFromEstate(this.initialEstate)
		}
	},

	methods: {
		numToStr(value) {
			return value === null || value === undefined ? '' : String(value)
		},

		setAggregatedFields(agg) {
			this.municipalityCode = agg?.municipality_code ?? ''
			this.streetname = agg?.streetname ?? ''
			this.roadCode = agg?.road_code ?? ''
			this.housenumber = agg?.housenumber ?? ''
			this.zipcode = agg?.zipcode ?? ''
			this.zipdistrict = agg?.zipdistrict ?? ''
			this.additionalCityName = agg?.additional_city_name ?? ''
		},

		populateFromEstate(estate) {
			this.type = estate.type

			if (estate.type === 'Samlet fast ejendom') {
				this.bfenummer = this.numToStr(estate.aggregated_estate?.bfenumber)
				this.locationAddress = estate.aggregated_estate?.location_address ?? ''
				this.setAggregatedFields(estate.aggregated_estate)
				return
			}

			this.sectionBfenummer = this.numToStr(estate.aggregated_estate?.bfenumber)
			this.sectionLocationAddress = estate.aggregated_estate?.location_address ?? ''
			this.setAggregatedFields(estate.aggregated_estate)

			if (estate.type === 'Ejerlejlighed') {
				this.bfenummer = this.numToStr(estate.apartment?.bfenumber)
				this.locationAddress = estate.apartment?.location_address ?? ''
				this.floor = estate.apartment?.floor ?? ''
				this.door = estate.apartment?.door ?? ''
				this.apartmentNumber = estate.apartment?.apartment_number ?? ''
				this.allocFactorNom = this.numToStr(estate.apartment?.alloc_factor_nom)
				this.allocFactorDenom = this.numToStr(estate.apartment?.alloc_factor_denom)
				this.area = this.numToStr(estate.apartment?.area)
			} else if (estate.type === 'Bygning på fremmed grund') {
				this.bfenummer = this.numToStr(estate.building_land_by_other?.bfenumber)
				this.locationAddress = estate.building_land_by_other?.location_address ?? ''
			}
		},

		toIntOrNull(value) {
			const trimmed = String(value ?? '').trim()
			return trimmed === '' ? null : parseInt(trimmed, 10)
		},

		toStrOrNull(value) {
			const trimmed = String(value ?? '').trim()
			return trimmed === '' ? null : trimmed
		},

		buildAggregatedEstate(bfenummer, locationAddress) {
			return {
				bfenumber: this.toIntOrNull(bfenummer),
				location_address: this.toStrOrNull(locationAddress),
				municipality_code: this.toStrOrNull(this.municipalityCode),
				streetname: this.toStrOrNull(this.streetname),
				road_code: this.toStrOrNull(this.roadCode),
				housenumber: this.toStrOrNull(this.housenumber),
				zipcode: this.toStrOrNull(this.zipcode),
				zipdistrict: this.toStrOrNull(this.zipdistrict),
				additional_city_name: this.toStrOrNull(this.additionalCityName),
			}
		},

		buildPayload() {
			const payload = {
				type: this.type,
				bfenummer: this.toIntOrNull(this.bfenummer),
				aggregated_estate: this.type === 'Samlet fast ejendom'
					? this.buildAggregatedEstate(this.bfenummer, this.locationAddress)
					: this.buildAggregatedEstate(this.sectionBfenummer, this.sectionLocationAddress),
			}

			if (this.type === 'Ejerlejlighed') {
				payload.apartment = {
					bfenumber: this.toIntOrNull(this.bfenummer),
					location_address: this.toStrOrNull(this.locationAddress),
					floor: this.toStrOrNull(this.floor),
					door: this.toStrOrNull(this.door),
					apartment_number: this.toStrOrNull(this.apartmentNumber),
					alloc_factor_nom: this.toIntOrNull(this.allocFactorNom),
					alloc_factor_denom: this.toIntOrNull(this.allocFactorDenom),
					area: this.toIntOrNull(this.area),
				}
			} else if (this.type === 'Bygning på fremmed grund') {
				payload.building_land_by_other = {
					bfenumber: this.toIntOrNull(this.bfenummer),
					location_address: this.toStrOrNull(this.locationAddress),
				}
			}

			return payload
		},

		async submit() {
			if (!this.canSubmit) return

			this.creating = true
			try {
				if (this.mode === 'edit') {
					await api.updateEstate(this.estateId, this.buildPayload())
					showSuccess(t('opencase', 'Ejendom opdateret'))
					this.$emit('updated')
				} else {
					const estateId = await api.createEstate(this.buildPayload())
					showSuccess(t('opencase', 'Ejendom oprettet'))
					this.$emit('created', estateId)
				}
				this.$emit('close')
			} catch (e) {
				showError(t('opencase', this.mode === 'edit' ? 'Kunne ikke opdatere ejendom' : 'Kunne ikke oprette ejendom'))
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.create-estate {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
	width: 100%;
}

.create-estate__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}

.create-estate__field label {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-lighter);
}

.create-estate__row {
	display: flex;
	gap: 12px;
}

.create-estate__field--wide {
	flex: 2;
}

.create-estate__fraction {
	display: flex;
	align-items: center;
	gap: 8px;
}

.create-estate__section {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.create-estate__section h3 {
	margin: 0;
	font-size: 1em;
}
</style>
