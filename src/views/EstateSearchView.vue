<template>
	<div class="opencase-estate-search">
		<!-- Loading a stored estate by id (arrived via a case's Ejendomme tab) -->
		<div v-if="estateId && !estate">
			<NcLoadingIcon v-if="searching" :size="44" class="ocs-estate__loading" />
			<NcEmptyContent v-else
				:title="t('opencase', 'Ejendommen blev ikke fundet')">
				<template #icon>
					<HomeCityIcon :size="48" />
				</template>
			</NcEmptyContent>
		</div>

		<!-- Search form -->
		<div v-else-if="!estate">
			<h2>{{ t('opencase', 'Søg ejendom') }}</h2>

			<div class="ocs-estate__form">
				<div class="ocs-estate__search-type">
					<label class="ocs-estate__radio-label">
						<input type="radio" v-model="searchMode" value="bfe" />
						{{ t('opencase', 'BFE-nummer') }}
					</label>
					<label class="ocs-estate__radio-label">
						<input type="radio" v-model="searchMode" value="address" />
						{{ t('opencase', 'Adresse') }}
					</label>
				</div>

				<div v-if="searchMode === 'bfe'" class="ocs-estate__form-grid">
					<div class="ocs-estate__field">
						<label class="ocs-estate__label">{{ t('opencase', 'BFE Nummer') }}</label>
						<NcTextField v-model="form.bfenumber"
							:placeholder="t('opencase', 'Søg på BFE-nummer')"
							@keyup.enter="doSearch" />
					</div>
				</div>

				<div v-else class="ocs-estate__form-grid">
					<div class="ocs-estate__field">
						<label class="ocs-estate__label">{{ t('opencase', 'Vejnavn') }}</label>
						<NcTextField v-model="form.streetname"
							:placeholder="t('opencase', 'Søg på vejnavn')"
							@keyup.enter="doSearch" />
					</div>
					<div class="ocs-estate__field">
						<label class="ocs-estate__label">{{ t('opencase', 'Husnummer') }}</label>
						<NcTextField v-model="form.housenumber"
							:placeholder="t('opencase', 'Valgfrit')"
							@keyup.enter="doSearch" />
					</div>
				</div>

				<div class="ocs-estate__form-actions">
					<NcButton type="primary"
						:disabled="searching || !hasSearchCriteria"
						@click="doSearch">
						<template #icon>
							<NcLoadingIcon v-if="searching" :size="20" />
							<MagnifyIcon v-else :size="20" />
						</template>
						{{ t('opencase', 'Søg') }}
					</NcButton>
					<NcButton v-if="hasSearchCriteria" @click="resetForm">
						{{ t('opencase', 'Nulstil') }}
					</NcButton>
				</div>
			</div>

			<!-- Loading -->
			<NcLoadingIcon v-if="searching" :size="44" class="ocs-estate__loading" />

			<!-- No results -->
			<NcEmptyContent v-else-if="searched && searchMode === 'bfe' && !estate"
				:title="t('opencase', 'Ingen ejendom fundet')"
				:description="t('opencase', 'Prøv at ændre BFE-nummeret')">
				<template #icon>
					<HomeCityIcon :size="48" />
				</template>
			</NcEmptyContent>

			<!-- Address search: no results -->
			<NcEmptyContent v-else-if="searched && searchMode === 'address' && addressResults.length === 0"
				:title="t('opencase', 'Ingen ejendomme fundet')"
				:description="t('opencase', 'Prøv at ændre søgekriterierne')">
				<template #icon>
					<HomeCityIcon :size="48" />
				</template>
			</NcEmptyContent>

			<!-- Address search: results list -->
			<table v-else-if="searchMode === 'address' && addressResults.length > 0" class="ocs-estate__table">
				<thead>
					<tr>
						<th />
						<th>{{ t('opencase', 'Type') }}</th>
						<th>{{ t('opencase', 'BFE Nummer') }}</th>
						<th>{{ t('opencase', 'Beliggenhedsadresse') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in addressResults"
						:key="row.type + '-' + row.bfenummer"
						class="ocs-estate__row"
						@click="selectAddressResult(row)">
						<td>
							<HomeGroupIcon v-if="row.type === 'Ejerlejlighed'" :size="20" />
							<HomeVariantIcon v-else-if="row.type === 'Bygning på fremmed grund'" :size="20" />
							<HomeCityIcon v-else :size="20" />
						</td>
						<td>{{ row.type }}</td>
						<td class="ocs-estate__mono">{{ row.bfenummer }}</td>
						<td>{{ addressRowLocation(row) }}</td>
					</tr>
				</tbody>
			</table>

			<!-- Address search: pagination -->
			<div v-if="searchMode === 'address' && effectiveTotal > limit" class="ocs-estate__pagination">
				<NcButton :disabled="addressOffset === 0" @click="prevAddressPage">
					{{ t('opencase', 'Forrige') }}
				</NcButton>
				<span class="ocs-estate__page-info">
					{{ t('opencase', 'Side {page}', { page: Math.floor(addressOffset / limit) + 1 }) }}
				</span>
				<NcButton :disabled="addressOffset + limit >= effectiveTotal" @click="nextAddressPage">
					{{ t('opencase', 'Næste') }}
				</NcButton>
			</div>
		</div>

		<!-- Estate detail view -->
		<div v-else>
			<div class="ocs-estate__detail-header">
				<NcButton @click="backToSearch">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ fromCase ? t('opencase', 'Tilbage til sag') : t('opencase', 'Tilbage til søgning') }}
				</NcButton>
			</div>

			<!-- Estate info card -->
			<div class="ocs-estate__card">
				<div class="ocs-estate__card-title">
					<HomeGroupIcon v-if="estate.apartment" :size="24" />
					<HomeVariantIcon v-else-if="estate.building_land_by_other" :size="24" />
					<HomeCityIcon v-else :size="24" />
					<h2>{{ estateLocationAddress || t('opencase', 'Ejendom') }}</h2>
					<NcButton v-if="!enterpriseVersionEnabled" class="ocs-estate__edit-btn" @click="showEditEstateDialog = true">
						<template #icon>
							<PencilIcon :size="18" />
						</template>
						{{ t('opencase', 'Rediger') }}
					</NcButton>
				</div>
				<div class="ocs-estate__card-fields">
					<div class="ocs-estate__card-field">
						<span class="ocs-estate__card-label">{{ t('opencase', 'Type') }}</span>
						<span>{{ estate.type }}</span>
					</div>
					<div class="ocs-estate__card-field">
						<span class="ocs-estate__card-label">{{ t('opencase', 'BFE Nummer') }}</span>
						<span class="ocs-estate__mono">{{ estate.bfenummer }}</span>
					</div>
					<div class="ocs-estate__card-field">
						<span class="ocs-estate__card-label">{{ t('opencase', 'Beliggenhedsadresse') }}</span>
						<span>{{ estateLocationAddress || '–' }}</span>
					</div>
					<template v-if="estate.apartment">
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Ejerlejlighedsnummer') }}</span>
							<span class="ocs-estate__mono">{{ estate.apartment.apartment_number || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Fordelingstal') }}</span>
							<span>{{ formatAllocFactor(estate.apartment) }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Areal') }}</span>
							<span>{{ formatArea(estate.apartment.area) }}</span>
						</div>
					</template>
				</div>
			</div>

			<CreateEstateDialog v-if="showEditEstateDialog"
				mode="edit"
				:estate-id="estate.id"
				:initial-estate="estate"
				@close="showEditEstateDialog = false"
				@updated="reloadEstate" />

			<!-- Tabs -->
			<OverflowTabs :tabs="estateTabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Tab: Sager -->
			<div v-if="activeTab === 'cases'" class="ocs-estate__tab-panel">
				<div class="ocs-estate__tab-actions">
					<NcButton type="primary" @click="showNewCaseDialog = true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('opencase', 'Opret sag') }}
					</NcButton>
				</div>
				<NewCaseDialog v-if="showNewCaseDialog" :estate="estate" :case-type="estateCaseType" @close="showNewCaseDialog = false" />
				<NcLoadingIcon v-if="loadingCases" :size="32" />
				<NcEmptyContent v-else-if="cases.length === 0"
					:title="t('opencase', 'Ingen sager')">
					<template #icon>
						<FolderIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-estate__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Sagsnummer') }}</th>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Organisation') }}</th>
							<th>{{ t('opencase', 'Status') }}</th>
							<th>{{ t('opencase', 'Opdateret') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="c in cases"
							:key="c.id"
							class="ocs-estate__row"
							@click="$router.push({ name: 'case-detail', params: { id: c.id } })">
							<td class="ocs-estate__mono">{{ c.case_number }}</td>
							<td>{{ c.title }}</td>
							<td>{{ c.organisation }}</td>
							<td><CaseStatusBadge :status="c.status_class" :label="c.status_name" /></td>
							<td>{{ formatDate(c.updated_at) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tab: Samlet fast ejendom -->
			<div v-else-if="activeTab === 'aggregated'" class="ocs-estate__tab-panel">
				<template v-if="estate.aggregated_estate">
					<div class="ocs-estate__card-fields">
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'BFE Nummer') }}</span>
							<span class="ocs-estate__mono">{{ estate.aggregated_estate.bfenumber }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Beliggenhedsadresse') }}</span>
							<span>{{ estate.aggregated_estate.location_address || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Kommunekode') }}</span>
							<span>{{ estate.aggregated_estate.municipality_code || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Vejnavn') }}</span>
							<span>{{ estate.aggregated_estate.streetname || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Vejkode') }}</span>
							<span>{{ estate.aggregated_estate.road_code || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Husnummer') }}</span>
							<span>{{ estate.aggregated_estate.housenumber || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Postnummer') }}</span>
							<span>{{ estate.aggregated_estate.zipcode || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Postdistrikt') }}</span>
							<span>{{ estate.aggregated_estate.zipdistrict || '–' }}</span>
						</div>
						<div class="ocs-estate__card-field">
							<span class="ocs-estate__card-label">{{ t('opencase', 'Supplerende bynavn') }}</span>
							<span>{{ estate.aggregated_estate.additional_city_name || '–' }}</span>
						</div>
					</div>

					<template v-if="estate.aggregated_estate.addresses && estate.aggregated_estate.addresses.length">
						<h3 class="ocs-estate__subheading">{{ t('opencase', 'Adresser') }}</h3>
						<table class="ocs-estate__table">
							<thead>
								<tr>
									<th>{{ t('opencase', 'Adresse') }}</th>
									<th>{{ t('opencase', 'Etage') }}</th>
									<th>{{ t('opencase', 'Dør') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(a, idx) in estate.aggregated_estate.addresses" :key="idx">
									<td>{{ a.location_address || '–' }}</td>
									<td>{{ a.floor || '–' }}</td>
									<td>{{ a.door || '–' }}</td>
								</tr>
							</tbody>
						</table>
					</template>
				</template>
				<NcEmptyContent v-else :title="t('opencase', 'Ingen data')">
					<template #icon>
						<HomeCityIcon :size="32" />
					</template>
				</NcEmptyContent>
			</div>

			<!-- Tab: Jordstykke -->
			<div v-else-if="activeTab === 'landplots'" class="ocs-estate__tab-panel">
				<NcEmptyContent v-if="landPlots.length === 0" :title="t('opencase', 'Ingen jordstykker')">
					<template #icon>
						<TerrainIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-estate__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Matrikelnummer') }}</th>
							<th>{{ t('opencase', 'Ejerlavskode') }}</th>
							<th>{{ t('opencase', 'Ejerlavsnavn') }}</th>
							<th>{{ t('opencase', 'Registreret areal') }}</th>
							<th>{{ t('opencase', 'Vejareal') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(l, idx) in landPlots" :key="idx">
							<td class="ocs-estate__mono">{{ l.cadastral_number || '–' }}</td>
							<td>{{ l.cadastral_code || '–' }}</td>
							<td>{{ l.cadastral_name || '–' }}</td>
							<td>{{ formatArea(l.registred_area) }}</td>
							<td>{{ formatArea(l.road_area) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tab: Ejerlejligheder -->
			<div v-else-if="activeTab === 'apartments'" class="ocs-estate__tab-panel">
				<NcEmptyContent v-if="apartments.length === 0" :title="t('opencase', 'Ingen ejerlejligheder')">
					<template #icon>
						<HomeGroupIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-estate__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'BFE Nummer') }}</th>
							<th>{{ t('opencase', 'Ejerlejlighedsnummer') }}</th>
							<th>{{ t('opencase', 'Adresse') }}</th>
							<th>{{ t('opencase', 'Etage') }}</th>
							<th>{{ t('opencase', 'Dør') }}</th>
							<th>{{ t('opencase', 'Fordelingstal') }}</th>
							<th>{{ t('opencase', 'Areal') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(a, idx) in apartments" :key="idx">
							<td class="ocs-estate__mono">{{ a.bfenumber || '–' }}</td>
							<td class="ocs-estate__mono">{{ a.apartment_number || '–' }}</td>
							<td>{{ a.location_address || '–' }}</td>
							<td>{{ a.floor || '–' }}</td>
							<td>{{ a.door || '–' }}</td>
							<td>{{ formatAllocFactor(a) }}</td>
							<td>{{ formatArea(a.area) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tab: Bygning på fremmed grund -->
			<div v-else-if="activeTab === 'buildings'" class="ocs-estate__tab-panel">
				<NcEmptyContent v-if="buildings.length === 0" :title="t('opencase', 'Ingen bygninger på fremmed grund')">
					<template #icon>
						<HomeVariantIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-estate__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'BFE Nummer') }}</th>
							<th>{{ t('opencase', 'Adresse') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(b, idx) in buildings" :key="idx">
							<td class="ocs-estate__mono">{{ b.bfenumber || '–' }}</td>
							<td>{{ b.location_address || '–' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import HomeCityIcon from 'vue-material-design-icons/HomeCity.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import TerrainIcon from 'vue-material-design-icons/Terrain.vue'
import HomeGroupIcon from 'vue-material-design-icons/HomeGroup.vue'
import HomeVariantIcon from 'vue-material-design-icons/HomeVariant.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { showError } from '@nextcloud/dialogs'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import NewCaseDialog from '../components/NewCaseDialog.vue'
import CreateEstateDialog from '../components/CreateEstateDialog.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import api from '../services/api.js'

export default {
	name: 'EstateSearchView',

	components: {
		NcButton,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		MagnifyIcon,
		HomeCityIcon,
		ArrowLeftIcon,
		TerrainIcon,
		HomeGroupIcon,
		HomeVariantIcon,
		FolderIcon,
		PlusIcon,
		PencilIcon,
		CaseStatusBadge,
		NewCaseDialog,
		CreateEstateDialog,
		OverflowTabs,
	},

	props: {
		estateId: { type: [String, Number], default: null },
	},

	data() {
		return {
			searchMode: 'bfe',
			form: {
				bfenumber: '',
				streetname: '',
				housenumber: '',
			},
			searching: false,
			searched: false,
			estate: null,
			activeTab: 'aggregated',
			cases: [],
			loadingCases: false,
			showNewCaseDialog: false,
			showEditEstateDialog: false,
			addressResults: [],
			addressOffset: 0,
			addressTotal: 0,
		}
	},

	mounted() {
		this.$store.dispatch('fetchCaseTypes').catch(() => {})
		if (this.estateId) {
			this.loadEstateById(this.estateId)
		}
	},

	watch: {
		// The 'estate-detail' and 'search-estate' routes both render this same
		// component, so Vue Router reuses the instance instead of remounting it
		// — mounted() only runs once. Without this watcher, navigating away from
		// an estate-detail page (e.g. via the nav bar's "Ejendom" search link)
		// would leave the previously loaded estate on screen instead of showing
		// the search form.
		estateId(newVal) {
			if (newVal) {
				this.loadEstateById(newVal)
			} else {
				this.estate         = null
				this.searched       = false
				this.cases          = []
				this.addressResults = []
				this.addressOffset  = 0
				this.addressTotal   = 0
			}
		},
	},

	computed: {
		hasSearchCriteria() {
			if (this.searchMode === 'address') {
				return this.form.streetname.trim() !== ''
			}
			return this.form.bfenumber.trim() !== ''
		},

		fromCase() {
			return this.$route.query.caseId || null
		},

		enterpriseVersionEnabled() {
			return this.$store.state.enterpriseVersionEnabled
		},

		limit() {
			return this.$store.state.searchPageSize
		},

		effectiveTotal() {
			return Math.min(this.addressTotal, this.$store.state.searchMaxResultCount)
		},

		landPlots() {
			return this.estate?.aggregated_estate?.land_plots || []
		},

		apartments() {
			return this.estate?.aggregated_estate?.apartments || []
		},

		buildings() {
			return this.estate?.aggregated_estate?.buildings_land_by_other || []
		},

		estateLocationAddress() {
			return this.estate?.apartment?.location_address
				|| this.estate?.building_land_by_other?.location_address
				|| this.estate?.aggregated_estate?.location_address
				|| null
		},

		estateTabs() {
			return [
				{ id: 'cases', label: t('opencase', 'Sager'), count: this.cases.length || null, icon: FolderOutlineIcon },
				{ id: 'aggregated', label: t('opencase', 'Samlet fast ejendom'), icon: HomeCityIcon },
				{ id: 'landplots', label: t('opencase', 'Jordstykke'), count: this.landPlots.length || null, icon: TerrainIcon },
				{ id: 'apartments', label: t('opencase', 'Ejerlejligheder'), count: this.apartments.length || null, icon: HomeGroupIcon },
				{ id: 'buildings', label: t('opencase', 'Bygning på fremmed grund'), count: this.buildings.length || null, icon: HomeVariantIcon },
			]
		},

		estateCaseType() {
			return this.$store.state.caseTypes.find(t => t.id === 5) || null
		},
	},

	methods: {
		defaultTabForType(type) {
			if (type === 'Ejerlejlighed') return 'apartments'
			if (type === 'Bygning på fremmed grund') return 'buildings'
			return 'aggregated'
		},

		loadCasesFor(bfenummer) {
			if (!bfenummer) return
			this.loadingCases = true
			api.getEstateCases(bfenummer)
				.then(cases => { this.cases = cases || [] })
				.catch(() => showError(t('opencase', 'Kunne ikke hente sager')))
				.finally(() => { this.loadingCases = false })
		},

		async doSearch() {
			if (!this.hasSearchCriteria) return

			if (this.searchMode === 'address') {
				await this.searchByAddress()
			} else {
				await this.searchByBfeNumber(this.form.bfenumber.trim())
			}
		},

		async searchByBfeNumber(bfenumber) {
			this.searching      = true
			this.searched       = false
			this.estate         = null
			this.cases          = []
			this.addressResults = []
			try {
				const result = await api.searchEstate(bfenumber)
				this.searched = true
				if (result) {
					this.estate    = result
					this.activeTab = this.defaultTabForType(result.type)
					this.loadCasesFor(result.bfenummer)
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		async searchByAddress() {
			this.addressOffset = 0
			await this.fetchAddressResults()
		},

		async fetchAddressResults() {
			this.searching      = true
			this.searched       = false
			this.estate         = null
			try {
				const result = await api.searchEstateByAddress(
					this.form.streetname.trim(),
					this.form.housenumber.trim(),
					this.limit,
					this.addressOffset,
				)
				this.addressResults = result.estates || []
				this.addressTotal   = result.total || 0
				this.searched       = true
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		prevAddressPage() {
			this.addressOffset = Math.max(0, this.addressOffset - this.limit)
			this.fetchAddressResults()
		},

		nextAddressPage() {
			this.addressOffset += this.limit
			this.fetchAddressResults()
		},

		selectAddressResult(row) {
			this.form.bfenumber = String(row.bfenummer)
			this.searchByBfeNumber(String(row.bfenummer))
		},

		addressRowLocation(row) {
			return row.apartment?.location_address
				|| row.building_land_by_other?.location_address
				|| row.aggregated_estate?.location_address
				|| null
		},

		async loadEstateById(estateId) {
			this.searching = true
			this.cases     = []
			try {
				const result = await api.getEstateById(estateId)
				if (result) {
					this.estate    = result
					this.activeTab = this.defaultTabForType(result.type)
					this.loadCasesFor(result.bfenummer)
				}
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente ejendom'))
			} finally {
				this.searching = false
			}
		},

		reloadEstate() {
			if (this.estate?.id) {
				this.loadEstateById(this.estate.id)
			}
		},

		backToSearch() {
			if (this.fromCase) {
				this.$router.push({ name: 'case-detail', params: { id: this.fromCase } })
				return
			}
			this.estate = null
		},

		formatArea(value) {
			return value != null ? `${value} m2` : '–'
		},

		formatAllocFactor(apartment) {
			const { alloc_factor_nom: nom, alloc_factor_denom: denom } = apartment
			return nom != null && denom != null ? `${nom}/${denom}` : '–'
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},

		resetForm() {
			this.form           = { bfenumber: '', streetname: '', housenumber: '' }
			this.searched       = false
			this.addressResults = []
			this.addressOffset  = 0
			this.addressTotal   = 0
		},
	},
}
</script>

<style scoped>
.opencase-estate-search {
	padding: 20px;
	max-width: 1200px;
}

.opencase-estate-search h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
	font-weight: 700;
}

.ocs-estate__form {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ocs-estate__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
}

.ocs-estate__search-type {
	display: flex;
	gap: 20px;
}

.ocs-estate__radio-label {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	font-size: 0.9em;
	font-weight: 600;
}

.ocs-estate__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocs-estate__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-estate__form-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ocs-estate__loading {
	margin: 60px auto;
}

.ocs-estate__table {
	width: 100%;
	border-collapse: collapse;
}

.ocs-estate__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ocs-estate__table td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.ocs-estate__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 20px;
}

.ocs-estate__page-info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.ocs-estate__row {
	cursor: pointer;
	transition: background 0.15s;
}

.ocs-estate__row:hover {
	background: var(--color-background-hover);
}

.ocs-estate__mono {
	font-family: var(--font-monospace, monospace);
	white-space: nowrap;
}

.ocs-estate__subheading {
	margin: 24px 0 12px;
	font-size: 1em;
	font-weight: 700;
}

/* Detail view */
.ocs-estate__detail-header {
	margin-bottom: 20px;
}

.ocs-estate__card {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 28px;
}

.ocs-estate__card-title {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 16px;
}

.ocs-estate__card-title h2 {
	margin: 0;
	font-size: 1.3em;
	font-weight: 700;
}

.ocs-estate__edit-btn {
	margin-left: auto;
}

.ocs-estate__card-fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px 24px;
}

.ocs-estate__card-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ocs-estate__card-label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-estate__tab-panel {
	margin-bottom: 36px;
}

.ocs-estate__tab-actions {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}
</style>
