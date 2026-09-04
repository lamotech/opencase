<template>
	<NcContent app-name="opencase">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :to="{ name: 'home' }"
					:exact="true"
					:name="t('opencase', 'Startside')">
					<template #icon>
						<HomeIcon :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :to="{ name: 'favorites' }"
					:exact="true"
					:name="t('opencase', 'Favoritter')">
					<template #icon>
						<StarIcon :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :to="{ name: 'recent' }"
					:exact="true"
					:name="t('opencase', 'Seneste')">
					<template #icon>
						<HistoryIcon :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :name="t('opencase', 'Søg')"
					v-model:open="searchGroupOpen"
					:allow-collapse="true"
					@click="searchGroupOpen = !searchGroupOpen">
					<template #icon>
						<MagnifyIcon :size="20" />
					</template>
					<NcAppNavigationItem :to="{ name: 'search' }"
						:exact="true"
						:name="t('opencase', 'Fritekst')">
						<template #icon>
							<MagnifyIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'search-cases' }"
						:name="t('opencase', 'Sager')">
						<template #icon>
							<FolderIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'search-documents' }"
						:name="t('opencase', 'Dokumenter')">
						<template #icon>
							<FileDocumentOutlineIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'search-citizen' }"
						:name="t('opencase', 'Borger')">
						<template #icon>
							<AccountSearchIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'search-company' }"
						:name="t('opencase', 'Virksomhed')">
						<template #icon>
							<OfficeBuildingIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'search-estate' }"
						:name="t('opencase', 'Ejendom')">
						<template #icon>
							<HomeCityIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'search-employees' }"
						:name="t('opencase', 'Medarbejder')">
						<template #icon>
							<AccountGroupIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'search-organisations' }"
						:name="t('opencase', 'Organisation')">
						<template #icon>
							<SitemapIcon :size="20" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>

				<NcAppNavigationItem :name="t('opencase', 'Sager')"
					v-model:open="casesGroupOpen"
					:allow-collapse="true"
					@click="casesGroupOpen = !casesGroupOpen">
					<template #icon>
						<FolderIcon :size="20" />
					</template>
					<NcAppNavigationItem :to="{ name: 'my-cases' }"
						:name="t('opencase', 'Mine sager')">
						<template #icon>
							<AccountIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'favorites-cases' }"
						:name="t('opencase', 'Favoritter')">
						<template #icon>
							<StarIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'recent-cases' }"
						:name="t('opencase', 'Seneste')">
						<template #icon>
							<HistoryIcon :size="20" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>

				<NcAppNavigationItem :name="t('opencase', 'Dokumenter')"
					v-model:open="documentsGroupOpen"
					:allow-collapse="true"
					@click="documentsGroupOpen = !documentsGroupOpen">
					<template #icon>
						<FileDocumentOutlineIcon :size="20" />
					</template>
					<NcAppNavigationItem :to="{ name: 'my-documents' }"
						:name="t('opencase', 'Mine dokumenter')">
						<template #icon>
							<AccountIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'favorites-documents' }"
						:name="t('opencase', 'Favoritter')">
						<template #icon>
							<StarIcon :size="20" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :to="{ name: 'recent-documents' }"
						:name="t('opencase', 'Seneste')">
						<template #icon>
							<HistoryIcon :size="20" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>

				<NcAppNavigationItem :to="{ name: 'shared' }"
					:name="t('opencase', 'Delt med mig')">
					<template #icon>
						<ShareVariantIcon :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :to="{ name: 'inbound' }"
					:name="t('opencase', 'Indkommende dokumenter')">
					<template #icon>
						<InboxArrowDownIcon :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>

			<template #footer>
				<NcAppNavigationItem v-if="canManageTemplates"
					class="opencase-manage-templates"
					:to="{ name: 'templates' }"
					:name="t('opencase', 'Administrer skabeloner')">
					<template #icon>
						<FileDocumentEditOutlineIcon :size="20" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem v-if="isAdministrator"
					class="opencase-manage-templates"
					:to="{ name: 'configuration' }"
					:name="t('opencase', 'Konfiguration')">
					<template #icon>
						<CogIcon :size="20" />
					</template>
				</NcAppNavigationItem>

			</template>
		</NcAppNavigation>

		<NewCaseDialog v-if="showNewCaseDialog" :case-type="selectedCaseType" @close="showNewCaseDialog = false" />
		<CreateEstateDialog v-if="showCreateEstateDialog" @close="showCreateEstateDialog = false" @created="onEstateCreated" />
		<NotificationsDialog v-if="showNotificationsDialog" @close="showNotificationsDialog = false" />

		<NcAppContent>
			<div class="opencase-topbar"
				:class="{ 'opencase-topbar--sidebar-open': aiEnabled && showAiPanel }">
				<div class="opencase-topbar__actions">
					<NcActions v-model:open="newCaseMenuOpen"
						class="opencase-topbar__create-btn"
						:primary="true"
						:force-menu="true"
						:menu-name="t('opencase', 'Opret')">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						<NcActionCaption :name="t('opencase', 'Ny sag')" />
						<NcActionButton v-for="type in caseTypes"
							:key="type.id"
							@click="openNewCaseDialog(type)">
							{{ type.name }}
						</NcActionButton>

						<template v-if="!enterpriseVersionEnabled">
							<NcActionCaption :name="t('opencase', 'Ny Ejendom')" />
							<NcActionButton @click="openCreateEstateDialog">
								{{ t('opencase', 'Ejendom') }}
							</NcActionButton>
						</template>
					</NcActions>
					<NcButton v-if="aiEnabled" @click="openPromptLibrary">
						<template #icon>
							<BookOpenIcon :size="20" />
						</template>
						{{ t('opencase', 'Promptbibliotek') }}
					</NcButton>
					<NcButton @click="showNotificationsDialog = true">
						<template #icon>
							<BellIcon :size="20" />
						</template>
						{{ t('opencase', 'Adviseringer') }}
					</NcButton>
				</div>
				<button v-if="aiEnabled"
					class="opencase-ai-wand"
					:title="t('opencase', 'AI-assistent')"
					:aria-label="t('opencase', 'AI-assistent')"
					@click="showAiPanel = !showAiPanel">
					<AutoFixIcon :size="22" />
				</button>
			</div>
			<router-view />
		</NcAppContent>

		<AiSidePanel v-if="aiEnabled" :open="showAiPanel" @close="showAiPanel = false" />
	</NcContent>
