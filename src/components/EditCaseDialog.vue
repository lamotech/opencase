<template>
	<NcDialog :name="t('opencase', 'Rediger sag')"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-edit-case">
			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'Titel') }}</label>
				<NcTextField v-model="form.title" />
			</div>

			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'Organisation') }}</label>
				<NcSelect v-model="selectedOrg"
					:options="orgOptions"
					:loading="orgSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter organisation')"
					@search="onOrgSearch"
					@update:model-value="v => form.organisation = v?.id || ''" />
			</div>

			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'KLE-nummer') }}</label>
				<NcSelect v-model="selectedClassification"
					:options="classificationOptions"
					:placeholder="t('opencase', 'Vælg KLE-emneord')"
					@update:model-value="v => form.classification_code = v?.id || ''" />
			</div>

			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'Følsomhed') }}</label>
				<NcSelect v-model="selectedSensitivity"
					:options="sensitivityOptions"
					@update:model-value="v => form.sensitivity = v?.id || null" />
			</div>

			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'Handlingsfacet') }} *</label>
				<NcSelect v-model="selectedClassificationFacet"
					:options="classificationFacetOptions"
					:placeholder="t('opencase', 'Vælg handlingsfacet')"
					@update:model-value="v => form.classification_facet_uuid = v?.id || ''" />
			</div>

			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
				<NcSelect v-model="selectedInsightLevel"
					:options="insightLevelOptions"
					:placeholder="t('opencase', 'Vælg indsigtsgrad')"
					:clearable="true"
					@update:model-value="v => form.insight_level_id = v?.id || null" />
			</div>

			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'Ansvarlig') }}</label>
				<NcSelect v-model="selectedResponsibleUser"
					:options="userOptions"
					:loading="userSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter bruger')"
					@search="onUserSearch"
					@update:model-value="v => form.responsible_user_id = v?.id || null" />
			</div>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Annuller') }}</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				{{ t('opencase', 'Gem') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../services/api.js'

