<template>
	<NcDialog :name="t('opencase', 'Journaliser på ny sag')"
		size="large"
		@close="$emit('close')">
		<div class="opencase-move-new-case">
			<div class="opencase-move-new-case__field">
				<label>{{ t('opencase', 'Titel') }} *</label>
				<NcTextField v-model="form.title"
					:placeholder="t('opencase', 'Sagstitel')"
					:error="!!errors.title"
					:helper-text="errors.title || ''" />
			</div>

			<div class="opencase-move-new-case__field">
				<label>{{ t('opencase', 'Organisation') }} *</label>
				<NcSelect v-model="selectedOrg"
					:options="orgOptions"
					:loading="orgSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter organisation')"
					@search="onOrgSearch"
					@update:model-value="form.organisation = $event?.id || ''" />
				<span v-if="errors.organisation" class="opencase-move-new-case__error">{{ errors.organisation }}</span>
			</div>

			<div class="opencase-move-new-case__row">
				<div class="opencase-move-new-case__field">
					<label>{{ t('opencase', 'KLE-nummer') }} *</label>
					<NcSelect v-model="selectedClassification"
						:options="classificationOptions"
						:placeholder="t('opencase', 'Vælg KLE-emneord')"
						@update:model-value="form.classification_code = $event?.id || ''" />
					<span v-if="errors.classification_code" class="opencase-move-new-case__error">{{ errors.classification_code }}</span>
				</div>

				<div class="opencase-move-new-case__field">
					<label>{{ t('opencase', 'Følsomhed') }} *</label>
					<NcSelect v-model="selectedSensitivity"
						:options="sensitivityOptions"
						:placeholder="t('opencase', 'Vælg følsomhed')"
						@update:model-value="form.sensitivity = $event?.id || ''" />
					<span v-if="errors.sensitivity" class="opencase-move-new-case__error">{{ errors.sensitivity }}</span>
				</div>
			</div>

			<div class="opencase-move-new-case__field">
				<label>{{ t('opencase', 'Handlingsfacet') }} *</label>
				<NcSelect v-model="selectedClassificationFacet"
					:options="classificationFacetOptions"
					:placeholder="t('opencase', 'Vælg handlingsfacet')"
					@update:model-value="form.classification_facet_uuid = $event?.id || ''" />
				<span v-if="errors.classification_facet_uuid" class="opencase-move-new-case__error">{{ errors.classification_facet_uuid }}</span>
			</div>

			<div class="opencase-move-new-case__field">
				<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
				<NcSelect v-model="selectedInsightLevel"
					:options="insightLevelOptions"
					:clearable="true"
					:placeholder="t('opencase', 'Vælg indsigtsgrad')"
					@update:model-value="form.insight_level_id = $event?.id || null" />
			</div>

			<div class="opencase-move-new-case__field">
				<label>{{ t('opencase', 'Ansvarlig') }} *</label>
				<NcSelect v-model="selectedResponsibleUser"
					:options="userOptions"
					:loading="userSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter bruger')"
					@search="onUserSearch"
					@update:model-value="form.responsible_user_id = $event?.id || ''" />
				<span v-if="errors.responsible_user_id" class="opencase-move-new-case__error">{{ errors.responsible_user_id }}</span>
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="confirm">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('opencase', 'Opret sag og journaliser') }}
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

import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'

import api from '../services/api.js'

export default {
	name: 'MoveToNewCaseDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon },

	props: {
		documentId: { type: [String, Number], required: true },
	},

	emits: ['close', 'moved'],

	data() {
		const currentUser = getCurrentUser()
		const responsibleOption = currentUser
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
		this.$store.dispatch('fetchClassificationFacets')
		try {
			this.orgOptions = await api.searchOrganisations('')
		} catch {
			// ignore
		}
	},

	methods: {
		onOrgSearch(query) {
			clearTimeout(this.orgSearchTimeout)
			this.orgSearchLoading = true
			this.orgSearchTimeout = setTimeout(async () => {
				try {
					this.orgOptions = await api.searchOrganisations(query)
				} catch {
					// ignore
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
				} catch {
					// ignore
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

		async confirm() {
			if (!this.validate() || this.saving) return
			this.saving = true
			try {
				const created = await this.$store.dispatch('createCase', this.form)
				const doc = await api.moveDocumentToCase(this.documentId, created.case_number)
				this.$emit('moved', { doc, case: created })
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Handlingen fejlede.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-move-new-case {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 4px 0;
}

.opencase-move-new-case__row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.opencase-move-new-case__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-move-new-case__field label {
	font-size: 0.9em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.opencase-move-new-case__error {
	color: var(--color-error);
	font-size: 0.85em;
}
</style>