</template>

<script>
import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationSettings from '@nextcloud/vue/components/NcAppNavigationSettings'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'

import HomeIcon from 'vue-material-design-icons/Home.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import InboxArrowDownIcon from 'vue-material-design-icons/InboxArrowDown.vue'
import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountSearchIcon from 'vue-material-design-icons/AccountSearch.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import HomeCityIcon from 'vue-material-design-icons/HomeCity.vue'
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import BookOpenIcon from 'vue-material-design-icons/BookOpen.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'

import { loadState } from '@nextcloud/initial-state'

import AiSidePanel from './components/AiSidePanel.vue'
import NewCaseDialog from './components/NewCaseDialog.vue'
import CreateEstateDialog from './components/CreateEstateDialog.vue'
import NotificationsDialog from './components/NotificationsDialog.vue'

export default {
	name: 'App',

	components: {
		NcContent,
		NcButton,
		NcActions,
		NcActionButton,
		NcActionCaption,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		NcAppContent,
		HomeIcon,
		FolderIcon,
		MagnifyIcon,
		HistoryIcon,
		StarIcon,
		CogIcon,
		ShareVariantIcon,
		FileDocumentEditOutlineIcon,
		FileDocumentOutlineIcon,
		InboxArrowDownIcon,
		AutoFixIcon,
		AccountIcon,
		AccountSearchIcon,
		OfficeBuildingIcon,
		HomeCityIcon,
		AccountGroupIcon,
		SitemapIcon,
		PlusIcon,
		BookOpenIcon,
		BellIcon,
		AiSidePanel,
		NewCaseDialog,
		CreateEstateDialog,
		NotificationsDialog,
	},

	data() {
		return {
			showAiPanel: false,
			showNewCaseDialog: false,
			showCreateEstateDialog: false,
			newCaseMenuOpen: false,
			selectedCaseType: null,
			showNotificationsDialog: false,
			aiEnabled: loadState('opencase', 'ai_enabled', false),
			searchGroupOpen: false,
			casesGroupOpen: false,
			documentsGroupOpen: false,
		}
	},

	computed: {
		isAdministrator() {
			return this.$store.state.isAdministrator
		},
		isTemplateDesigner() {
			return this.$store.state.isTemplateDesigner
		},
		canManageTemplates() {
			return this.isAdministrator || this.isTemplateDesigner
		},
		caseTypes() {
			return this.$store.state.caseTypes
		},
		enterpriseVersionEnabled() {
			return this.$store.state.enterpriseVersionEnabled
		},
	},

	watch: {
		$route: {
			immediate: true,
			handler(route) {
				const searchRoutes = ['search', 'search-cases', 'search-documents', 'search-citizen', 'search-company', 'search-estate', 'search-employees', 'search-organisations']
				const casesRoutes = ['my-cases', 'favorites-cases', 'recent-cases']
				const documentsRoutes = ['my-documents', 'favorites-documents', 'recent-documents']
				if (searchRoutes.includes(route.name)) {
					this.searchGroupOpen = true
				}
				if (casesRoutes.includes(route.name)) {
					this.casesGroupOpen = true
				}
				if (documentsRoutes.includes(route.name)) {
					this.documentsGroupOpen = true
				}
			},
		},
	},

	async mounted() {
		// Fetch roles independently so a failure in the other calls
		// (e.g. empty stats for a fresh install) cannot prevent it from
		// committing to the store.
		this.$store.dispatch('fetchMyRoles').catch(() => {})
		this.$store.dispatch('fetchFavorites').catch(() => {})

		this.$store.dispatch('fetchOrganisations').catch(() => {})
		this.$store.dispatch('fetchStats').catch(() => {})
		this.$store.dispatch('fetchCaseTypes').catch(() => {})

		this._onOpenPrompt = () => {
			this.showAiPanel = true
		}
		subscribe('ai:open-prompt', this._onOpenPrompt)
	},

	beforeUnmount() {
		unsubscribe('ai:open-prompt', this._onOpenPrompt)
	},

	methods: {
		openNewCaseDialog(caseType) {
			this.selectedCaseType = caseType
			this.newCaseMenuOpen = false
			this.$nextTick(() => {
				this.showNewCaseDialog = true
			})
		},

		openPromptLibrary() {
			emit('ai:open-prompt')
		},

		openCreateEstateDialog() {
			this.newCaseMenuOpen = false
			this.$nextTick(() => {
				this.showCreateEstateDialog = true
			})
		},

		onEstateCreated(estateId) {
			this.$router.push({ name: 'estate-detail', params: { estateId } })
		},
	},
}
</script>

