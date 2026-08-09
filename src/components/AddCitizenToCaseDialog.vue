<template>
	<NcDialog :name="t('opencase', 'Tilføj borger')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="add-citizen">
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
				<NcTextField v-model="cpr"
					:placeholder="t('opencase', 'Søg på CPR-nummer')"
					@keydown.enter="search" />
			</div>

			<!-- Name -->
			<div class="add-citizen__row">
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Fornavn') }}</label>
					<NcTextField v-model="firstname"
						:placeholder="t('opencase', 'Søg på fornavn')"
						:disabled="cpr.trim() !== ''"
						@keydown.enter="search" />
				</div>
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Efternavn') }}</label>
					<NcTextField v-model="lastname"
						:placeholder="t('opencase', 'Søg på efternavn')"
						:disabled="cpr.trim() !== ''"
						@keydown.enter="search" />
				</div>
			</div>

			<!-- Address fields (only shown when not searching by CPR) -->
			<template v-if="cpr.trim() === ''">
				<div class="add-citizen__field">
					<label>{{ t('opencase', 'Vejnavn') }}</label>
					<NcTextField v-model="streetname"
						:placeholder="t('opencase', 'Vejnavn')"
						@keydown.enter="search" />
				</div>

				<div class="add-citizen__row">
					<div class="add-citizen__field">
						<label>{{ t('opencase', 'Husnummer') }}</label>
						<NcTextField v-model="housenumber"
							:placeholder="t('opencase', 'Husnr.')"
							@keydown.enter="search" />
					</div>
					<div class="add-citizen__field">
						<label>{{ t('opencase', 'Postnummer') }}</label>
						<NcTextField v-model="zipcode"
							:placeholder="t('opencase', 'Postnr.')"
							@keydown.enter="search" />
					</div>
					<div class="add-citizen__field add-citizen__field--wide">
						<label>{{ t('opencase', 'By') }}</label>
						<NcTextField v-model="zipdistrict"
							:placeholder="t('opencase', 'By')"
							@keydown.enter="search" />
					</div>
				</div>
			</template>

			<!-- Search button -->
			<NcButton type="primary"
				:disabled="searching || !selectedRole || !hasSearchCriteria"
				@click="search">
				<template #icon>
					<NcLoadingIcon v-if="searching" :size="20" />
					<MagnifyIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Søg') }}
			</NcButton>

			<!-- Results -->
			<div v-if="searched" class="add-citizen__results">
				<p v-if="results.length === 0" class="add-citizen__no-results">
					{{ t('opencase', 'Ingen borgere fundet') }}
				</p>
				<table v-else class="add-citizen__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'CPR') }}</th>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Adresse') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(citizen, idx) in results" :key="idx">
							<td class="add-citizen__mono"><CprDisplay :value="citizen.cpr_cvr" /></td>
							<td>{{ citizen.name }}</td>
							<td>{{ formatAddress(citizen) }}</td>
							<td>
								<NcButton :disabled="adding"
									@click="addCitizen(citizen)">
									<template #icon>
										<PlusIcon :size="18" />
									</template>
									{{ t('opencase', 'Tilføj') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Luk') }}</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'
import CprDisplay from './CprDisplay.vue'

export default {
	name: 'AddCitizenToCaseDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon,
		MagnifyIcon, PlusIcon, CprDisplay,
	},

	props: {
		caseId: { type: [String, Number], default: null },
	},

	emits: ['close', 'added'],

	data() {
		return {
			selectedRole: null,
			roleOptions: [],
			rolesLoading: false,
			cpr: '',
			firstname: '',
			lastname: '',
			streetname: '',
			housenumber: '',
			zipcode: '',
			zipdistrict: '',
			searching: false,
			searched: false,
			results: [],
			adding: false,
		}
	},

	computed: {
		hasSearchCriteria() {
			return this.cpr.trim() !== ''
				|| this.firstname.trim() !== ''
				|| this.lastname.trim() !== ''
				|| this.streetname.trim() !== ''
				|| this.housenumber.trim() !== ''
				|| this.zipcode.trim() !== ''
				|| this.zipdistrict.trim() !== ''
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
		async search() {
			if (!this.selectedRole || !this.hasSearchCriteria) return

			this.searching = true
			this.searched  = false
			this.results   = []
			try {
				const params = {}
				if (this.cpr.trim() !== '') {
					params.cpr = this.cpr.trim()
				} else {
					if (this.firstname.trim())   params.firstname   = this.firstname.trim()
					if (this.lastname.trim())    params.lastname    = this.lastname.trim()
					if (this.streetname.trim())  params.streetname  = this.streetname.trim()
					if (this.housenumber.trim()) params.housenumber = this.housenumber.trim()
					if (this.zipcode.trim())     params.zipcode     = this.zipcode.trim()
					if (this.zipdistrict.trim()) params.zipdistrict = this.zipdistrict.trim()
				}
				this.results = await api.searchCitizen(params)
				this.searched = true

				if (this.results.length === 1) {
					await this.addCitizen(this.results[0])
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		async addCitizen(citizen) {
			const payload = {
				participantrole_id:     this.selectedRole.id,
				cpr_cvr:                citizen.cpr_cvr,
				name:                   citizen.name,
				streetname:             citizen.streetname,
				housenumber:            citizen.housenumber,
				floor:                  citizen.floor,
				door:                   citizen.door,
				zipcode:                citizen.zipcode,
				zipdistrict:            citizen.zipdistrict,
				phone:                  citizen.phone,
				email:                  citizen.email,
				has_address_protection: citizen.has_address_protection ? 1 : 0,
			}

			if (!this.caseId) {
				this.$emit('added', { id: 'pending-' + Date.now() + '-' + Math.random(), role_name: this.selectedRole.label, ...payload })
				this.$emit('close')
				return
			}

			this.adding = true
			try {
				const participant = await api.createCaseParticipant(this.caseId, payload)
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

		formatAddress(citizen) {
			const street = [citizen.streetname, citizen.housenumber, citizen.floor, citizen.door]
				.filter(Boolean).join(' ')
			const city = [citizen.zipcode, citizen.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
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

.add-citizen__no-results {
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 8px 0 0;
}

.add-citizen__results {
	margin-top: 8px;
	overflow-x: auto;
}

.add-citizen__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.add-citizen__table th,
.add-citizen__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.add-citizen__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
}

.add-citizen__mono {
	font-family: monospace;
	white-space: nowrap;
}
</style>
