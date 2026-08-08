<template>
	<div class="opencase-home">
		<div class="opencase-home__hero">
			<h2 class="opencase-home__title">
				{{ t('opencase', 'Velkommen til OpenCase') }}
			</h2>
			<p class="opencase-home__subtitle">
				{{ t('opencase', 'Et komplet sagshåndteringssystem til din organisation') }}
			</p>
		</div>

		<!-- Quick links -->
		<section class="opencase-home__section">
			<h3 class="opencase-home__section-title">
				{{ t('opencase', 'Hurtige genveje') }}
			</h3>
			<div class="opencase-home__cards">
				<router-link :to="{ name: 'my-cases' }" class="opencase-home__card">
					<div class="opencase-home__card-icon opencase-home__card-icon--primary">
						<AccountIcon :size="28" />
					</div>
					<div class="opencase-home__card-body">
						<strong>{{ t('opencase', 'Mine sager') }}</strong>
						<span>{{ t('opencase', 'Sager du er ansvarlig for') }}</span>
					</div>
				</router-link>

				<router-link :to="{ name: 'cases' }" class="opencase-home__card">
					<div class="opencase-home__card-icon opencase-home__card-icon--folder">
						<FolderIcon :size="28" />
					</div>
					<div class="opencase-home__card-body">
						<strong>{{ t('opencase', 'Alle sager') }}</strong>
						<span>{{ t('opencase', 'Gennemse alle sager') }}</span>
					</div>
				</router-link>

				<router-link :to="{ name: 'inbound' }" class="opencase-home__card">
					<div class="opencase-home__card-icon opencase-home__card-icon--inbound">
						<InboxArrowDownIcon :size="28" />
					</div>
					<div class="opencase-home__card-body">
						<strong>{{ t('opencase', 'Indkommende dokumenter') }}</strong>
						<span>{{ t('opencase', 'Behandl nye dokumenter') }}</span>
					</div>
				</router-link>

				<router-link :to="{ name: 'recent' }" class="opencase-home__card">
					<div class="opencase-home__card-icon opencase-home__card-icon--recent">
						<HistoryIcon :size="28" />
					</div>
					<div class="opencase-home__card-body">
						<strong>{{ t('opencase', 'Seneste aktivitet') }}</strong>
						<span>{{ t('opencase', 'Se nylige sager og dokumenter') }}</span>
					</div>
				</router-link>

				<router-link :to="{ name: 'favorites' }" class="opencase-home__card">
					<div class="opencase-home__card-icon opencase-home__card-icon--favorites">
						<StarIcon :size="28" />
					</div>
					<div class="opencase-home__card-body">
						<strong>{{ t('opencase', 'Favoritter') }}</strong>
						<span>{{ t('opencase', 'Dine markerede sager og dokumenter') }}</span>
					</div>
				</router-link>

				<router-link :to="{ name: 'search' }" class="opencase-home__card">
					<div class="opencase-home__card-icon opencase-home__card-icon--search">
						<MagnifyIcon :size="28" />
					</div>
					<div class="opencase-home__card-body">
						<strong>{{ t('opencase', 'Søg') }}</strong>
						<span>{{ t('opencase', 'Find sager, dokumenter og borgere') }}</span>
					</div>
				</router-link>
			</div>
		</section>

		<!-- Suggested work -->
		<section class="opencase-home__section">
			<h3 class="opencase-home__section-title">
				{{ t('opencase', 'Foreslået arbejde') }}
			</h3>
			<ul class="opencase-home__suggestions">
				<!-- Merged workflow tasks + reminders, sorted by deadline -->
				<template v-for="item in suggestedItems">
					<!-- Workflow task -->
					<li v-if="item.type === 'workflow'"
						:key="'wf-' + item.data.workflow_id"
						class="opencase-home__suggestion opencase-home__suggestion--urgent">
						<router-link :to="{ path: '/document/' + item.data.document_id, hash: '#tab-workflow' }">
							<SitemapIcon :size="20" />
							<div>
								<strong>
									{{ item.data.workflow_type === 'review'
										? t('opencase', 'Gennemse dokument')
										: t('opencase', 'Godkend eller afvis dokument') }}
								</strong>
								<p>
									{{ item.data.document_title }}
									<template v-if="item.data.step_deadline">
										· {{ t('opencase', 'Frist: {date}', { date: formatDeadline(item.data.step_deadline) }) }}
									</template>
								</p>
							</div>
						</router-link>
					</li>
					<!-- Reminder -->
					<li v-else-if="item.type === 'reminder'"
						:key="'rm-' + item.data.id"
						class="opencase-home__suggestion opencase-home__suggestion--reminder"
						:class="{ 'opencase-home__suggestion--overdue': isOverdue(item.data) }">
						<router-link :to="{ path: '/' + item.data.entity + '/' + item.data.entity_key, hash: '#tab-info' }">
							<BellAlertIcon :size="20" />
							<div>
								<strong>{{ item.data.title }}</strong>
								<p>
									<template v-if="item.data.deadline">
										{{ t('opencase', 'Frist: {date}', { date: formatDeadline(item.data.deadline) }) }}
									</template>
								</p>
							</div>
						</router-link>
					</li>
				</template>

				<li class="opencase-home__suggestion">
					<router-link :to="{ name: 'inbound' }">
						<InboxArrowDownIcon :size="20" />
						<div>
							<strong>{{ t('opencase', 'Gennemgå indkommende dokumenter') }}</strong>
							<p>{{ t('opencase', 'Tilknyt nye dokumenter til eksisterende sager eller opret nye sager.') }}</p>
						</div>
					</router-link>
				</li>
				<li class="opencase-home__suggestion">
					<router-link :to="{ name: 'my-cases' }">
						<AccountIcon :size="20" />
						<div>
							<strong>{{ t('opencase', 'Følg op på dine aktive sager') }}</strong>
							<p>{{ t('opencase', 'Se status på de sager, du er ansvarlig for, og opdater dem.') }}</p>
						</div>
					</router-link>
				</li>
				<li class="opencase-home__suggestion">
					<router-link :to="{ name: 'recent' }">
						<HistoryIcon :size="20" />
						<div>
							<strong>{{ t('opencase', 'Fortsæt hvor du slap') }}</strong>
							<p>{{ t('opencase', 'Se seneste sager og dokumenter du har arbejdet med.') }}</p>
						</div>
					</router-link>
				</li>
				<li class="opencase-home__suggestion">
					<router-link :to="{ name: 'shared' }">
						<ShareVariantIcon :size="20" />
						<div>
							<strong>{{ t('opencase', 'Tjek hvad der er delt med dig') }}</strong>
							<p>{{ t('opencase', 'Se sager og dokumenter som kolleger har givet dig adgang til.') }}</p>
						</div>
					</router-link>
				</li>
			</ul>
		</section>

		<NewCaseDialog v-if="showNewCaseDialog" :case-type="selectedCaseType" @close="showNewCaseDialog = false" />
		<NotificationsDialog v-if="showNotificationsDialog" @close="showNotificationsDialog = false" />
	</div>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import BookOpenIcon from 'vue-material-design-icons/BookOpen.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import InboxArrowDownIcon from 'vue-material-design-icons/InboxArrowDown.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import SitemapIcon from 'vue-material-design-icons/Sitemap.vue'
