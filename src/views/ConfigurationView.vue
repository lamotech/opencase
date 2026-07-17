<template>
	<div class="opencase-configuration">
		<h2>{{ t('opencase', 'Konfiguration') }}</h2>

		<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

		<!-- Settings tab -->
		<div v-if="activeTab === 'settings'" class="opencase-configuration__content">
			<NcLoadingIcon v-if="loadingSettings" :size="32" />

			<div v-else class="opencase-configuration__settings">
				<div v-for="entry in settingsEntries"
					:key="entry.key"
					class="opencase-configuration__entry">
					<label :for="'config-' + entry.key" class="opencase-configuration__label">
						{{ entry.name || entry.key }}
					</label>
					<p v-if="entry.description" class="opencase-configuration__description">
						{{ entry.description }}
					</p>
					<NcTextField :id="'config-' + entry.key"
						v-model="settingsDraft[entry.key]"
						:placeholder="entry.key" />
				</div>

				<NcButton type="primary"
					:disabled="savingSettings"
					@click="saveSettings">
					{{ t('opencase', 'Gem') }}
				</NcButton>
			</div>
		</div>

		<!-- Code lists tab -->
		<div v-if="activeTab === 'codelists'" class="opencase-configuration__content">
			<ul class="opencase-configuration__codelists">
				<li v-for="cl in codeLists" :key="cl.list">
					<button class="opencase-configuration__codelist-btn" @click="openCodeList(cl)">
						{{ cl.label }}
					</button>
				</li>
			</ul>
		</div>

		<!-- Incoming documents tab -->
		<div v-if="activeTab === 'incoming'" class="opencase-configuration__content">
			<NcLoadingIcon v-if="incomingLoading" :size="32" />

			<div v-else class="opencase-configuration__settings">
				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="selectedIncomingOrg"
						:options="incomingOrgOptions"
						:loading="incomingOrgSearchLoading"
						:filterable="false"
						:clearable="true"
						:placeholder="t('opencase', 'Søg efter organisation')"
						@search="onIncomingOrgSearch" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Følsomhed') }}</label>
					<NcSelect v-model="selectedIncomingSensitivity"
						:options="sensitivityOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg følsomhed')" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'KLE-nummer') }}</label>
					<NcSelect v-model="selectedIncomingKle"
						:options="kleOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg KLE-emneord')" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Handlingsfacet') }}</label>
					<NcSelect v-model="selectedIncomingFacet"
						:options="facetOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg handlingsfacet')" />
				</div>

				<div class="opencase-configuration__entry">
					<label class="opencase-configuration__label">{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="selectedIncomingInsightLevel"
						:options="insightLevelOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg indsigtsgrad')" />
				</div>

				<NcButton type="primary"
					:disabled="incomingSaving"
					@click="saveIncoming">
					{{ t('opencase', 'Gem') }}
				</NcButton>
			</div>
		</div>

		<CodeListDialog v-if="openList"
			:list="openList.list"
			:title="openList.label"
			@close="openList = null" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import { showError, showSuccess } from '@nextcloud/dialogs'
import OverflowTabs from '../components/OverflowTabs.vue'
import CodeListDialog from '../components/CodeListDialog.vue'
import api from '../services/api.js'

const SETTINGS_KEYS = ['case_number_mask', 'search_max_result_count', 'search_page_size']

export default {
	name: 'ConfigurationView',

	components: { NcButton, NcTextField, NcLoadingIcon, NcSelect, OverflowTabs, CodeListDialog },

	data() {
		return {
			activeTab: 'settings',
			tabs: [
				{ id: 'settings', label: t('opencase', 'Indstillinger') },
				{ id: 'codelists', label: t('opencase', 'Værdilister') },
				{ id: 'incoming', label: t('opencase', 'Indkommende dokumenter') },
			],

			// Settings tab
			loadingSettings: true,
			savingSettings: false,
			settingsEntries: [],
			settingsDraft: {},

			// Code lists tab
			codeLists: [
				{ list: 'casestatus', label: t('opencase', 'Sagsstatus') },
				{ list: 'casetype', label: t('opencase', 'Sagstype') },
				{ list: 'contactroles', label: t('opencase', 'Kontakt roller') },
				{ list: 'documentcategory', label: t('opencase', 'Dokumentkategorier') },
				{ list: 'documentstatus', label: t('opencase', 'Dokumentstatus') },
				{ list: 'insightlevel', label: t('opencase', 'Indsigtsgrad') },
				{ list: 'participantroles', label: t('opencase', 'Parts roller') },
			],
			openList: null,

			// Incoming documents tab
			incomingLoading: true,
			incomingSaving: false,
			incomingOrgOptions: [],
			incomingOrgSearchLoading: false,
			incomingOrgSearchTimeout: null,
			selectedIncomingOrg: null,
			selectedIncomingSensitivity: null,
			selectedIncomingKle: null,
			selectedIncomingFacet: null,
			selectedIncomingInsightLevel: null,
		}
	},

	computed: {
		sensitivityOptions() {
			return this.$store.state.sensitivities.map(s => ({
				id: s.uuid,
				label: s.title,
			}))
		},
		kleOptions() {
			return this.$store.state.classificationSubjects
				.filter(s => /^\d{2}\.\d{2}\.\d{2}$/.test(s.code))
				.map(s => ({
					id: s.code,
					label: `${s.code} – ${s.title}`,
				}))
		},
		facetOptions() {
			return this.$store.state.classificationFacets
				.filter(f => /^[A-Za-z]\d{2}$/.test(f.code))
				.map(f => ({
					id: f.uuid,
					label: `${f.code} – ${f.title}`,
				}))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({
				id: l.id,
				label: l.name,
			}))
		},
	},

	async mounted() {
		try {
			const [allEntries] = await Promise.all([
				api.getConfig(),
				this.$store.dispatch('fetchSensitivities'),
				this.$store.dispatch('fetchInsightLevels'),
				this.$store.dispatch('fetchClassificationFacets'),
				this.$store.dispatch('fetchClassificationSubjects'),
			])

			this.settingsEntries = allEntries.filter(e => SETTINGS_KEYS.includes(e.key))
			this.settingsDraft = Object.fromEntries(this.settingsEntries.map(e => [e.key, e.value]))

			const cfg = Object.fromEntries(allEntries.map(e => [e.key, e.value]))
			this.initIncomingSelections(cfg)

			try {
				const orgs = await api.searchOrganisations('')
				this.incomingOrgOptions = orgs.map(o => ({ id: o.uuid, label: o.label }))
				// Resolve label for the pre-selected org (we only had UUID before)
				if (this.selectedIncomingOrg) {
					const match = this.incomingOrgOptions.find(o => o.id === this.selectedIncomingOrg.id)
					if (match) {
						this.selectedIncomingOrg = match
					} else {
						// Not in first 20 — keep UUID stub so the selection isn't lost
						this.incomingOrgOptions = [this.selectedIncomingOrg, ...this.incomingOrgOptions]
					}
				}
			} catch (e) {
				// silently ignore
			}
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente konfiguration'))
		} finally {
			this.loadingSettings = false
			this.incomingLoading = false
		}
	},

	methods: {
		// ── Settings tab ──────────────────────────────────────────────
		async saveSettings() {
			this.savingSettings = true
			try {
				const changed = this.settingsEntries.filter(e => this.settingsDraft[e.key] !== e.value)
				for (const entry of changed) {
					await api.updateConfigValue(entry.key, this.settingsDraft[entry.key])
					entry.value = this.settingsDraft[entry.key]
				}
				showSuccess(t('opencase', 'Konfiguration gemt'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme konfiguration'))
			} finally {
				this.savingSettings = false
			}
		},

		// ── Code lists tab ────────────────────────────────────────────
		openCodeList(cl) {
			this.openList = cl
		},

		// ── Incoming documents tab ────────────────────────────────────
		initIncomingSelections(cfg) {
			const orgUuid = cfg['incoming_organisation'] || ''
			if (orgUuid) {
				// Pre-select with UUID; label will be populated once org options load
				this.selectedIncomingOrg = { id: orgUuid, label: orgUuid }
			}

			const sensUuid = cfg['incoming_sensitivity'] || ''
			if (sensUuid) {
				const s = this.$store.state.sensitivities.find(s => s.uuid === sensUuid)
				if (s) this.selectedIncomingSensitivity = { id: s.uuid, label: s.title }
			}

			const kleCode = cfg['incoming_kle'] || ''
			if (kleCode) {
				const s = this.$store.state.classificationSubjects.find(s => s.code === kleCode)
				if (s) this.selectedIncomingKle = { id: s.code, label: `${s.code} – ${s.title}` }
			}

			const facetUuid = cfg['incoming_facet'] || ''
			if (facetUuid) {
				const f = this.$store.state.classificationFacets.find(f => f.uuid === facetUuid)
				if (f) this.selectedIncomingFacet = { id: f.uuid, label: `${f.code} – ${f.title}` }
			}

			const insightRaw = cfg['incoming_insight_level']
			if (insightRaw) {
				const insightId = parseInt(insightRaw)
				const l = this.$store.state.insightLevels.find(l => l.id === insightId)
				if (l) this.selectedIncomingInsightLevel = { id: l.id, label: l.name }
			}
		},

		onIncomingOrgSearch(query) {
			clearTimeout(this.incomingOrgSearchTimeout)
			this.incomingOrgSearchLoading = true
			this.incomingOrgSearchTimeout = setTimeout(async () => {
				try {
					const orgs = await api.searchOrganisations(query)
					this.incomingOrgOptions = orgs.map(o => ({ id: o.uuid, label: o.label }))
				} catch (e) {
					// silently ignore search errors
				} finally {
					this.incomingOrgSearchLoading = false
				}
			}, 300)
		},

		async saveIncoming() {
			this.incomingSaving = true
			try {
				const insightId = this.selectedIncomingInsightLevel?.id
				await Promise.all([
					api.updateConfigValue('incoming_organisation', this.selectedIncomingOrg?.id || ''),
					api.updateConfigValue('incoming_sensitivity', this.selectedIncomingSensitivity?.id || ''),
					api.updateConfigValue('incoming_kle', this.selectedIncomingKle?.id || ''),
					api.updateConfigValue('incoming_facet', this.selectedIncomingFacet?.id || ''),
					api.updateConfigValue('incoming_insight_level', insightId != null ? String(insightId) : ''),
				])
				showSuccess(t('opencase', 'Konfiguration gemt'))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme konfiguration'))
			} finally {
				this.incomingSaving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-configuration {
	padding: 20px;
}

.opencase-configuration__content {
	max-width: 600px;
}

.opencase-configuration__settings {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.opencase-configuration__entry {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-configuration__label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-configuration__description {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	margin: 0 0 2px;
	line-height: 1.4;
}

.opencase-configuration__codelists {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-configuration__codelist-btn {
	display: block;
	width: 100%;
	text-align: left;
	padding: 12px 16px;
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	font-size: var(--default-font-size);
	color: var(--color-main-text);
}

.opencase-configuration__codelist-btn:hover,
.opencase-configuration__codelist-btn:focus-visible {
	background-color: var(--color-background-hover);
	outline: none;
}
</style>
