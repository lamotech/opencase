<template>
	<div class="opencase-citizen-search">
		<!-- Search form -->
		<div v-if="!selectedCitizen">
			<h2>{{ t('opencase', 'Søg borger') }}</h2>

			<div class="ocs-citizen__form">
				<div class="ocs-citizen__form-grid">
					<div class="ocs-citizen__field">
						<label class="ocs-citizen__label">{{ t('opencase', 'CPR-nummer') }}</label>
						<NcTextField v-model="form.cpr"
							:placeholder="t('opencase', 'Søg på CPR-nummer')"
							@keyup.enter="doSearch" />
					</div>
					<div class="ocs-citizen__field">
						<label class="ocs-citizen__label">{{ t('opencase', 'Fornavn') }}</label>
						<NcTextField v-model="form.firstname"
							:placeholder="t('opencase', 'Søg på fornavn')"
							:disabled="form.cpr.trim() !== ''"
							@keyup.enter="doSearch" />
					</div>
					<div class="ocs-citizen__field">
						<label class="ocs-citizen__label">{{ t('opencase', 'Efternavn') }}</label>
						<NcTextField v-model="form.lastname"
							:placeholder="t('opencase', 'Søg på efternavn')"
							:disabled="form.cpr.trim() !== ''"
							@keyup.enter="doSearch" />
					</div>
				</div>

				<template v-if="form.cpr.trim() === ''">
					<div class="ocs-citizen__form-grid">
						<div class="ocs-citizen__field">
							<label class="ocs-citizen__label">{{ t('opencase', 'Vejnavn') }}</label>
							<NcTextField v-model="form.streetname"
								:placeholder="t('opencase', 'Vejnavn')"
								@keyup.enter="doSearch" />
						</div>
						<div class="ocs-citizen__field">
							<label class="ocs-citizen__label">{{ t('opencase', 'Husnummer') }}</label>
							<NcTextField v-model="form.housenumber"
								:placeholder="t('opencase', 'Husnr.')"
								@keyup.enter="doSearch" />
						</div>
						<div class="ocs-citizen__field">
							<label class="ocs-citizen__label">{{ t('opencase', 'Postnummer') }}</label>
							<NcTextField v-model="form.zipcode"
								:placeholder="t('opencase', 'Postnr.')"
								@keyup.enter="doSearch" />
						</div>
						<div class="ocs-citizen__field">
							<label class="ocs-citizen__label">{{ t('opencase', 'By') }}</label>
							<NcTextField v-model="form.zipdistrict"
								:placeholder="t('opencase', 'By')"
								@keyup.enter="doSearch" />
						</div>
					</div>
				</template>

				<div class="ocs-citizen__form-actions">
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
			<NcLoadingIcon v-if="searching" :size="44" class="ocs-citizen__loading" />

			<!-- No results -->
			<NcEmptyContent v-else-if="searched && results.length === 0"
				:title="t('opencase', 'Ingen borgere fundet')"
				:description="t('opencase', 'Prøv at ændre søgekriterierne')">
				<template #icon>
					<AccountSearchIcon :size="48" />
				</template>
			</NcEmptyContent>

			<!-- Results table -->
			<template v-else-if="results.length > 0">
				<table class="ocs-citizen__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'CPR') }}</th>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Adresse') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(citizen, idx) in results"
							:key="idx"
							class="ocs-citizen__row"
							@click="selectCitizen(citizen)">
							<td class="ocs-citizen__mono"><CprDisplay :value="citizen.cpr_cvr" /></td>
							<td>{{ citizen.name }}</td>
							<td>
								<template v-if="citizen.has_address_protection">
									<div v-if="!revealedSearchAddresses[idx]"
										class="ocs-citizen__protected-badge"
										@click.stop="revealSearchAddress(citizen, idx)">
										{{ t('opencase', 'Beskyttet adresse') }}
									</div>
									<div v-else
										class="ocs-citizen__protected-badge"
										@click.stop="revealedSearchAddresses[idx] = false">
										{{ formatAddress(citizen) }}
									</div>
								</template>
								<template v-else>
									{{ formatAddress(citizen) }}
								</template>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>

		<!-- Citizen detail view -->
		<div v-else>
			<div class="ocs-citizen__detail-header">
				<NcButton @click="backToSearch">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('opencase', 'Tilbage til søgning') }}
				</NcButton>
			</div>

			<!-- Citizen info card -->
			<div class="ocs-citizen__card">
				<div class="ocs-citizen__card-title">
					<AccountIcon :size="24" />
					<h2>{{ selectedCitizen.name }}</h2>
				</div>
				<div class="ocs-citizen__card-fields">
					<div class="ocs-citizen__card-field">
						<span class="ocs-citizen__card-label">{{ t('opencase', 'CPR-nummer') }}</span>
						<CprDisplay :value="selectedCitizen.cpr_cvr" />
					</div>
					<div class="ocs-citizen__card-field">
						<span class="ocs-citizen__card-label">{{ t('opencase', 'Adresse') }}</span>
						<template v-if="selectedCitizen.has_address_protection">
							<div v-if="!revealDetailAddress"
								class="ocs-citizen__protected-badge"
								@click="revealDetailCitizenAddress()">
								{{ t('opencase', 'Beskyttet adresse') }}
							</div>
							<div v-else
								class="ocs-citizen__protected-badge"
								@click="revealDetailAddress = false">
								{{ formatAddress(selectedCitizen) }}
							</div>
						</template>
						<span v-else>{{ formatAddress(selectedCitizen) }}</span>
					</div>
					<div v-if="selectedCitizen.phone" class="ocs-citizen__card-field">
						<span class="ocs-citizen__card-label">{{ t('opencase', 'Telefon') }}</span>
						<span>{{ selectedCitizen.phone }}</span>
					</div>
					<div v-if="selectedCitizen.email" class="ocs-citizen__card-field">
						<span class="ocs-citizen__card-label">{{ t('opencase', 'E-mail') }}</span>
						<span>{{ selectedCitizen.email }}</span>
					</div>
				</div>
			</div>

			<!-- Tabs -->
			<OverflowTabs :tabs="citizenTabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Tab: Sager -->
			<div v-if="activeTab === 'cases'" class="ocs-citizen__tab-panel">
				<div class="ocs-citizen__tab-actions">
					<NcButton type="primary" @click="showNewCaseDialog = true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('opencase', 'Opret sag') }}
					</NcButton>
				</div>
				<NewCaseDialog v-if="showNewCaseDialog" :citizen="selectedCitizen" :case-type="citizenCaseType" @close="showNewCaseDialog = false" />
				<NcLoadingIcon v-if="loadingCases" :size="32" />
				<NcEmptyContent v-else-if="cases.length === 0"
					:title="t('opencase', 'Ingen sager')">
					<template #icon>
						<FolderIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-citizen__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Sagsnummer') }}</th>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Rolle') }}</th>
							<th>{{ t('opencase', 'Organisation') }}</th>
							<th>{{ t('opencase', 'Status') }}</th>
							<th>{{ t('opencase', 'Opdateret') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="c in cases"
							:key="c.id + '-' + c.role_name"
							class="ocs-citizen__row"
							@click="$router.push({ name: 'case-detail', params: { id: c.id } })">
							<td class="ocs-citizen__number">{{ c.case_number }}</td>
							<td class="ocs-citizen__title">{{ c.title }}</td>
							<td>{{ c.role_name }}</td>
							<td>{{ c.organisation }}</td>
							<td><CaseStatusBadge :status="c.status_class" :label="c.status_name" /></td>
							<td class="ocs-citizen__date">{{ formatDate(c.updated_at) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tab: Dokumenter -->
			<div v-else-if="activeTab === 'documents'" class="ocs-citizen__tab-panel">
				<NcLoadingIcon v-if="loadingDocuments" :size="32" />
				<NcEmptyContent v-else-if="documents.length === 0"
					:title="t('opencase', 'Ingen dokumenter')">
					<template #icon>
						<FileDocumentOutlineIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-citizen__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Dokumenttype') }}</th>
							<th>{{ t('opencase', 'Rolle') }}</th>
							<th>{{ t('opencase', 'Sag') }}</th>
							<th>{{ t('opencase', 'Organisation') }}</th>
							<th>{{ t('opencase', 'Dokumentdato') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="doc in documents"
							:key="doc.id + '-' + doc.role_name"
							class="ocs-citizen__row"
							@click="$router.push({ name: 'document-detail', params: { id: doc.id } })">
							<td class="ocs-citizen__title">{{ doc.title }}</td>
							<td>{{ doc.document_type }}</td>
							<td>{{ doc.role_name }}</td>
							<td class="ocs-citizen__number">{{ doc.case_number }}</td>
							<td>{{ doc.organisation }}</td>
							<td class="ocs-citizen__date">{{ formatDate(doc.document_date) }}</td>
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
import AccountSearchIcon from 'vue-material-design-icons/AccountSearch.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import { showError } from '@nextcloud/dialogs'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import NewCaseDialog from '../components/NewCaseDialog.vue'
import CprDisplay from '../components/CprDisplay.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import api from '../services/api.js'

export default {
	name: 'CitizenSearchView',

	components: {
		NcButton,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		MagnifyIcon,
		AccountSearchIcon,
		AccountIcon,
		ArrowLeftIcon,
		PlusIcon,
		FolderIcon,
		NewCaseDialog,
		FileDocumentOutlineIcon,
		CaseStatusBadge,
		CprDisplay,
		OverflowTabs,
	},

	data() {
		return {
			form: {
				cpr: '',
				firstname: '',
				lastname: '',
				streetname: '',
				housenumber: '',
				zipcode: '',
				zipdistrict: '',
			},
			searching: false,
			searched: false,
			results: [],
			revealedSearchAddresses: {},
			selectedCitizen: null,
			revealDetailAddress: false,
			showNewCaseDialog: false,
			cases: [],
			documents: [],
			loadingCases: false,
			loadingDocuments: false,
			activeTab: 'cases',
		}
	},

	computed: {
		hasSearchCriteria() {
			return this.form.cpr.trim() !== ''
				|| this.form.firstname.trim() !== ''
				|| this.form.lastname.trim() !== ''
				|| this.form.streetname.trim() !== ''
				|| this.form.housenumber.trim() !== ''
				|| this.form.zipcode.trim() !== ''
				|| this.form.zipdistrict.trim() !== ''
		},

		citizenTabs() {
			return [
				{ id: 'cases', label: t('opencase', 'Sager'), count: this.cases.length || null, icon: FolderOutlineIcon },
				{ id: 'documents', label: t('opencase', 'Dokumenter'), count: this.documents.length || null, icon: FileDocumentOutlineIcon },
			]
		},

		citizenCaseType() {
			return this.$store.state.caseTypes.find(t => t.id === 2) || null
		},
	},

	mounted() {
		this.$store.dispatch('fetchCaseTypes').catch(() => {})
		const encoded = this.$route.query.citizen
		if (encoded) {
			try {
				const stored = sessionStorage.getItem('oc_selected_citizen')
				if (stored) {
					const citizen = JSON.parse(stored)
					if (citizen.cpr_cvr === atob(encoded)) {
						this.selectCitizen(citizen, { updateUrl: false })
						const tab = this.$route.query.tab
						if (tab) this.activeTab = tab
						return
					}
				}
			} catch (e) {}
			this.$router.replace({ query: {} })
			return
		}
		if (this.$route.query.cpr) {
			this.form.cpr = this.$route.query.cpr
			this.doSearch()
		}
	},

	watch: {
		activeTab(val) {
			if (!this.selectedCitizen) return
			if (this.$route.query.tab !== val) {
				this.$router.replace({ query: { citizen: btoa(this.selectedCitizen.cpr_cvr), tab: val } })
			}
		},
	},

	methods: {
		async doSearch() {
			if (!this.hasSearchCriteria) return

			this.searching = true
			this.searched  = false
			this.results   = []
			this.revealedSearchAddresses = {}
			try {
				const params = {}
				if (this.form.cpr.trim() !== '') {
					params.cpr = this.form.cpr.trim()
				} else {
					if (this.form.firstname.trim())   params.firstname   = this.form.firstname.trim()
					if (this.form.lastname.trim())    params.lastname    = this.form.lastname.trim()
					if (this.form.streetname.trim())  params.streetname  = this.form.streetname.trim()
					if (this.form.housenumber.trim()) params.housenumber = this.form.housenumber.trim()
					if (this.form.zipcode.trim())     params.zipcode     = this.form.zipcode.trim()
					if (this.form.zipdistrict.trim()) params.zipdistrict = this.form.zipdistrict.trim()
				}
				this.results  = await api.searchCitizen(params)
				this.searched = true

				if (this.results.length === 1) {
					await this.selectCitizen(this.results[0])
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		async selectCitizen(citizen, { updateUrl = true } = {}) {
			this.selectedCitizen = citizen
			this.cases           = []
			this.documents       = []
			this.activeTab       = 'cases'

			if (updateUrl) {
				sessionStorage.setItem('oc_selected_citizen', JSON.stringify(citizen))
				this.$router.replace({ query: { citizen: btoa(citizen.cpr_cvr), tab: 'cases' } })
			}

			const cpr = citizen.cpr_cvr

			this.loadingCases     = true
			this.loadingDocuments = true

			api.getCitizenCases(cpr)
				.then(cases => { this.cases = cases || [] })
				.catch(() => showError(t('opencase', 'Kunne ikke hente sager')))
				.finally(() => { this.loadingCases = false })

			api.getCitizenDocuments(cpr)
				.then(docs => { this.documents = docs || [] })
				.catch(() => showError(t('opencase', 'Kunne ikke hente dokumenter')))
				.finally(() => { this.loadingDocuments = false })
		},

		backToSearch() {
			this.selectedCitizen  = null
			this.revealDetailAddress = false
			sessionStorage.removeItem('oc_selected_citizen')
			this.$router.replace({ query: {} })
		},

		resetForm() {
			this.form = {
				cpr: '',
				firstname: '',
				lastname: '',
				streetname: '',
				housenumber: '',
				zipcode: '',
				zipdistrict: '',
			}
			this.results  = []
			this.searched = false
		},

		formatAddress(citizen) {
			const street = [citizen.streetname, citizen.housenumber, citizen.floor, citizen.door]
				.filter(Boolean).join(' ')
			const city = [citizen.zipcode, citizen.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},

		revealSearchAddress(citizen, idx) {
			this.revealedSearchAddresses[idx] = true
			api.logAddressProtection(citizen.cpr_cvr, this.formatAddress(citizen)).catch(() => {})
		},

		revealDetailCitizenAddress() {
			this.revealDetailAddress = true
			api.logAddressProtection(this.selectedCitizen.cpr_cvr, this.formatAddress(this.selectedCitizen)).catch(() => {})
		},
	},
}
</script>

<style scoped>
.opencase-citizen-search {
	padding: 20px;
	max-width: 1200px;
}

.opencase-citizen-search h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
	font-weight: 700;
}

.ocs-citizen__form {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ocs-citizen__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
}

.ocs-citizen__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocs-citizen__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-citizen__form-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ocs-citizen__loading {
	margin: 60px auto;
}

.ocs-citizen__table {
	width: 100%;
	border-collapse: collapse;
}

.ocs-citizen__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ocs-citizen__row {
	cursor: pointer;
	transition: background 0.15s;
}

.ocs-citizen__row:hover {
	background: var(--color-background-hover);
}

.ocs-citizen__row td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.ocs-citizen__mono {
	font-family: var(--font-monospace, monospace);
	white-space: nowrap;
}

.ocs-citizen__number {
	font-family: var(--font-monospace, monospace);
	font-weight: 600;
	white-space: nowrap;
	color: var(--color-primary-element);
}

.ocs-citizen__title {
	max-width: 320px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ocs-citizen__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
}

/* Detail view */
.ocs-citizen__detail-header {
	margin-bottom: 20px;
}

.ocs-citizen__card {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 28px;
}

.ocs-citizen__card-title {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 16px;
}

.ocs-citizen__card-title h2 {
	margin: 0;
	font-size: 1.3em;
	font-weight: 700;
}

.ocs-citizen__card-fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px 24px;
}

.ocs-citizen__card-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ocs-citizen__card-label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-citizen__tab-panel {
	margin-bottom: 36px;
}

.ocs-citizen__tab-actions {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}

.ocs-citizen__protected-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	background: #fce4ec;
	color: #c62828;
	border: none;
	cursor: pointer;
}

.ocs-citizen__protected-badge:hover {
	opacity: 0.85;
}
</style>