import BellAlertIcon from 'vue-material-design-icons/BellAlert.vue'

import NewCaseDialog from '../components/NewCaseDialog.vue'
import NotificationsDialog from '../components/NotificationsDialog.vue'
import api from '../services/api.js'
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'HomeView',

	components: {
		NcButton,
		NcActions,
		NcActionButton,
		PlusIcon,
		BookOpenIcon,
		BellIcon,
		FolderIcon,
		AccountIcon,
		HistoryIcon,
		StarIcon,
		MagnifyIcon,
		InboxArrowDownIcon,
		ShareVariantIcon,
		SitemapIcon,
		BellAlertIcon,
		NewCaseDialog,
		NotificationsDialog,
	},

	data() {
		return {
			showNewCaseDialog: false,
			selectedCaseType: null,
			showNotificationsDialog: false,
			workflowTasks: [],
			myReminders: [],
			aiEnabled: loadState('opencase', 'ai_enabled', false),
		}
	},

	computed: {
		caseTypes() {
			return this.$store.state.caseTypes
		},
		suggestedItems() {
			const tasks = (this.workflowTasks ?? []).map(t => ({
				type: 'workflow',
				deadline: t.step_deadline ?? null,
				data: t,
			}))
			const reminders = (this.myReminders ?? []).map(r => ({
				type: 'reminder',
				deadline: r.deadline ?? null,
				data: r,
			}))
			return [...tasks, ...reminders].sort((a, b) => {
				if (!a.deadline && !b.deadline) return 0
				if (!a.deadline) return 1
				if (!b.deadline) return -1
				return new Date(a.deadline) - new Date(b.deadline)
			})
		},
	},

	mounted() {
		api.getMyWorkflowTasks().then(tasks => { this.workflowTasks = tasks ?? [] }).catch(() => {})
		api.getMyActiveReminders().then(reminders => { this.myReminders = reminders ?? [] }).catch(() => {})
		this.$store.dispatch('fetchCaseTypes').catch(() => {})
	},

	methods: {
		openNewCaseDialog(caseType) {
			this.selectedCaseType = caseType
			this.showNewCaseDialog = true
		},

		openPromptLibrary() {
			emit('ai:open-prompt')
		},

		formatDeadline(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},

		isOverdue(reminder) {
			if (!reminder.deadline) return false
			return new Date(reminder.deadline) < new Date()
		},
	},
}
</script>