export default {
	name: 'EditCaseDialog',
	components: { NcDialog, NcButton, NcTextField, NcSelect },
	props: {
		caseData: { type: Object, required: true },
	},
	data() {
		const sensitivities = this.$store.state.sensitivities
		const sensitivityMatch = sensitivities.find(s => s.key === this.caseData.sensitivity_key) || null

		const classSubjects = this.$store.state.classificationSubjects
		const classMatch = classSubjects.find(s => s.code === this.caseData.classification_code) || null

		// Build initial responsible user option from the case's existing data.
		// The API returns both responsible_user_id (uid) and responsible_user_display_name.
		const responsibleUid    = this.caseData.responsible_user_id || null
		const responsibleName   = this.caseData.responsible_user_display_name || responsibleUid || ''
		const responsibleOption = responsibleUid ? { id: responsibleUid, label: responsibleName } : null

		const insightLevels = this.$store.state.insightLevels
		const insightMatch = this.caseData.insight_level_id
			? insightLevels.find(l => l.id === this.caseData.insight_level_id) || null
			: null

		const initialOrg = this.caseData.organisation
			? { id: this.caseData.organisation, label: this.caseData.organisation } || null
			: null
		const facets = this.$store.state.classificationFacets
		const facetMatch = this.caseData.classification_facet_uuid
			? facets.find(f => f.uuid === this.caseData.classification_facet_uuid) || null
			: null

		return {
			form: {
				title: this.caseData.title,
				organisation: this.caseData.organisation,
				classification_code: this.caseData.classification_code || '',
				sensitivity: this.caseData.sensitivity_key || null,
				insight_level_id: this.caseData.insight_level_id || null,
				classification_facet_uuid: this.caseData.classification_facet_uuid || '',
				responsible_user_id: responsibleUid,
			},
			selectedOrg: initialOrg,
			// Seed with current org so it shows without typing
			orgOptions: initialOrg ? [initialOrg] : [],
			orgSearchLoading: false,
			orgSearchTimeout: null,
			selectedClassification: classMatch
				? { id: classMatch.code, label: `${classMatch.code} – ${classMatch.title}` }
				: (this.caseData.classification_code
					? { id: this.caseData.classification_code, label: this.caseData.classification_code }
					: null),
			selectedSensitivity: sensitivityMatch
				? { id: sensitivityMatch.key, label: sensitivityMatch.title }
				: null,
			selectedInsightLevel: insightMatch
				? { id: insightMatch.id, label: insightMatch.name }
				: null,
			selectedClassificationFacet: facetMatch
				? { id: facetMatch.uuid, label: `${facetMatch.code} – ${facetMatch.title}` }
				: (this.caseData.classification_facet_uuid
					? { id: this.caseData.classification_facet_uuid, label: this.caseData.classification_facet_code ? `${this.caseData.classification_facet_code} – ${this.caseData.classification_facet_title}` : this.caseData.classification_facet_uuid }
					: null),
			selectedResponsibleUser: responsibleOption,
			// Seed the list with the current responsible user so it's visible without typing
			userOptions: responsibleOption ? [responsibleOption] : [],
			userSearchLoading: false,
			userSearchTimeout: null,
			saving: false,
		}
	},
	computed: {
		classificationOptions() {
			return this.$store.state.classificationSubjects
				.filter(s => /^\d{2}\.\d{2}\.\d{2}$/.test(s.code))
				.map(s => ({
					id: s.code,
					label: `${s.code} – ${s.title}`,
				}))
		},
		sensitivityOptions() {
			return this.$store.state.sensitivities.map(s => ({
				id: s.key,
				label: s.title,
			}))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({
				id: l.id,
				label: l.name,
			}))
		},
		classificationFacetOptions() {
			return this.$store.state.classificationFacets
				.filter(f => /^[A-Za-z]\d{2}$/.test(f.code))
				.map(f => ({
					id: f.uuid,
					label: `${f.code} – ${f.title}`,
				}))
		},
	},
	watch: {
		sensitivityOptions(options) {
			if (!this.selectedSensitivity && this.form.sensitivity) {
				const match = options.find(o => o.id === this.form.sensitivity)
				if (match) this.selectedSensitivity = match
			}
		},
		classificationOptions(options) {
			if (!this.selectedClassification && this.form.classification_code) {
				const match = options.find(o => o.id === this.form.classification_code)
				if (match) this.selectedClassification = match
			}
		},
		insightLevelOptions(options) {
			if (!this.selectedInsightLevel && this.form.insight_level_id) {
				const match = options.find(o => o.id === this.form.insight_level_id)
				if (match) this.selectedInsightLevel = match
			}
		},
		organisationOptions(options) {
			if (!this.selectedOrg && this.form.organisation) {
				const match = options.find(o => o.id === this.form.organisation)
				if (match) this.selectedOrg = match
			}
		},
		classificationFacetOptions(options) {
			if (!this.selectedClassificationFacet && this.form.classification_facet_uuid) {
				const match = options.find(o => o.id === this.form.classification_facet_uuid)
				if (match) this.selectedClassificationFacet = match
			}
		},
	},
	async mounted() {
		this.$store.dispatch('fetchClassificationSubjects')
		this.$store.dispatch('fetchSensitivities')
		this.$store.dispatch('fetchInsightLevels')
		this.$store.dispatch('fetchClassificationFacets')
	},
	methods: {
		onOrgSearch(query) {
			clearTimeout(this.orgSearchTimeout)
			this.orgSearchLoading = true
			this.orgSearchTimeout = setTimeout(async () => {
				try {
					this.orgOptions = await api.searchOrganisations(query)
				} catch (e) {
					// silently ignore
				} finally {
					this.orgSearchLoading = false
				}
			}, 300)
		},

		onUserSearch(query) {
			clearTimeout(this.userSearchTimeout)
			if (!query || query.length < 2) return
			this.userSearchLoading = true
			this.userSearchTimeout = setTimeout(async () => {
				try {
					this.userOptions = await api.searchUsers(query)
				} catch (e) {
					// silently ignore search errors
				} finally {
					this.userSearchLoading = false
				}
			}, 300)
		},

		async save() {
			this.saving = true
			try {
				const updated = await this.$store.dispatch('updateCase', {
					id: this.caseData.id, payload: this.form,
				})
				showSuccess(t('opencase', 'Sag opdateret'))
				this.$emit('saved', updated)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke opdatere sag'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-edit-case { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-edit-case__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-edit-case__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
</style>
