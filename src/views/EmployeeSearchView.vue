<template>
	<div class="opencase-employee-search">
		<!-- ── Search form ── -->
		<div v-if="!selectedEmployee">
			<h2>{{ t('opencase', 'Søg medarbejder') }}</h2>

			<div class="ocs-emp__form">
				<div class="ocs-emp__form-grid">
					<div class="ocs-emp__field">
						<label class="ocs-emp__label">{{ t('opencase', 'Navn / brugernavn / e-mail') }}</label>
						<NcTextField v-model="form.q"
							:placeholder="t('opencase', 'Søg på navn, brugernavn, e-mail...')"
							@keyup.enter="doSearch" />
					</div>
					<div class="ocs-emp__field">
						<label class="ocs-emp__label">{{ t('opencase', 'Organisation') }}</label>
						<NcTextField v-model="form.organisation"
							:placeholder="t('opencase', 'Filtrer på organisation')"
							@keyup.enter="doSearch" />
					</div>
					<div class="ocs-emp__field">
						<label class="ocs-emp__label">{{ t('opencase', 'Rolle') }}</label>
						<NcTextField v-model="form.role"
							:placeholder="t('opencase', 'Filtrer på rolle')"
							@keyup.enter="doSearch" />
					</div>
				</div>

				<div class="ocs-emp__form-actions">
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
			<NcLoadingIcon v-if="searching" :size="44" class="ocs-emp__loading" />

			<!-- No results -->
			<NcEmptyContent v-else-if="searched && results.length === 0"
				:title="t('opencase', 'Ingen medarbejdere fundet')"
				:description="t('opencase', 'Prøv at ændre søgekriterierne')">
				<template #icon>
					<AccountGroupIcon :size="48" />
				</template>
			</NcEmptyContent>

			<!-- Results table -->
			<template v-else-if="results.length > 0">
				<p class="ocs-emp__count">
					{{ n('opencase', '{count} medarbejder fundet', '{count} medarbejdere fundet', results.length, { count: results.length }) }}
				</p>
				<table class="ocs-emp__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Brugernavn') }}</th>
							<th>{{ t('opencase', 'E-mail') }}</th>
							<th>{{ t('opencase', 'Telefon') }}</th>
							<th>{{ t('opencase', 'Sted') }}</th>
							<th>{{ t('opencase', 'Organisationer / Roller') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="emp in results"
							:key="emp.uuid"
							class="ocs-emp__row"
							@click="selectEmployee(emp)">
							<td>{{ emp.personname || '–' }}</td>
							<td class="ocs-emp__mono">{{ emp.username || '–' }}</td>
							<td>{{ emp.email || '–' }}</td>
							<td class="ocs-emp__mono">{{ emp.phone || '–' }}</td>
							<td>{{ emp.location || '–' }}</td>
							<td>
								<span v-if="emp.orgs.length === 0">–</span>
								<ul v-else class="ocs-emp__orgs">
									<li v-for="(o, idx) in emp.orgs" :key="idx">
										<span class="ocs-emp__org-name">{{ o.org_name }}</span>
										<span v-if="o.role" class="ocs-emp__role-badge">{{ o.role }}</span>
									</li>
								</ul>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>

		<!-- ── Employee detail ── -->
		<div v-else>
			<div class="ocs-emp__detail-header">
				<NcButton @click="backToSearch">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('opencase', 'Tilbage til søgning') }}
				</NcButton>
			</div>

			<!-- Info card -->
			<div class="ocs-emp__card">
				<div class="ocs-emp__card-title">
					<AccountIcon :size="24" />
					<h2>{{ selectedEmployee.personname || selectedEmployee.username }}</h2>
				</div>
				<div class="ocs-emp__card-fields">
					<div v-if="selectedEmployee.username" class="ocs-emp__card-field">
						<span class="ocs-emp__card-label">{{ t('opencase', 'Brugernavn') }}</span>
						<span class="ocs-emp__mono">{{ selectedEmployee.username }}</span>
					</div>
					<div v-if="selectedEmployee.email" class="ocs-emp__card-field">
						<span class="ocs-emp__card-label">{{ t('opencase', 'E-mail') }}</span>
						<a :href="'mailto:' + selectedEmployee.email">{{ selectedEmployee.email }}</a>
					</div>
					<div v-if="selectedEmployee.phone" class="ocs-emp__card-field">
						<span class="ocs-emp__card-label">{{ t('opencase', 'Telefon') }}</span>
						<span class="ocs-emp__mono">{{ selectedEmployee.phone }}</span>
					</div>
					<div v-if="selectedEmployee.location" class="ocs-emp__card-field">
						<span class="ocs-emp__card-label">{{ t('opencase', 'Sted') }}</span>
						<span>{{ selectedEmployee.location }}</span>
					</div>
				</div>

				<div v-if="selectedEmployee.orgs.length > 0" class="ocs-emp__card-orgs">
					<span class="ocs-emp__card-label">{{ t('opencase', 'Organisationer') }}</span>
					<ul class="ocs-emp__orgs">
						<li v-for="(o, idx) in selectedEmployee.orgs" :key="idx">
							<span class="ocs-emp__org-name">{{ o.org_name }}</span>
							<span v-if="o.role" class="ocs-emp__role-badge">{{ o.role }}</span>
						</li>
					</ul>
				</div>
			</div>

			<!-- Tabs -->
			<OverflowTabs :tabs="employeeTabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Tab: Sager -->
			<div v-if="activeTab === 'cases'" class="ocs-emp__tab-panel">
				<div class="ocs-emp__tab-actions">
					<NcButton type="primary" @click="showNewCaseDialog = true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('opencase', 'Opret sag') }}
					</NcButton>
				</div>
				<NewCaseDialog v-if="showNewCaseDialog" :employee="selectedEmployee" :case-type="employeeCaseType" @close="showNewCaseDialog = false" />
				<NcLoadingIcon v-if="loadingCases" :size="32" />
				<NcEmptyContent v-else-if="cases.length === 0"
					:title="t('opencase', 'Ingen sager')">
					<template #icon>
						<FolderIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-emp__table">
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
							class="ocs-emp__row"
							@click="openCase(c.id)">
							<td class="ocs-emp__mono">{{ c.case_number }}</td>
							<td>{{ c.title }}</td>
							<td>{{ c.role_name }}</td>
							<td>{{ c.organisation }}</td>
							<td><CaseStatusBadge :status="c.status_class" :label="c.status_name" /></td>
							<td class="ocs-emp__mono">{{ formatDate(c.updated_at) }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tab: Organisationsstruktur -->
			<div v-else-if="activeTab === 'orgtree'" class="ocs-emp__tab-panel">
				<NcLoadingIcon v-if="loadingTree" :size="32" />
				<NcEmptyContent v-else-if="orgRoots.length === 0"
					:title="t('opencase', 'Ingen organisationer')">
					<template #icon>
						<OfficeBuildingIcon :size="32" />
					</template>
				</NcEmptyContent>
				<ul v-else class="org-tree">
					<OrgTreeNode v-for="root in orgRoots"
						:key="root.org_uuid"
						:node="root"
						:children-map="orgChildrenMap"
						:user-org-uuids="userOrgUuidsMap"
						:user-org-roles="userOrgRolesMap"
						:auto-open-uuids="autoOpenUuidsMap" />
				</ul>
			</div>

			<!-- Tab: Rettigheder -->
			<div v-else-if="activeTab === 'access'" class="ocs-emp__tab-panel">
				<NcLoadingIcon v-if="loadingPrivileges" :size="32" />
				<NcEmptyContent v-else-if="userPrivileges.length === 0"
					:title="t('opencase', 'Ingen rettigheder')">
					<template #icon>
						<ShieldKeyOutlineIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-emp__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Rettighedstype') }}</th>
							<th>{{ t('opencase', 'Følsomhed') }}</th>
							<th>{{ t('opencase', 'KLE') }}</th>
							<th>{{ t('opencase', 'Organisationsenhed') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(priv, idx) in userPrivileges" :key="idx" class="ocs-emp__row--static">
							<td>{{ privilegeTypeMap[priv.privilege_type] || priv.privilege_type }}</td>
							<td>
								<span v-if="priv.foelsomhed.length === 0">{{ t('opencase', 'Alle') }}</span>
								<ul v-else class="ocs-emp__value-list">
									<li v-for="(f, fi) in priv.foelsomhed" :key="fi">{{ f }}</li>
								</ul>
							</td>
							<td>
								<span v-if="priv.kle.length === 0">{{ t('opencase', 'Alle') }}</span>
								<div v-else class="ocs-emp__kle-list">
									<span v-for="(k, ki) in priv.kle" :key="ki" class="ocs-emp__kle-item">{{ k }}</span>
								</div>
							</td>
							<td>
								<span v-if="priv.orgenhed.length === 0">{{ t('opencase', 'Alle') }}</span>
								<ul v-else class="ocs-emp__value-list">
									<li v-for="(o, oi) in priv.orgenhed" :key="oi">{{ o }}</li>
								</ul>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Tab: Manuelt tildelte sager -->
			<div v-else-if="activeTab === 'grants'" class="ocs-emp__tab-panel">
				<NcLoadingIcon v-if="loadingGrants" :size="32" />
				<NcEmptyContent v-else-if="manualGrants.length === 0"
					:title="t('opencase', 'Ingen manuelt tildelte sager')">
					<template #icon>
						<LockOutlineIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-emp__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Sagsnummer') }}</th>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Adgang') }}</th>
							<th>{{ t('opencase', 'Tildelt den') }}</th>
							<th>{{ t('opencase', 'Udløber') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="grant in manualGrants"
							:key="grant.case_id"
							class="ocs-emp__row"
							@click="openCase(grant.case_id)">
							<td class="ocs-emp__mono">{{ grant.case_number }}</td>
							<td>{{ grant.title }}</td>
							<td>
								<span :class="grant.can_write ? 'ocs-emp__badge--write' : 'ocs-emp__badge--read'"
									class="ocs-emp__access-badge">
									{{ grant.can_write ? t('opencase', 'Skriv') : t('opencase', 'Læs') }}
								</span>
							</td>
							<td class="ocs-emp__mono">{{ formatDate(grant.granted_at) }}</td>
							<td class="ocs-emp__mono">{{ grant.expires_at ? formatDate(grant.expires_at) : t('opencase', 'Permanent') }}</td>
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
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import SitemapOutlineIcon from 'vue-material-design-icons/SitemapOutline.vue'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'
import ShieldKeyOutlineIcon from 'vue-material-design-icons/ShieldKeyOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import { showError } from '@nextcloud/dialogs'

import OrgTreeNode from '../components/OrgTreeNode.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import NewCaseDialog from '../components/NewCaseDialog.vue'
import api from '../services/api.js'

export default {
	name: 'EmployeeSearchView',

	components: {
		NcButton,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		MagnifyIcon,
		AccountGroupIcon,
		AccountIcon,
		ArrowLeftIcon,
		OfficeBuildingIcon,
		LockOutlineIcon,
		ShieldKeyOutlineIcon,
		PlusIcon,
		FolderIcon,
		CaseStatusBadge,
		NewCaseDialog,
		OrgTreeNode,
		OverflowTabs,
	},

	data() {
		return {
			form: { q: '', organisation: '', role: '' },
			searching: false,
			searched: false,
			results: [],
			selectedEmployee: null,
			allOrgs: [],
			loadingTree: false,
			activeTab: 'orgtree',
			manualGrants: [],
			loadingGrants: false,
			userPrivileges: [],
			loadingPrivileges: false,
			cases: [],
			loadingCases: false,
			showNewCaseDialog: false,
		}
	},

	computed: {
		hasSearchCriteria() {
			return this.form.q.trim() !== ''
				|| this.form.organisation.trim() !== ''
				|| this.form.role.trim() !== ''
		},

		employeeTabs() {
			return [
				{ id: 'cases', label: t('opencase', 'Sager'), count: this.cases.length || null, icon: FolderOutlineIcon },
				{ id: 'orgtree', label: t('opencase', 'Organisationsstruktur'), icon: SitemapOutlineIcon },
				{ id: 'access', label: t('opencase', 'Rettigheder'), count: this.userPrivileges.length || null, icon: ShieldKeyOutlineIcon },
				{ id: 'grants', label: t('opencase', 'Manuelt tildelte sager'), count: this.manualGrants.length || null, icon: LockOutlineIcon },
			]
		},

		employeeCaseType() {
			return this.$store.state.caseTypes.find(t => t.id === 4) || null
		},

		privilegeTypeMap() {
			return {
				systemadministrator: t('opencase', 'Systemadministrator'),
				opencaseadministrator: t('opencase', 'OpenCase administrator'),
				opencasetemplateadministrator: t('opencase', 'Skabelonadministrator'),
				opencaseuser: t('opencase', 'Bruger'),
				opencasereaduser: t('opencase', 'Læsebruger'),
			}
		},

		// Map org_uuid → [children]
		orgChildrenMap() {
			const map = {}
			for (const org of this.allOrgs) {
				if (!map[org.org_uuid]) map[org.org_uuid] = []
				const parentKey = org.org_parent_uuid || ''
				if (!map[parentKey]) map[parentKey] = []
				map[parentKey].push(org)
			}
			return map
		},

		// Root nodes: those with no parent (org_parent_uuid is '' or not found)
		orgRoots() {
			const allUuids = new Set(this.allOrgs.map(o => o.org_uuid))
			return this.allOrgs.filter(o => !o.org_parent_uuid || !allUuids.has(o.org_parent_uuid))
		},

		// { [org_uuid]: true } for the selected employee's orgs
		userOrgUuidsMap() {
			if (!this.selectedEmployee) return {}
			const map = {}
			for (const o of this.selectedEmployee.orgs) {
				const uuid = this.orgUuidByName(o.org_name)
				if (uuid) map[uuid] = true
			}
			return map
		},

		// { [org_uuid]: [roles] } for the selected employee
		userOrgRolesMap() {
			if (!this.selectedEmployee) return {}
			const map = {}
			for (const o of this.selectedEmployee.orgs) {
				const uuid = this.orgUuidByName(o.org_name)
				if (uuid) {
					if (!map[uuid]) map[uuid] = []
					if (o.role) map[uuid].push(o.role)
				}
			}
			return map
		},

		// { [org_uuid]: true } for nodes that should start open
		// (ancestors of user orgs + user orgs themselves)
		autoOpenUuidsMap() {
			const open = { ...this.userOrgUuidsMap }
			const parentOf = {}
			for (const org of this.allOrgs) {
				if (org.org_parent_uuid) parentOf[org.org_uuid] = org.org_parent_uuid
			}
			for (const uuid of Object.keys(this.userOrgUuidsMap)) {
				let cur = parentOf[uuid]
				while (cur) {
					open[cur] = true
					cur = parentOf[cur]
				}
			}
			return open
		},
	},

	mounted() {
		this.$store.dispatch('fetchCaseTypes').catch(() => {})
		const uuid = this.$route.query.emp
		if (uuid) {
			try {
				const stored = sessionStorage.getItem('oc_selected_employee')
				if (stored) {
					const emp = JSON.parse(stored)
					if (emp.uuid === uuid) {
						this.selectEmployee(emp, { updateUrl: false })
						const tab = this.$route.query.tab
						if (tab) this.activeTab = tab
						return
					}
				}
			} catch (e) {}
			this.$router.replace({ query: {} })
			return
		}
		if (this.$route.query.username) {
			this.searchByUsername(this.$route.query.username)
		}
	},

	watch: {
		activeTab(val) {
			if (!this.selectedEmployee) return
			if (this.$route.query.tab !== val) {
				this.$router.replace({ query: { emp: this.selectedEmployee.uuid, tab: val } })
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
				const params = {}
				if (this.form.q.trim())            params.q            = this.form.q.trim()
				if (this.form.organisation.trim()) params.organisation = this.form.organisation.trim()
				if (this.form.role.trim())         params.role         = this.form.role.trim()
				this.results  = await api.searchEmployees(params) || []
				this.searched = true
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		async searchByUsername(username) {
			this.searching = true
			try {
				const results = await api.searchEmployees({ q: username }) || []
				const match = results.find(e => e.username === username) || results[0] || null
				if (match) {
					await this.selectEmployee(match)
				} else {
					this.$router.replace({ query: {} })
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		async selectEmployee(emp, { updateUrl = true } = {}) {
			this.selectedEmployee = emp
			this.activeTab = 'orgtree'
			this.manualGrants = []
			this.userPrivileges = []
			this.cases = []

			if (updateUrl) {
				sessionStorage.setItem('oc_selected_employee', JSON.stringify(emp))
				this.$router.replace({ query: { emp: emp.uuid, tab: 'orgtree' } })
			}

			if (this.allOrgs.length === 0) {
				this.loadingTree = true
				try {
					this.allOrgs = await api.getOrgTree() || []
				} catch (e) {
					showError(t('opencase', 'Kunne ikke hente organisationsstruktur'))
				} finally {
					this.loadingTree = false
				}
			}

			if (emp.uuid) {
				this.loadingGrants = true
				try {
					this.manualGrants = await api.getEmployeeGrants(emp.uuid) || []
				} catch (e) {
					showError(t('opencase', 'Kunne ikke hente manuelt tildelte sager'))
				} finally {
					this.loadingGrants = false
				}
			}

			if (emp.uuid) {
				this.loadingPrivileges = true
				try {
					this.userPrivileges = await api.getEmployeePrivileges(emp.uuid) || []
				} catch (e) {
					showError(t('opencase', 'Kunne ikke hente rettigheder'))
				} finally {
					this.loadingPrivileges = false
				}
			}

			if (emp.username) {
				this.loadingCases = true
				try {
					this.cases = await api.getEmployeeCases(emp.username) || []
				} catch (e) {
					showError(t('opencase', 'Kunne ikke hente sager'))
				} finally {
					this.loadingCases = false
				}
			}
		},

		backToSearch() {
			this.selectedEmployee = null
			sessionStorage.removeItem('oc_selected_employee')
			this.$router.replace({ query: {} })
		},

		resetForm() {
			this.form = { q: '', organisation: '', role: '' }
			this.results  = []
			this.searched = false
		},

		orgUuidByName(name) {
			const found = this.allOrgs.find(o => o.org_name === name)
			return found ? found.org_uuid : null
		},

		openCase(caseId) {
			this.$router.push({ name: 'case-detail', params: { id: caseId } })
		},

		formatDate(dateStr) {
			if (!dateStr) return '–'
			const d = new Date(dateStr)
			if (isNaN(d)) return dateStr
			return d.toLocaleDateString('da-DK')
		},
	},
}
</script>

<style scoped>
.opencase-employee-search {
	padding: 20px;
	max-width: 1200px;
}

.opencase-employee-search h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
	font-weight: 700;
}

.ocs-emp__form {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ocs-emp__form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
}

.ocs-emp__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocs-emp__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-emp__form-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ocs-emp__loading {
	margin: 60px auto;
}

.ocs-emp__count {
	margin: 0 0 10px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ocs-emp__table {
	width: 100%;
	border-collapse: collapse;
}

.ocs-emp__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ocs-emp__row {
	cursor: pointer;
	transition: background 0.15s;
}

.ocs-emp__row:hover {
	background: var(--color-background-hover);
}

.ocs-emp__row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: top;
}

.ocs-emp__mono {
	font-family: var(--font-monospace, monospace);
	white-space: nowrap;
}

.ocs-emp__orgs {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocs-emp__org-name {
	font-weight: 500;
}

.ocs-emp__role-badge {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.78em;
	font-weight: 600;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

/* Detail view */
.ocs-emp__detail-header {
	margin-bottom: 20px;
}

.ocs-emp__card {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 28px;
}

.ocs-emp__card-title {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 16px;
}

.ocs-emp__card-title h2 {
	margin: 0;
	font-size: 1.3em;
	font-weight: 700;
}

.ocs-emp__card-fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px 24px;
	margin-bottom: 16px;
}

.ocs-emp__card-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ocs-emp__card-label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
	display: block;
	margin-bottom: 4px;
}

.ocs-emp__card-orgs {
	margin-top: 4px;
}

.ocs-emp__tab-panel {
	margin-bottom: 36px;
}

.ocs-emp__tab-actions {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}

.ocs-emp__access-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
}

.ocs-emp__badge--write {
	background: #d4edda;
	color: #155724;
}

.ocs-emp__badge--read {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.ocs-emp__row--static td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: top;
}

.ocs-emp__value-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ocs-emp__kle-list {
	display: flex;
	flex-wrap: wrap;
	gap: 2px 6px;
}

.ocs-emp__kle-item {
	font-family: var(--font-monospace, monospace);
	font-size: 0.9em;
	white-space: nowrap;
}

/* Org tree root */
.org-tree {
	list-style: none;
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: 8px;
}
</style>
