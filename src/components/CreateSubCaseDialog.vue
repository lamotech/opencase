<template>
	<NcDialog :name="t('opencase', 'Opret undersag')"
		size="normal"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-sub-case">
			<div class="opencase-sub-case__field">
				<label>{{ t('opencase', 'Titel') }} *</label>
				<NcTextField v-model="form.title" />
				<span v-if="errors.title" class="opencase-sub-case__error">{{ errors.title }}</span>
			</div>

			<div class="opencase-sub-case__field">
				<label>{{ t('opencase', 'Organisation') }} *</label>
				<NcSelect v-model="selectedOrg"
					:options="orgOptions"
					:loading="orgSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter organisation')"
					@search="onOrgSearch"
					@update:model-value="v => form.organisation = v?.id || ''" />
				<span v-if="errors.organisation" class="opencase-sub-case__error">{{ errors.organisation }}</span>
			</div>

			<div class="opencase-sub-case__field">
				<label>{{ t('opencase', 'KLE-nummer') }} *</label>
				<NcSelect v-model="selectedClassification"
					:options="classificationOptions"
					:placeholder="t('opencase', 'Vælg KLE-emneord')"
					@update:model-value="v => form.classification_code = v?.id || ''" />
				<span v-if="errors.classification_code" class="opencase-sub-case__error">{{ errors.classification_code }}</span>
			</div>

			<div class="opencase-sub-case__field">
				<label>{{ t('opencase', 'Følsomhed') }} *</label>
				<NcSelect v-model="selectedSensitivity"
					:options="sensitivityOptions"
					@update:model-value="v => form.sensitivity = v?.id || null" />
				<span v-if="errors.sensitivity" class="opencase-sub-case__error">{{ errors.sensitivity }}</span>
			</div>

			<div class="opencase-sub-case__field">
				<label>{{ t('opencase', 'Handlingsfacet') }} *</label>
				<NcSelect v-model="selectedClassificationFacet"
					:options="classificationFacetOptions"
					:placeholder="t('opencase', 'Vælg handlingsfacet')"
					@update:model-value="v => form.classification_facet_uuid = v?.id || ''" />
				<span v-if="errors.classification_facet_uuid" class="opencase-sub-case__error">{{ errors.classification_facet_uuid }}</span>
			</div>

			<div class="opencase-sub-case__field">
				<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
				<NcSelect v-model="selectedInsightLevel"
					:options="insightLevelOptions"
					:placeholder="t('opencase', 'Vælg indsigtsgrad')"
					:clearable="true"
					@update:model-value="v => form.insight_level_id = v?.id || null" />
			</div>

			<div class="opencase-sub-case__field">
				<label>{{ t('opencase', 'Ansvarlig') }} *</label>
				<NcSelect v-model="selectedResponsibleUser"
					:options="userOptions"
					:loading="userSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter bruger')"
					@search="onUserSearch"
					@update:model-value="v => form.responsible_user_id = v?.id || null" />
				<span v-if="errors.responsible_user_id" class="opencase-sub-case__error">{{ errors.responsible_user_id }}</span>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Annuller') }}</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencase', 'Opret undersag') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../services/api.js'

export default {
	name: 'CreateSubCaseDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
	},

	props: {
		caseData: { type: Object, required: true },
	},

	emits: ['close', 'created'],

	data() {
		const sensitivities = this.$store.state.sensitivities
		const sensitivityMatch = sensitivities.find(s => s.key === this.caseData.sensitivity_key) || null

		const classSubjects = this.$store.state.classificationSubjects
		const classMatch = classSubjects.find(s => s.code === this.caseData.classification_code) || null

		const responsibleUid  = this.caseData.responsible_user_id || null
		const responsibleName = this.caseData.responsible_user_display_name || responsibleUid || ''
		const responsibleOption = responsibleUid ? { id: responsibleUid, label: responsibleName } : null

		const insightLevels = this.$store.state.insightLevels
		const insightMatch = this.caseData.insight_level_id
			? insightLevels.find(l => l.id === this.caseData.insight_level_id) || null
			: null

		const initialOrg = this.caseData.organisation
			? { id: this.caseData.organisation, label: this.caseData.organisation }
			: null

		const facets = this.$store.state.classificationFacets
		const facetMatch = this.caseData.classification_facet_uuid
			? facets.find(f => f.uuid === this.caseData.classification_facet_uuid) || null
			: null

		return {
			form: {
				title: t('opencase', 'Undersag - {title}', { title: this.caseData.title }),
				organisation: this.caseData.organisation || '',
				classification_code: this.caseData.classification_code || '',
				sensitivity: this.caseData.sensitivity_key || null,
				insight_level_id: this.caseData.insight_level_id || null,
				classification_facet_uuid: this.caseData.classification_facet_uuid || '',
				responsible_user_id: responsibleUid,
			},

			selectedOrg: initialOrg,
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
			userOptions: responsibleOption ? [responsibleOption] : [],
			userSearchLoading: false,
			userSearchTimeout: null,

			errors: {},
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
			return this.$store.state.sensitivities.map(s => ({ id: s.key, label: s.title }))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({ id: l.id, label: l.name }))
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

	mounted() {
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
				} catch (e) { /* ignore */ } finally {
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
				} catch (e) { /* ignore */ } finally {
					this.userSearchLoading = false
				}
			}, 300)
		},

		validate() {
			this.errors = {}
			if (!this.form.title.trim()) this.errors.title = t('opencase', 'Påkrævet')
			if (!this.form.organisation) this.errors.organisation = t('opencase', 'Påkrævet')
			if (!this.form.classification_code) this.errors.classification_code = t('opencase', 'Påkrævet')
			if (!this.form.sensitivity) this.errors.sensitivity = t('opencase', 'Påkrævet')
			if (!this.form.classification_facet_uuid) this.errors.classification_facet_uuid = t('opencase', 'Påkrævet')
			if (!this.form.responsible_user_id) this.errors.responsible_user_id = t('opencase', 'Påkrævet')
			return Object.keys(this.errors).length === 0
		},

		async save() {
			if (!this.validate()) return

			this.saving = true
			try {
				const newCase = await api.createCase({
					...this.form,
					parent_case_id: this.caseData.id,
				})
				showSuccess(t('opencase', 'Undersag oprettet'))
				this.$emit('created', newCase)
			} catch (e) {
				const msg = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke oprette undersag')
				showError(msg)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-sub-case {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
}

.opencase-sub-case__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-sub-case__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-sub-case__error {
	color: var(--color-error);
	font-size: 0.85em;
}
</style>
