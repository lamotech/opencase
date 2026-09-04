<template>
	<NcDialog :name="dialogName"
		size="large"
		@close="$emit('close')">
		<div class="opencase-create-sepsheet">
			<div class="opencase-create-sepsheet__field">
				<label>{{ t('opencase', 'Type') }} *</label>
				<!-- The type is fixed once printed: the QR code identifies how the sheet is filed -->
				<NcSelect v-model="selectedType"
					:options="typeOptions"
					:clearable="false"
					:disabled="isEdit"
					:placeholder="t('opencase', 'Vælg type')" />
			</div>

			<!-- Existing case -->
			<div v-if="selectedType && selectedType.id === 'existing case'" class="opencase-create-sepsheet__field">
				<label>{{ t('opencase', 'Sag') }} *</label>
				<NcSelect v-model="selectedCase"
					:options="caseOptions"
					:loading="caseSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter sagsnummer eller titel')"
					@search="onCaseSearch" />
				<span v-if="errors.case_number" class="opencase-create-sepsheet__error">
					{{ errors.case_number }}
				</span>
			</div>

			<!-- New case -->
			<template v-if="selectedType && selectedType.id === 'new case'">
				<div class="opencase-create-sepsheet__field">
					<label>{{ t('opencase', 'Titel') }} *</label>
					<NcTextField v-model="form.title"
						:placeholder="t('opencase', 'Sagstitel')"
						:error="!!errors.title"
						:helper-text="errors.title" />
				</div>

				<div class="opencase-create-sepsheet__field">
					<label>{{ t('opencase', 'Ansvarlig') }}</label>
					<NcSelect v-model="selectedResponsibleUser"
						:options="userOptions"
						:loading="userSearchLoading"
						:filterable="false"
						:placeholder="t('opencase', 'Søg efter bruger')"
						:clearable="true"
						@search="onUserSearch" />
				</div>
			</template>

			<!-- New case + inbox case share Organisation/KLE/Facet/Sensitivity/Insight level -->
			<template v-if="selectedType && (selectedType.id === 'new case' || selectedType.id === 'inbox case')">
				<div class="opencase-create-sepsheet__field">
					<label>{{ t('opencase', 'Organisation') }}</label>
					<NcSelect v-model="selectedOrg"
						:options="orgOptions"
						:loading="orgSearchLoading"
						:filterable="false"
						:clearable="true"
						:placeholder="t('opencase', 'Søg efter organisation')"
						@search="onOrgSearch" />
				</div>

				<div class="opencase-create-sepsheet__row">
					<div class="opencase-create-sepsheet__field">
						<label>{{ t('opencase', 'KLE-nummer') }}</label>
						<NcSelect v-model="selectedKle"
							:options="kleOptions"
							:clearable="true"
							:placeholder="t('opencase', 'Vælg KLE-emneord')" />
					</div>

					<div class="opencase-create-sepsheet__field">
						<label>{{ t('opencase', 'Følsomhed') }}</label>
						<NcSelect v-model="selectedSensitivity"
							:options="sensitivityOptions"
							:clearable="true"
							:placeholder="t('opencase', 'Vælg følsomhed')" />
					</div>
				</div>

				<div class="opencase-create-sepsheet__field">
					<label>{{ t('opencase', 'Handlingsfacet') }}</label>
					<NcSelect v-model="selectedFacet"
						:options="facetOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg handlingsfacet')" />
				</div>

				<div class="opencase-create-sepsheet__field">
					<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
					<NcSelect v-model="selectedInsightLevel"
						:options="insightLevelOptions"
						:clearable="true"
						:placeholder="t('opencase', 'Vælg indsigtsgrad')" />
				</div>
			</template>

			<p v-if="errorMessage" class="opencase-create-sepsheet__error">
				{{ errorMessage }}
			</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!selectedType || saving"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ isEdit ? t('opencase', 'Gem') : t('opencase', 'Opret') }}
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

import api from '../services/api.js'