<style scoped>
.opencase-home {
	max-width: 960px;
	margin: 0 auto;
	padding: 32px 24px 48px;
}

.opencase-home__hero {
	text-align: center;
	padding: 48px 0 40px;
}

.opencase-home__title {
	font-size: 2rem;
	font-weight: 700;
	margin: 0 0 12px;
	color: var(--color-main-text);
}

.opencase-home__subtitle {
	font-size: 1.1rem;
	color: var(--color-text-lighter);
	margin: 0 0 28px;
}

.opencase-home__hero-actions {
	display: flex;
	gap: 12px;
	justify-content: center;
	flex-wrap: wrap;
}

.opencase-home__create-btn {
	font-size: 1rem;
}

.opencase-home__section {
	margin-bottom: 48px;
}

.opencase-home__section-title {
	font-size: 1rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: var(--color-text-lighter);
	margin: 0 0 16px;
}

/* Quick-link cards */
.opencase-home__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 16px;
}

.opencase-home__card {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 18px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	text-decoration: none;
	color: var(--color-main-text);
	transition: box-shadow 0.15s, border-color 0.15s;
}

.opencase-home__card:hover,
.opencase-home__card:focus-visible {
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
	border-color: var(--color-primary-element);
	outline: none;
}

.opencase-home__card-icon {
	flex-shrink: 0;
	width: 52px;
	height: 52px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
}

.opencase-home__card-icon--primary {
	background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);
}

.opencase-home__card-icon--folder {
	background: color-mix(in srgb, #f59e0b 15%, transparent);
	color: #d97706;
}

.opencase-home__card-icon--inbound {
	background: color-mix(in srgb, #3b82f6 15%, transparent);
	color: #2563eb;
}

.opencase-home__card-icon--recent {
	background: color-mix(in srgb, #8b5cf6 15%, transparent);
	color: #7c3aed;
}

.opencase-home__card-icon--favorites {
	background: color-mix(in srgb, #ef4444 15%, transparent);
	color: #dc2626;
}

.opencase-home__card-icon--search {
	background: color-mix(in srgb, #10b981 15%, transparent);
	color: #059669;
}

.opencase-home__card-body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.opencase-home__card-body strong {
	font-size: 0.95rem;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.opencase-home__card-body span {
	font-size: 0.82rem;
	color: var(--color-text-lighter);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

/* Suggested work list */
.opencase-home__suggestions {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.opencase-home__suggestion--urgent a {
	border-color: var(--color-primary-element);
	background: color-mix(in srgb, var(--color-primary-element) 6%, var(--color-main-background));
}

.opencase-home__suggestion--urgent .material-design-icon {
	color: var(--color-primary-element);
}

.opencase-home__suggestion--reminder a {
	border-color: #f59e0b;
	background: color-mix(in srgb, #f59e0b 6%, var(--color-main-background));
}

.opencase-home__suggestion--reminder .material-design-icon {
	color: #d97706;
}

.opencase-home__suggestion--overdue a {
	border-color: var(--color-error);
	background: color-mix(in srgb, var(--color-error) 6%, var(--color-main-background));
}

.opencase-home__suggestion--overdue .material-design-icon {
	color: var(--color-error);
}

.opencase-home__suggestion a {
	display: flex;
	align-items: flex-start;
	gap: 14px;
	padding: 16px 18px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	text-decoration: none;
	color: var(--color-main-text);
	transition: box-shadow 0.15s, border-color 0.15s;
}

.opencase-home__suggestion a:hover,
.opencase-home__suggestion a:focus-visible {
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
	border-color: var(--color-primary-element);
	outline: none;
}

.opencase-home__suggestion .material-design-icon {
	margin-top: 2px;
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.opencase-home__suggestion strong {
	display: block;
	font-weight: 600;
	margin-bottom: 2px;
}

.opencase-home__suggestion p {
	margin: 0;
	font-size: 0.88rem;
	color: var(--color-text-lighter);
}
</style>
