<template>
	<div class="opencase-case-create">
		<NcBreadcrumbs>
			<NcBreadcrumb :title="t('opencase', 'Sager')" :to="{ name: 'cases' }" />
			<NcBreadcrumb :title="t('opencase', 'Opret sag')" :disableDrop="true" />
		</NcBreadcrumbs>

		<h2>{{ t('opencase', 'Opret ny sag') }}</h2>

		<div class="opencase-case-create__form">
			<div class="opencase-case-create__field">
				<label for="title">{{ t('opencase', 'Titel') }} *</label>
				<NcTextField id="title"
					v-model="form.title"
					:placeholder="t('opencase', 'Sagstitel')"
					:error="!!errors.title"
					:helper-text="errors.title" />
			</div>

			<div class="opencase-case-create__field">
				<label for="organisation">{{ t('opencase', 'Organisation') }} *</label>
				<NcSelect v-model="selectedOrg"
					:options="orgOptions"
					:loading="orgSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter organisation')"
					@search="onOrgSearch"
					@update:model-value="onOrgSelected" />
				<span v-if="errors.organisation" class="opencase-case-create__error">
					{{ errors.organisation }}
				</span>
			</div>

			<div class="opencase-case-create__row">
				<div class="opencase-case-create__field">
					<label for="classification">{{ t('opencase', 'KLE-nummer') }} *</label>
					<NcSelect v-model="selectedClassification"
						:options="classificationOptions"
						:placeholder="t('opencase', 'Vælg KLE-emneord')"
						@update:model-value="onClassificationSelected" />
					<span v-if="errors.classification_code" class="opencase-case-create__error">
						{{ errors.classification_code }}
					</span>
				</div>

				<div class="opencase-case-create__field">
					<label for="sensitivity">{{ t('opencase', 'Følsomhed') }} *</label>
					<NcSelect v-model="selectedSensitivity"
						:options="sensitivityOptions"
						:placeholder="t('opencase', 'Vælg følsomhed')"
						@update:model-value="onSensitivitySelected" />
					<span v-if="errors.sensitivity" class="opencase-case-create__error">
						{{ errors.sensitivity }}
					</span>
				</div>
			</div>

			<div class="opencase-case-create__field">
				<label for="classification-facet">{{ t('opencase', 'Handlingsfacet') }} *</label>
				<NcSelect v-model="selectedClassificationFacet"
					:options="classificationFacetOptions"
					:placeholder="t('opencase', 'Vælg handlingsfacet')"
					@update:model-value="onClassificationFacetSelected" />
				<span v-if="errors.classification_facet_uuid" class="opencase-case-create__error">
					{{ errors.classification_facet_uuid }}
				</span>
			</div>

			<div class="opencase-case-create__field">
				<label for="insight-level">{{ t('opencase', 'Indsigtsgrad') }}</label>
				<NcSelect v-model="selectedInsightLevel"
					:options="insightLevelOptions"
					:placeholder="t('opencase', 'Vælg indsigtsgrad')"
					:clearable="true"
					@update:model-value="onInsightLevelSelected" />
			</div>

			<div class="opencase-case-create__field">
				<label for="responsible-user">{{ t('opencase', 'Ansvarlig') }} *</label>
				<NcSelect v-model="selectedResponsibleUser"
					:options="userOptions"
					:loading="userSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter bruger')"
					@search="onUserSearch"
					@update:model-value="onResponsibleUserSelected" />
				<span v-if="errors.responsible_user_id" class="opencase-case-create__error">
					{{ errors.responsible_user_id }}
				</span>
			</div>

			<div class="opencase-case-create__actions">
				<NcButton @click="$router.back()">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton type="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('opencase', 'Opret sag') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import NcBreadcrumbs from '@nextcloud/vue/components/NcBreadcrumbs'
import NcBreadcrumb from '@nextcloud/vue/components/NcBreadcrumb'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../services/api.js'

export default {
	name: 'CaseCreateView',

	components: {
		NcBreadcrumbs,
		NcBreadcrumb,
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
	},

	data() {
		const currentUser = getCurrentUser()
		const responsibleUserOption = currentUser
			? { id: currentUser.uid, label: currentUser.displayName || currentUser.uid }
			: null

		return {
			form: {
				title: '',
				organisation: '',
				classification_code: '',
				sensitivity: '',
				insight_level_id: null,
				classification_facet_uuid: '',
				responsible_user_id: currentUser?.uid || '',
			},
			selectedOrg: null,
			orgOptions: [],
			orgSearchLoading: false,
			orgSearchTimeout: null,
			selectedClassification: null,
			selectedSensitivity: null,
			selectedInsightLevel: null,
			selectedClassificationFacet: null,
			selectedResponsibleUser: responsibleUserOption,
			// Start with current user as the only option so it shows immediately
			userOptions: responsibleUserOption ? [responsibleUserOption] : [],
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

	async mounted() {
		this.$store.dispatch('fetchClassificationSubjects')
		this.$store.dispatch('fetchSensitivities')
		this.$store.dispatch('fetchInsightLevels')
		// Pre-load first 20 organisations so the dropdown isn't empty on open
		try {
			this.orgOptions = await api.searchOrganisations('')
		} catch (e) {
			// silently ignore
		}
		this.$store.dispatch('fetchClassificationFacets')
	},

	methods: {
		onOrgSelected(val) {
			this.form.organisation = val?.id || ''
		},

		onOrgSearch(query) {
			clearTimeout(this.orgSearchTimeout)
			this.orgSearchLoading = true
			this.orgSearchTimeout = setTimeout(async () => {
				try {
					this.orgOptions = await api.searchOrganisations(query)
				} catch (e) {
					// silently ignore search errors
				} finally {
					this.orgSearchLoading = false
				}
			}, 300)
		},

		onClassificationSelected(val) {
			this.form.classification_code = val?.id || ''
		},

		onSensitivitySelected(val) {
			this.form.sensitivity = val?.id || ''
		},

		onInsightLevelSelected(val) {
			this.form.insight_level_id = val?.id || null
		},

		onClassificationFacetSelected(val) {
			this.form.classification_facet_uuid = val?.id || ''
		},

		onResponsibleUserSelected(val) {
			this.form.responsible_user_id = val?.id || ''
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
				const created = await this.$store.dispatch('createCase', this.form)
				showSuccess(t('opencase', 'Sag oprettet'))
				this.$router.push({ name: 'case-detail', params: { id: created.id } })
			} catch (e) {
				const msg = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke oprette sag')
				showError(msg)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-case-create {
	padding: 20px;
	max-width: 700px;
}

.opencase-case-create h2 {
	margin: 16px 0;
	font-size: 1.4em;
}

.opencase-case-create__form {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.opencase-case-create__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}

.opencase-case-create__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-case-create__row {
	display: flex;
	gap: 16px;
}

.opencase-case-create__error {
	color: var(--color-error);
	font-size: 0.85em;
}

.opencase-case-create__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
</style>
