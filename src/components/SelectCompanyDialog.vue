<template>
	<NcDialog :name="t('opencase', 'Vælg virksomhed')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="add-company">
			<!-- Search type -->
			<div class="add-company__search-type">
				<label class="add-company__radio-label">
					<input type="radio" v-model="searchMode" value="company" />
					{{ t('opencase', 'Virksomhed') }}
				</label>
				<label class="add-company__radio-label">
					<input type="radio" v-model="searchMode" value="pnumber" />
					{{ t('opencase', 'P nummer') }}
				</label>
			</div>

			<!-- CVR -->
			<div class="add-company__field">
				<label>{{ t('opencase', 'CVR-nummer') }}</label>
				<NcTextField v-model="cvr"
					:placeholder="t('opencase', 'Søg på CVR-nummer')"
					@keydown.enter="search" />
			</div>

			<!-- Name -->
			<div class="add-company__field">
				<label>{{ t('opencase', 'Navn') }}</label>
				<NcTextField v-model="name"
					:placeholder="t('opencase', 'Søg på virksomhedsnavn')"
					:disabled="cvr.trim() !== '' || searchMode === 'pnumber'"
					@keydown.enter="search" />
			</div>

			<!-- Search button -->
			<NcButton type="primary"
				:disabled="searching || (searchMode === 'pnumber' ? cvr.trim() === '' : cvr.trim() === '' && name.trim() === '')"
				@click="search">
				<template #icon>
					<NcLoadingIcon v-if="searching" :size="20" />
					<MagnifyIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Søg') }}
			</NcButton>

			<!-- Results -->
			<div v-if="searched" class="add-company__results">
				<p v-if="results.length === 0" class="add-company__no-results">
					{{ t('opencase', 'Ingen virksomheder fundet') }}
				</p>
				<table v-else class="add-company__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'CVR') }}</th>
							<th v-if="searchMode === 'pnumber'">{{ t('opencase', 'P-nummer') }}</th>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Adresse') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(company, idx) in results" :key="idx">
							<td class="add-company__mono">{{ company.cpr_cvr }}</td>
							<td v-if="searchMode === 'pnumber'" class="add-company__mono">{{ company.pnumber }}</td>
							<td>{{ company.name }}</td>
							<td>{{ formatAddress(company) }}</td>
							<td>
								<NcButton @click="selectCompany(company)">
									<template #icon>
										<CheckIcon :size="18" />
									</template>
									{{ t('opencase', 'Vælg') }}
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'SelectCompanyDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcLoadingIcon,
		MagnifyIcon, CheckIcon,
	},

	emits: ['close', 'selected'],

	data() {
		return {
			searchMode: 'company',
			cvr: '',
			name: '',
			searching: false,
			searched: false,
			results: [],
		}
	},

	methods: {
		async search() {
			const cvr  = this.cvr.trim()
			const name = this.name.trim()
			if (this.searchMode === 'pnumber' ? cvr === '' : cvr === '' && name === '') return

			this.searching = true
			this.searched  = false
			this.results   = []
			try {
				let params
				if (this.searchMode === 'pnumber') {
					params = { cvr, mode: 'pnumber' }
				} else if (cvr !== '') {
					params = { cvr }
				} else {
					params = { name }
				}
				this.results = await api.searchCompany(params)
				this.searched = true

				if (this.results.length === 1) {
					this.selectCompany(this.results[0])
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		selectCompany(company) {
			this.$emit('selected', company)
			this.$emit('close')
		},

		formatAddress(company) {
			const street = [company.streetname, company.housenumber, company.floor, company.door]
				.filter(Boolean).join(' ')
			const city = [company.zipcode, company.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
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

.add-company__search-type {
	display: flex;
	gap: 20px;
}

.add-company__radio-label {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	font-size: 0.9em;
	font-weight: 600;
}

.add-company__no-results {
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 8px 0 0;
}

.add-company__results {
	margin-top: 8px;
}

.add-company__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.add-company__table th,
.add-company__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.add-company__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
}

.add-company__mono {
	font-family: monospace;
	white-space: nowrap;
}
</style>
