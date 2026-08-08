<template>
	<div class="opencase-org-search">
		<!-- ── Search form ── -->
		<div v-if="!selectedOrg">
			<h2>{{ t('opencase', 'Søg organisation') }}</h2>

			<div class="ocs-org__form">
				<div class="ocs-org__field">
					<label class="ocs-org__label">{{ t('opencase', 'Organisationsnavn') }}</label>
					<NcTextField v-model="query"
						:placeholder="t('opencase', 'Søg på navn...')"
						@keyup.enter="doSearch" />
				</div>
				<div class="ocs-org__form-actions">
					<NcButton type="primary"
						:disabled="searching || query.trim() === ''"
						@click="doSearch">
						<template #icon>
							<NcLoadingIcon v-if="searching" :size="20" />
							<MagnifyIcon v-else :size="20" />
						</template>
						{{ t('opencase', 'Søg') }}
					</NcButton>
					<NcButton v-if="query.trim() !== ''" @click="resetForm">
						{{ t('opencase', 'Nulstil') }}
					</NcButton>
				</div>
			</div>

			<!-- Loading -->
			<NcLoadingIcon v-if="searching" :size="44" class="ocs-org__loading" />

			<!-- No results -->
			<NcEmptyContent v-else-if="searched && results.length === 0"
				:title="t('opencase', 'Ingen organisationer fundet')"
				:description="t('opencase', 'Prøv at ændre søgekriterierne')">
				<template #icon>
					<SitemapOutlineIcon :size="48" />
				</template>
			</NcEmptyContent>

			<!-- Results table -->
			<template v-else-if="results.length > 0">
				<p class="ocs-org__count">
					{{ n('opencase', '{count} organisation fundet', '{count} organisationer fundet', results.length, { count: results.length }) }}
				</p>
				<table class="ocs-org__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Overordnet organisation') }}</th>
							<th>{{ t('opencase', 'Medarbejdere') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="org in results"
							:key="org.org_uuid"
							class="ocs-org__row"
							@click="selectOrg(org)">
							<td class="ocs-org__name">{{ org.org_name }}</td>
							<td class="ocs-org__parent">{{ org.org_parent_name || '–' }}</td>
							<td class="ocs-org__count-cell">{{ org.member_count }}</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>

		<!-- ── Organisation detail ── -->
		<div v-else>
			<div class="ocs-org__detail-header">
				<NcButton @click="backToSearch">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('opencase', 'Tilbage til søgning') }}
				</NcButton>
			</div>

			<!-- Info card -->
			<div class="ocs-org__card">
				<div class="ocs-org__card-title">
					<OfficeBuildingIcon :size="24" />
					<h2>{{ selectedOrg.org_name }}</h2>
				</div>
				<div class="ocs-org__card-fields">
					<div v-if="selectedOrg.org_parent_name" class="ocs-org__card-field">
						<span class="ocs-org__card-label">{{ t('opencase', 'Overordnet organisation') }}</span>
						<span>{{ selectedOrg.org_parent_name }}</span>
					</div>
					<div class="ocs-org__card-field">
						<span class="ocs-org__card-label">{{ t('opencase', 'Medarbejdere') }}</span>
						<span>{{ selectedOrg.member_count }}</span>
					</div>
					<div v-if="selectedOrg.email" class="ocs-org__card-field">
						<span class="ocs-org__card-label">{{ t('opencase', 'E-mail') }}</span>
						<a :href="'mailto:' + selectedOrg.email">{{ selectedOrg.email }}</a>
					</div>
					<div v-if="selectedOrg.phone" class="ocs-org__card-field">
						<span class="ocs-org__card-label">{{ t('opencase', 'Telefon') }}</span>
						<span>{{ selectedOrg.phone }}</span>
					</div>
					<div v-if="selectedOrg.location" class="ocs-org__card-field">
						<span class="ocs-org__card-label">{{ t('opencase', 'Adresse') }}</span>
						<span>{{ selectedOrg.location }}</span>
					</div>
				</div>
			</div>

			<!-- Tabs -->
			<OverflowTabs :tabs="orgTabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Tab: Organisationsstruktur -->
			<div v-if="activeTab === 'orgtree'" class="ocs-org__tab-panel">
				<NcLoadingIcon v-if="loadingTree" :size="32" />
				<NcEmptyContent v-else-if="!loadingTree && orgRoots.length === 0"
					:title="t('opencase', 'Ingen organisationer')">
					<template #icon>
						<SitemapOutlineIcon :size="32" />
					</template>
				</NcEmptyContent>
				<ul v-else class="org-tree">
					<OrgTreeNode v-for="root in orgRoots"
						:key="root.org_uuid"
						:node="root"
						:children-map="orgChildrenMap"
						:user-org-uuids="selectedOrgUuidMap"
						:user-org-roles="{}"
						:auto-open-uuids="autoOpenUuidsMap" />
				</ul>
			</div>

			<!-- Tab: Medarbejdere -->
			<div v-else-if="activeTab === 'members'" class="ocs-org__tab-panel">
				<NcLoadingIcon v-if="loadingMembers" :size="32" />
				<NcEmptyContent v-else-if="!loadingMembers && members.length === 0"
					:title="t('opencase', 'Ingen medarbejdere')">
					<template #icon>
						<AccountGroupIcon :size="32" />
					</template>
				</NcEmptyContent>
				<table v-else class="ocs-org__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Brugernavn') }}</th>
							<th>{{ t('opencase', 'E-mail') }}</th>
							<th>{{ t('opencase', 'Telefon') }}</th>
							<th>{{ t('opencase', 'Rolle') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="m in members" :key="m.uuid" class="ocs-org__member-row">
							<td>{{ m.personname || '–' }}</td>
							<td class="ocs-org__mono">{{ m.username || '–' }}</td>
							<td>
								<a v-if="m.email" :href="'mailto:' + m.email">{{ m.email }}</a>
								<span v-else>–</span>
							</td>
							<td class="ocs-org__mono">{{ m.phone || '–' }}</td>
							<td>
								<span v-if="m.role" class="ocs-org__role-badge">{{ m.role }}</span>
								<span v-else>–</span>
							</td>
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
import SitemapOutlineIcon from 'vue-material-design-icons/SitemapOutline.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultipleOutlineIcon from 'vue-material-design-icons/AccountMultipleOutline.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import { showError } from '@nextcloud/dialogs'

import OrgTreeNode from '../components/OrgTreeNode.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import api from '../services/api.js'

export default {
	name: 'OrganisationSearchView',

	components: {
		NcButton,
		NcTextField,
		NcEmptyContent,
		NcLoadingIcon,
		MagnifyIcon,
		SitemapOutlineIcon,
		OfficeBuildingIcon,
		AccountGroupIcon,
		ArrowLeftIcon,
		OrgTreeNode,
		OverflowTabs,
	},

	data() {
		return {
			query: '',
			searching: false,
			searched: false,
			results: [],
			selectedOrg: null,
			members: [],
			loadingMembers: false,
			allOrgs: [],
			loadingTree: false,
			activeTab: 'orgtree',
		}
	},

	computed: {
		orgTabs() {
			return [
				{ id: 'orgtree', label: t('opencase', 'Organisationsstruktur'), icon: SitemapOutlineIcon },
				{ id: 'members', label: t('opencase', 'Medarbejdere'), count: this.members.length || null, icon: AccountMultipleOutlineIcon },
			]
		},

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

		orgRoots() {
			const allUuids = new Set(this.allOrgs.map(o => o.org_uuid))
			return this.allOrgs.filter(o => !o.org_parent_uuid || !allUuids.has(o.org_parent_uuid))
		},

		// Highlight just the selected org node in the tree
		selectedOrgUuidMap() {
			if (!this.selectedOrg) return {}
			return { [this.selectedOrg.org_uuid]: true }
		},

		// Auto-open: the selected org + all its ancestors
		autoOpenUuidsMap() {
			if (!this.selectedOrg) return {}
			const open = { [this.selectedOrg.org_uuid]: true }
			const parentOf = {}
			for (const org of this.allOrgs) {
				if (org.org_parent_uuid) parentOf[org.org_uuid] = org.org_parent_uuid
			}
			let cur = parentOf[this.selectedOrg.org_uuid]
			while (cur) {
				open[cur] = true
				cur = parentOf[cur]
			}
			return open
		},
	},

	mounted() {
		const uuid = this.$route.query.org
		if (uuid) {
			try {
				const stored = sessionStorage.getItem('oc_selected_org')
				if (stored) {
					const org = JSON.parse(stored)
					if (org.org_uuid === uuid) {
						this.selectOrg(org, { updateUrl: false })
						const tab = this.$route.query.tab
						if (tab) this.activeTab = tab
						return
					}
				}
			} catch (e) {}
			this.$router.replace({ query: {} })
		}
	},

	watch: {
		activeTab(val) {
			if (!this.selectedOrg) return
			if (this.$route.query.tab !== val) {
				this.$router.replace({ query: { org: this.selectedOrg.org_uuid, tab: val } })
			}
		},
	},

	methods: {
		async doSearch() {
			if (this.query.trim() === '') return
			this.searching = true
			this.searched  = false
			this.results   = []
			try {
				this.results  = await api.searchOrganisationsFull(this.query.trim()) || []
				this.searched = true
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		async selectOrg(org, { updateUrl = true } = {}) {
			this.selectedOrg = org
			this.members     = []
			this.activeTab   = 'orgtree'

			if (updateUrl) {
				sessionStorage.setItem('oc_selected_org', JSON.stringify(org))
				this.$router.replace({ query: { org: org.org_uuid, tab: 'orgtree' } })
			}

			this.loadingMembers = true
			api.getOrgMembers(org.org_uuid)
				.then(m => { this.members = m || [] })
				.catch(() => showError(t('opencase', 'Kunne ikke hente medarbejdere')))
				.finally(() => { this.loadingMembers = false })

			if (this.allOrgs.length === 0) {
				this.loadingTree = true
				api.getOrgTree()
					.then(orgs => { this.allOrgs = orgs || [] })
					.catch(() => showError(t('opencase', 'Kunne ikke hente organisationsstruktur')))
					.finally(() => { this.loadingTree = false })
			}
		},

		backToSearch() {
			this.selectedOrg = null
			this.members     = []
			sessionStorage.removeItem('oc_selected_org')
			this.$router.replace({ query: {} })
		},

		resetForm() {
			this.query    = ''
			this.results  = []
			this.searched = false
		},
	},
}
</script>

<style scoped>
.opencase-org-search {
	padding: 20px;
	max-width: 1200px;
}

.opencase-org-search h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
	font-weight: 700;
}

.ocs-org__form {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.ocs-org__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ocs-org__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-org__form-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ocs-org__loading {
	margin: 60px auto;
}

.ocs-org__count {
	margin: 0 0 10px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ocs-org__table {
	width: 100%;
	border-collapse: collapse;
}

.ocs-org__table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ocs-org__row {
	cursor: pointer;
	transition: background 0.15s;
}

.ocs-org__row:hover {
	background: var(--color-background-hover);
}

.ocs-org__row td,
.ocs-org__member-row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: middle;
}

.ocs-org__name {
	font-weight: 600;
}

.ocs-org__parent {
	color: var(--color-text-lighter);
}

.ocs-org__count-cell {
	font-family: var(--font-monospace, monospace);
	text-align: right;
	color: var(--color-text-maxcontrast);
}

.ocs-org__mono {
	font-family: var(--font-monospace, monospace);
	white-space: nowrap;
}

.ocs-org__role-badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.78em;
	font-weight: 600;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

/* Detail view */
.ocs-org__detail-header {
	margin-bottom: 20px;
}

.ocs-org__card {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 20px;
	margin-bottom: 28px;
}

.ocs-org__card-title {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 16px;
}

.ocs-org__card-title h2 {
	margin: 0;
	font-size: 1.3em;
	font-weight: 700;
}

.ocs-org__card-fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px 24px;
}

.ocs-org__card-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ocs-org__card-label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ocs-org__tab-panel {
	margin-bottom: 36px;
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