export default {
	name: 'CreateSeparationSheetDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon },

	props: {
		/** Existing sheet to edit; null creates a new one. */
		sheet: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'created', 'updated'],

	data() {
		const sheet = this.sheet
		const typeOptions = [
			{ id: 'existing case', label: t('opencase', 'Eksisterende sag') },
			{ id: 'new case', label: t('opencase', 'Ny sag') },
			{ id: 'inbox case', label: t('opencase', 'Indbakkesag') },
		]

		// Seed each search-backed select with a stub built from the stored id,
		// so the current value is visible before its label has been resolved.
		const caseOption = sheet?.case_number
			? { id: sheet.case_number, label: sheet.title ? `${sheet.case_number} – ${sheet.title}` : sheet.case_number }
			: null
		const userOption = sheet?.responsible_user_id
			? { id: sheet.responsible_user_id, label: sheet.responsible_user_id }
			: null
		const orgOption = sheet?.org_uuid
			? { id: sheet.org_uuid, label: sheet.org_uuid }
			: null

		return {
			typeOptions,
			selectedType: sheet ? typeOptions.find(o => o.id === sheet.type) || null : null,

			// Existing case
			selectedCase: caseOption,
			caseOptions: caseOption ? [caseOption] : [],
			caseSearchLoading: false,
			caseSearchTimeout: null,

			// New case
			form: { title: sheet?.type === 'new case' ? (sheet.title || '') : '' },
			selectedResponsibleUser: userOption,
			userOptions: userOption ? [userOption] : [],
			userSearchLoading: false,
			userSearchTimeout: null,

			// Shared (new case / inbox case)
			selectedOrg: orgOption,
			orgOptions: orgOption ? [orgOption] : [],
			orgSearchLoading: false,
			orgSearchTimeout: null,
			selectedKle: null,
			selectedSensitivity: null,
			selectedFacet: null,
			selectedInsightLevel: null,

			errors: {},
			errorMessage: null,
			saving: false,
		}
	},

	computed: {
		isEdit() {
			return !!this.sheet
		},
		dialogName() {
			return this.isEdit
				? t('opencase', 'Rediger separationsark')
				: t('opencase', 'Opret separationsark')
		},
		kleOptions() {
			return this.$store.state.classificationSubjects
				.filter(s => /^\d{2}\.\d{2}\.\d{2}$/.test(s.code))
				.map(s => ({ id: s.uuid, label: `${s.code} – ${s.title}` }))
		},
		sensitivityOptions() {
			return this.$store.state.sensitivities.map(s => ({ id: s.uuid, label: s.title }))
		},
		facetOptions() {
			return this.$store.state.classificationFacets
				.filter(f => /^[A-Za-z]\d{2}$/.test(f.code))
				.map(f => ({ id: f.uuid, label: `${f.code} – ${f.title}` }))
		},
		insightLevelOptions() {
			return this.$store.state.insightLevels.map(l => ({ id: l.id, label: l.name }))
		},
	},

	async mounted() {
		const codeLists = Promise.all([
			this.$store.dispatch('fetchClassificationSubjects'),
			this.$store.dispatch('fetchSensitivities'),
			this.$store.dispatch('fetchInsightLevels'),
			this.$store.dispatch('fetchClassificationFacets'),
		])

		try {
			const orgs = (await api.searchOrganisations('')).map(o => ({ id: o.uuid, label: o.label }))
			const current = this.selectedOrg
			const match = current ? orgs.find(o => o.id === current.id) : null
			if (match) {
				this.selectedOrg = match
				this.orgOptions = orgs
			} else {
				// Not in the first page — keep the stub so the selection isn't lost
				this.orgOptions = current ? [current, ...orgs] : orgs
			}
		} catch (e) {
			// silently ignore
		}

		try {
			await codeLists
		} catch (e) {
			// silently ignore
		}
		this.prefillFromCodeLists()
		this.resolveResponsibleUserLabel()
	},

	methods: {
		/** Resolve the code-list selects once the store has loaded their options. */
		prefillFromCodeLists() {
			if (!this.sheet) return
			const pick = (options, id) => (id === null || id === undefined || id === ''
				? null
				: options.find(o => o.id === id) || null)

			this.selectedKle = pick(this.kleOptions, this.sheet.class_subject_uuid)
			this.selectedSensitivity = pick(this.sensitivityOptions, this.sheet.sensitivity_uuid)
			this.selectedFacet = pick(this.facetOptions, this.sheet.classification_facet_uuid)
			this.selectedInsightLevel = pick(this.insightLevelOptions, this.sheet.insight_level_id)
		},

		/** The API stores only the uid — look up the display name for the label. */
		async resolveResponsibleUserLabel() {
			const uid = this.selectedResponsibleUser?.id
			if (!uid) return
			try {
				const match = (await api.searchUsers(uid)).find(u => u.id === uid)
				if (match) {
					this.selectedResponsibleUser = match
					this.userOptions = [match]
				}
			} catch (e) {
				// silently ignore — the uid stays as the label
			}
		},

		onCaseSearch(query) {
			clearTimeout(this.caseSearchTimeout)
			if (!query || query.trim().length < 1) return
			this.caseSearchLoading = true
			this.caseSearchTimeout = setTimeout(async () => {
				try {
					const { cases } = await api.getCases({ search: query, limit: 20 })
					this.caseOptions = cases.map(c => ({
						id: c.case_number,
						label: `${c.case_number} – ${c.title}`,
					}))
				} catch (e) {
					// silently ignore search errors
				} finally {
					this.caseSearchLoading = false
				}
			}, 300)
		},

		onOrgSearch(query) {
			clearTimeout(this.orgSearchTimeout)
			this.orgSearchLoading = true
			this.orgSearchTimeout = setTimeout(async () => {
				try {
					this.orgOptions = (await api.searchOrganisations(query)).map(o => ({ id: o.uuid, label: o.label }))
				} catch (e) {
					// silently ignore search errors
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

		validate() {
			this.errors = {}
			const type = this.selectedType?.id
			if (type === 'existing case' && !this.selectedCase) {
				this.errors.case_number = t('opencase', 'Påkrævet')
			}
			if (type === 'new case' && !this.form.title.trim()) {
				this.errors.title = t('opencase', 'Påkrævet')
			}
			return Object.keys(this.errors).length === 0
		},

		buildPayload() {
			const type = this.selectedType.id
			const payload = { type }

			if (type === 'existing case') {
				payload.case_number = this.selectedCase.id
			} else if (type === 'new case') {
				payload.title = this.form.title.trim()
				payload.responsible_user_id = this.selectedResponsibleUser?.id || ''
				payload.org_uuid = this.selectedOrg?.id || ''
				payload.class_subject_uuid = this.selectedKle?.id || ''
				payload.sensitivity_uuid = this.selectedSensitivity?.id || ''
				payload.classification_facet_uuid = this.selectedFacet?.id || ''
				payload.insight_level_id = this.selectedInsightLevel?.id ?? ''
			} else if (type === 'inbox case') {
				payload.org_uuid = this.selectedOrg?.id || ''
				payload.class_subject_uuid = this.selectedKle?.id || ''
				payload.sensitivity_uuid = this.selectedSensitivity?.id || ''
				payload.classification_facet_uuid = this.selectedFacet?.id || ''
				payload.insight_level_id = this.selectedInsightLevel?.id ?? ''
			}

			return payload
		},

		async save() {
			if (!this.selectedType || !this.validate()) return

			this.saving = true
			this.errorMessage = null
			try {
				if (this.isEdit) {
					this.$emit('updated', await api.updateSeparationSheet(this.sheet.id, this.buildPayload()))
				} else {
					this.$emit('created', await api.createSeparationSheet(this.buildPayload()))
				}
			} catch (e) {
				this.errorMessage = e?.response?.data?.ocs?.data?.error
					|| e?.response?.data?.error
					|| (this.isEdit
						? t('opencase', 'Kunne ikke gemme separationsark')
						: t('opencase', 'Kunne ikke oprette separationsark'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-create-sepsheet {
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.opencase-create-sepsheet__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
	min-width: 0;
}

/* NcSelect ships a 260px min-width, which pushes the fields past the dialog edge */
.opencase-create-sepsheet__field :deep(.v-select) {
	width: 100%;
	min-width: 0;
}

.opencase-create-sepsheet__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-create-sepsheet__row {
	display: flex;
	gap: 16px;
}

.opencase-create-sepsheet__error {
	margin: 0;
	color: var(--color-error);
	font-size: 0.85em;
}
</style>
