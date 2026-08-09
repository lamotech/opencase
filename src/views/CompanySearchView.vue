<template>
	<div class="opencase-company-search">
		<!-- Search form -->
		<div v-if="!selectedCompany">
			<h2>{{ t('opencase', 'Søg virksomhed') }}</h2>

			<div class="ocs-company__form">
				<div class="ocs-company__search-type">
					<label class="ocs-company__radio-label">
						<input type="radio" v-model="searchMode" value="company" />
						{{ t('opencase', 'Virksomhed') }}
					</label>
					<label class="ocs-company__radio-label">
						<input type="radio" v-model="searchMode" value="pnumber" />
						{{ t('opencase', 'P nummer') }}
					</label>
				</div>
				<div class="ocs-company__form-grid">
					<div class="ocs-company__field">
						<label class="ocs-company__label">{{ t('opencase', 'CVR-nummer') }}</label>
						<NcTextField v-model="form.cvr"
							:placeholder="t('opencase', 'Søg på CVR-nummer')"
							@keyup.enter="doSearch" />
					</div>
					<div class="ocs-company__field">
						<label class="ocs-company__label">{{ t('opencase', 'Navn') }}</label>
						<NcTextField v-model="form.name"
							:placeholder="t('opencase', 'Søg på virksomhedsnavn')"
							:disabled="form.cvr.trim() !== '' || searchMode === 'pnumber'"
							@keyup.enter="doSearch" />
					</div>
				</div>

				<div class="ocs-company__form-actions">
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
			<NcLoadingIcon v-if="searching" :size="44" class="ocs-company__loading" />

			<!-- No results -->
			<NcEmptyContent v-else-if="searched && results.length === 0"
				:title="t('opencase', 'Ingen virksomheder fundet')"
				:description="t('opencase', 'Prøv at ændre søgekriterierne')">
				<template #icon>
					<OfficeBuildingSearchIcon :size="48" />
				</template>
			</NcEmptyContent>

			<!-- Results table -->
			<template v-else-if="results.length > 0">
				<table class="ocs-company__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'CVR') }}</th>
							<th v-if="searchMode === 'pnumber'">{{ t('opencase', 'P-nummer') }}</th>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Adresse') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(company, idx) in results"
							:key="idx"
							class="ocs-company__row"
							@click="selectCompany(company)">
							<td class="ocs-company__mono">{{ company.cpr_cvr }}</td>
							<td v-if="searchMode === 'pnumber'" class="ocs-company__mono">{{ company.pnumber }}</td>
							<td>{{ company.name }}</td>
							<td>{{ formatAddress(company) }}</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>

		<!-- Company detail view -->
		<div v-else>
			<div class="ocs-company__detail-header">
				<NcButton @click="backToSearch">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('opencase', 'Tilbage til søgning') }}
				</NcButton>
			</div>

			<!-- Company info card -->
			<div class="ocs-company__card">
				<div class="ocs-company__card-title">
					<OfficeBuildingIcon :size="24" />
					<h2>{{ selectedCompany.name }}</h2>
				</div>
				<div class="ocs-company__card-fields">
					<div class="ocs-company__card-field">
						<span class="ocs-company__card-label">{{ t('opencase', 'CVR-nummer') }}</span>
						<span class="ocs-company__mono">{{ selectedCompany.cpr_cvr }}</span>
					</div>
					<div v-if="selectedCompany.pnumber" class="ocs-company__card-field">
						<span class="ocs-company__card-label">{{ t('opencase', 'P-nummer') }}</span>
						<span class="ocs-company__mono">{{ selectedCompany.pnumber }}</span>
					</div>
					<div class="ocs-company__card-field">
						<span class="ocs-company__card-label">{{ t('opencase', 'Adresse') }}</span>
						<span>{{ formatAddress(selectedCompany) }}</span>
					</div>
					<div v-if="selectedCompany.phone" class="ocs-company__card-field">
						<span class="ocs-company__card-label">{{ t('opencase', 'Telefon') }}</span>
						<span>{{ selectedCompany.phone }}</span>
					</div>
					<div v-if="selectedCompany.email" class="ocs-company__card-field">
						<span class="ocs-company__card-label">{{ t('opencase', 'E-mail') }}</span>
						<span>{{ selectedCompany.email }}</span>
					</div>
				</div>
			</div>

			<!-- Tabs -->
			<OverflowTabs :tabs="companyTabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Tab: Sager -->
			<div v-if="activeTab === 'cases'" class="ocs-company__tab-panel">
				<div class="ocs-company__tab-actions">
					<NcButton type="primary" @click="showNewCaseDialog = true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('opencase', 'Opret sag') }}
					</NcButton>
				</div>
				<NewCaseDialog v-if="showNewCaseDialog" :company="selectedCompany" :case-type="companyCaseType" @close="showNewCaseDialog = false" />
				<NcLoadingIcon v-if="loadingCases" :size="32" />
				<NcEmptyContent v-else-if="cases.length === 0"
					:title="t('opencase', 'Ingen sager')">
					<template #icon>
						<FolderIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-company__table">
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
							class="ocs-company__row"
							@click="$router.push({ name: 'case-detail', params: { id: c.id } })">
							<td class="ocs-company__number">{{ c.case_number }}</td>
							<td class="ocs-company__title">{{ c.title }}</td>
							<td>{{ c.role_name }}</td>
							<td>{{ c.organisation }}</td>
							<td><CaseStatusBadge :status="c.status_class" :label="c.status_name" /></td>
							<td class="ocs-company__date">{{ formatDate(c.updated_at) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tab: Dokumenter -->
			<div v-else-if="activeTab === 'documents'" class="ocs-company__tab-panel">
				<NcLoadingIcon v-if="loadingDocuments" :size="32" />
				<NcEmptyContent v-else-if="documents.length === 0"
					:title="t('opencase', 'Ingen dokumenter')">
					<template #icon>
						<FileDocumentOutlineIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-company__table">
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
							class="ocs-company__row"
							@click="$router.push({ name: 'document-detail', params: { id: doc.id } })">
							<td class="ocs-company__title">{{ doc.title }}</td>
							<td>{{ doc.document_type }}</td>
							<td>{{ doc.role_name }}</td>
							<td class="ocs-company__number">{{ doc.case_number }}</td>
							<td>{{ doc.organisation }}</td>
							<td class="ocs-company__date">{{ formatDate(doc.document_date) }}</td>
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
import OfficeBuildingSearchIcon from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import { showError } from '@nextcloud/dialogs'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import NewCaseDialog from '../components/NewCaseDialog.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import api from '../services/api.js'

export default {
	name: 'CompanySearchView',

	components: {
		NcButton,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		MagnifyIcon,
		OfficeBuildingSearchIcon,
		OfficeBuildingIcon,
		ArrowLeftIcon,
		PlusIcon,
		FolderIcon,
		NewCaseDialog,
		FileDocumentOutlineIcon,
		CaseStatusBadge,
		OverflowTabs,
	},

	data() {
		return {
			form: {
				cvr: '',
				name: '',
			},
			searchMode: 'company',
			searching: false,
			searched: false,
			results: [],
			selectedCompany: null,
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
			if (this.searchMode === 'pnumber') {
				return this.form.cvr.trim() !== ''
			}
			return this.form.cvr.trim() !== '' || this.form.name.trim() !== ''
		},

		companyTabs() {
			return [
				{ id: 'cases', label: t('opencase', 'Sager'), count: this.cases.length || null, icon: FolderOutlineIcon },
				{ id: 'documents', label: t('opencase', 'Dokumenter'), count: this.documents.length || null, icon: FileDocumentOutlineIcon },
			]
		},

		companyCaseType() {
			return this.$store.state.caseTypes.find(t => t.id === 3) || null
		},
	},

	mounted() {
		this.$store.dispatch('fetchCaseTypes').catch(() => {})
		const cvr = this.$route.query.company
		if (cvr) {
			try {
				const stored = sessionStorage.getItem('oc_selected_company')
				if (stored) {
					const company = JSON.parse(stored)
					if (company.cpr_cvr === cvr) {
						this.selectCompany(company, { updateUrl: false })
						const tab = this.$route.query.tab
						if (tab) this.activeTab = tab
						return
					}
				}
			} catch (e) {}
			this.$router.replace({ query: {} })
			return
		}
		if (this.$route.query.cvr) {
			this.form.cvr = this.$route.query.cvr
			this.doSearch()
		}
	},

	watch: {
		activeTab(val) {
			if (!this.selectedCompany) return
			if (this.$route.query.tab !== val) {
				this.$router.replace({ query: { company: this.selectedCompany.cpr_cvr, tab: val } })
			}
		},
	},

	methods: {
		async doSearch() {
			if (!this.hasSearchCriteria) return

			this.searching = true
			this.searched  = false
			this.results   = []
			try {
				const cvr  = this.form.cvr.trim()
				const name = this.form.name.trim()
				let params
				if (this.searchMode === 'pnumber') {
					params = { cvr, mode: 'pnumber' }
				} else if (cvr !== '') {
					params = { cvr }
				} else {
					params = { name }
				}
				this.results  = await api.searchCompany(params)
				this.searched = true

				if (this.results.length === 1) {
					await this.selectCompany(this.results[0])
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		async selectCompany(company, { updateUrl = true } = {}) {
			this.selectedCompany  = company
			this.cases            = []
			this.documents        = []
			this.activeTab        = 'cases'

			if (updateUrl) {
				sessionStorage.setItem('oc_selected_company', JSON.stringify(company))
				this.$router.replace({ query: { company: company.cpr_cvr, tab: 'cases' } })
			}

			const cvr     = company.cpr_cvr
			const pnumber = company.pnumber || null

			this.loadingCases     = true
			this.loadingDocuments = true

			api.getCompanyCases(cvr, pnumber)
				.then(cases => { this.cases = cases || [] })
				.catch(() => showError(t('opencase', 'Kunne ikke hente sager')))
				.finally(() => { this.loadingCases = false })

			api.getCompanyDocuments(cvr, pnumber)
				.then(docs => { this.documents = docs || [] })
				.catch(() => showError(t('opencase', 'Kunne ikke hente dokumenter')))
				.finally(() => { this.loadingDocuments = false })
		},

		backToSearch() {
			this.selectedCompany = null
			sessionStorage.removeItem('oc_selected_company')
			this.$router.replace({ query: {} })
		},

		resetForm() {
			this.form     = { cvr: '', name: '' }
			this.results  = []
			this.searched = false
		},

		formatAddress(company) {
			const street = [company.streetname, company.housenumber, company.floor, company.door]
				.filter(Boolean).join(' ')
			const city = [company.zipcode, company.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-company-search {
	padding: 20px;
	max-width: 1200px;
}

.opencase-company-search h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
	font-weight: 700;
}

.ocs-company__form {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ocs-company__search-type {
	display: flex;
	gap: 20px;
}

.ocs-company__radio-label {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	font-size: 0.9em;
	font-weight: 600;
}

.ocs-company__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
}

.ocs-company__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocs-company__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-company__form-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ocs-company__loading {
	margin: 60px auto;
}

.ocs-company__table {
	width: 100%;
	border-collapse: collapse;
}

.ocs-company__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ocs-company__row {
	cursor: pointer;
	transition: background 0.15s;
}

.ocs-company__row:hover {
	background: var(--color-background-hover);
}

.ocs-company__row td {
	padding: 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.ocs-company__mono {
	font-family: var(--font-monospace, monospace);
	white-space: nowrap;
}

.ocs-company__number {
	font-family: var(--font-monospace, monospace);
	font-weight: 600;
	white-space: nowrap;
	color: var(--color-primary-element);
}

.ocs-company__title {
	max-width: 320px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ocs-company__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
}

/* Detail view */
.ocs-company__detail-header {
	margin-bottom: 20px;
}

.ocs-company__card {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 28px;
}

.ocs-company__card-title {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 16px;
}

.ocs-company__card-title h2 {
	margin: 0;
	font-size: 1.3em;
	font-weight: 700;
}

.ocs-company__card-fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px 24px;
}

.ocs-company__card-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ocs-company__card-label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-company__tab-panel {
	margin-bottom: 36px;
}

.ocs-company__tab-actions {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}
</style>