<style>
/* Hide the default NcAppSidebar toggle — OpenCase uses its own magic-wand button instead. */
.app-sidebar__toggle {
	display: none !important;
}

/*
 * The NcAppNavigationToggle button is absolutely positioned inside the nav panel
 * and protrudes into the content area by (default-clickable-area + app-navigation-padding).
 * All view root divs need at least that much inline-start padding to keep titles visible.
 */
#app-content-vue .opencase-home,
#app-content-vue .opencase-case-list,
#app-content-vue .opencase-case-detail,
#app-content-vue .opencase-doc-detail,
#app-content-vue .opencase-case-create,
#app-content-vue .opencase-recent,
#app-content-vue .opencase-search,
#app-content-vue .opencase-inbound,
#app-content-vue .opencase-favorites,
#app-content-vue .opencase-shared,
#app-content-vue .opencase-case-field-search,
#app-content-vue .opencase-doc-field-search,
#app-content-vue .opencase-citizen-search,
#app-content-vue .opencase-company-search,
#app-content-vue .opencase-estate-search,
#app-content-vue .opencase-employee-search,
#app-content-vue .opencase-org-search,
#app-content-vue .opencase-my-documents,
#app-content-vue .opencase-configuration,
#app-content-vue .template-fields,
#app-content-vue .template-manager {
	padding-inline-start: calc(var(--default-clickable-area) + var(--app-navigation-padding, 8px));
}
</style>

<style scoped>
.opencase-manage-templates {
	padding: 0px 5px;
}
.opencase-settings {
	padding: 12px;
}

.opencase-settings__info {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.opencase-config-btn {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 100%;
	height: 44px;
	background: none;
	border: none;
	border-radius: var(--border-radius);
	cursor: pointer;
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	font-weight: normal;
	text-align: left;
}

.opencase-config-btn:hover,
.opencase-config-btn:focus-visible {
	background-color: var(--color-background-hover);
	outline: none;
}

.opencase-config-btn__icon {
	flex-shrink: 0;
	opacity: 0.7;
}

.opencase-nav-new-case {
	list-style: none;
	padding: 8px 12px 4px;
}

/*
 * NcAppContent renders our slot straight into main.app-content, which is
 * position: initial — so this bar is positioned against .content (position: fixed),
 * which spans the full window width. The AI sidebar overlaps that area at
 * z-index 1500, so the bar has to be offset by the sidebar width by hand.
 * Mirrors --app-sidebar-width from NcAppSidebar.
 */
.opencase-topbar {
	--opencase-sidebar-width: clamp(300px, 27vw, 500px);
	position: absolute;
	top: 12px;
	right: 16px;
	z-index: 100;
	display: flex;
	align-items: center;
	gap: 12px;
	transition: right var(--animation-quick, 100ms) ease-in-out;
}

.opencase-topbar--sidebar-open {
	right: calc(var(--opencase-sidebar-width) + 16px);
}

/* Below this the sidebar covers the viewport, so there is nowhere to shift to. */
@media only screen and (max-width: 512px) {
	.opencase-topbar--sidebar-open {
		display: none;
	}
}

.opencase-topbar__actions {
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
	justify-content: flex-end;
}

.opencase-topbar__create-btn {
	font-size: 1rem;
}

.opencase-ai-wand {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border: none;
	border-radius: 50%;
	cursor: pointer;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
	transition: background 0.15s, box-shadow 0.15s;
}

.opencase-ai-wand:hover,
.opencase-ai-wand:focus-visible {
	background: var(--color-primary-element-hover);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
	outline: none;
}
</style>
