<template>
	<div class="opencase-access-profiles">
		<NcSettingsSection :name="t('opencase', 'Adgangsprofiler')">
			<p>{{ t('opencase', 'Vælg hvordan adgangen til sager, dokumenter og filer styres.') }}</p>

			<NcCheckboxRadioSwitch v-model="type"
				value="fk"
				name="accessprofile_type"
				type="radio"
				:disabled="saving || !isMunicipality">
				{{ t('opencase', 'FK Adgang') }}
			</NcCheckboxRadioSwitch>
			<p class="opencase-access-profiles__hint">
				{{ isMunicipality
					? t('opencase', 'Adgang efter organisation, KLE-nummer og følsomhed, styret af brugernes rettigheder fra Fælleskommunalt Adgangsstyring.')
					: t('opencase', 'Kun for kommuner (kundetype under Version).') }}
			</p>

			<NcCheckboxRadioSwitch v-model="type"
				value="group"
				name="accessprofile_type"
				type="radio"
				:disabled="saving">
				{{ t('opencase', 'Grupper') }}
			</NcCheckboxRadioSwitch>
			<p class="opencase-access-profiles__hint">
				{{ t('opencase', 'Adgang efter Nextcloud-grupper: medlemmerne af en sags adgangsgruppe har skriveadgang til sagen.') }}
			</p>

			<p class="opencase-access-profiles__note">
				{{ t('opencase', 'Et skift gælder kun for nye sager. Eksisterende sager beholder deres adgangsprofil.') }}
			</p>
		</NcSettingsSection>

		<!-- Municipalities always classify by KLE -->
		<NcSettingsSection v-if="!isMunicipality" :name="t('opencase', 'Klassifikationsplan')">
			<p>{{ t('opencase', 'Vælg hvilken klassifikationsplan sager klassificeres efter.') }}</p>

			<NcCheckboxRadioSwitch v-model="classificationPlan"
				value="KLE"
				name="classification_plan"
				type="radio"
				:disabled="savingClassificationPlan || !isMunicipality">
				{{ t('opencase', 'KLE') }}
			</NcCheckboxRadioSwitch>
			<p class="opencase-access-profiles__hint">
				{{ t('opencase', 'Sager klassificeres med KLE-numre. Kun for kommuner.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="classificationPlan"
				value="Emneplan"
				name="classification_plan"
				type="radio"
				:disabled="savingClassificationPlan">
				{{ t('opencase', 'Emneplan') }}
			</NcCheckboxRadioSwitch>
			<p class="opencase-access-profiles__hint">
				{{ t('opencase', 'Sager klassificeres med en egen emneplan, som vedligeholdes under fanen Emneplan på siden Konfiguration.') }}
			</p>

			<div v-if="classificationPlan === 'Emneplan'" class="opencase-access-profiles__upload">
				<p class="opencase-access-profiles__hint">
					{{ t('opencase', 'CSV-format (semikolonsepareret, med overskriftsrække): uuid;code;title;description;active. uuid kan være tom – så findes emnet ud fra koden. code kan også være tom, hvis emnerne ikke har en kode – så bruges emnets navn (title). En valgfri 6. kolonne, deletion_deadline, angiver emnets slettefrist med kode (f.eks. P1825D) eller navn (f.eks. 5 år). Emner der ikke er med i filen, deaktiveres.') }}
				</p>
				<input ref="planFileInput"
					type="file"
					accept=".csv,text/csv"
					style="display:none"
					@change="onPlanFileSelected">
				<button class="primary" :disabled="uploadingPlan" @click="$refs.planFileInput.click()">
					{{ uploadingPlan ? t('opencase', 'Uploading...') : t('opencase', 'Upload emneplan') }}
				</button>
				<p v-if="planUploadResult" class="opencase-access-profiles__result">
					{{ t('opencase', 'Importeret: {fetched} rækker, {created} oprettet, {updated} opdateret, {deactivated} deaktiveret.', planUploadResult) }}
				</p>
				<p v-if="planUploadError" class="opencase-access-profiles__error">
					{{ planUploadError }}
				</p>
			</div>

		</NcSettingsSection>

		<!-- A municipality with the organisation integration gets it from Serviceplatformen -->
		<NcSettingsSection v-if="!isMunicipality || !organisationSyncEnabled" :name="t('opencase', 'Organisationsstruktur')">
			<p>{{ t('opencase', 'Uden integrationen til Serviceplatformens organisationsservice kan organisationsstrukturen indlæses fra en CSV-fil. Organisationer der ikke er med i filen, deaktiveres. Er integrationen slået til, overskriver den næste synkronisering det indlæste.') }}</p>
			<p class="opencase-access-profiles__hint opencase-access-profiles__hint--flush">
				{{ t('opencase', 'CSV-format (semikolonsepareret, med overskriftsrække): uuid;name;parent;group;email;phone;location. Kun name er påkrævet. parent er overordnet organisations uuid eller navn og skal også være i filen; tom betyder øverste niveau. group er den Nextcloud-gruppe (f.eks. fra Microsoft Entra ID), hvis medlemmer hører til organisationen – f.eks. »OpenCase Org - Byggeri«. Uden uuid genkendes en organisation på navnet, så en omdøbning opretter en ny organisation – brug uuid for at undgå det.') }}
			</p>
			<input ref="orgFileInput"
				type="file"
				accept=".csv,text/csv"
				style="display:none"
				@change="onOrgFileSelected">
			<button class="primary" :disabled="uploadingOrgs" @click="$refs.orgFileInput.click()">
				{{ uploadingOrgs ? t('opencase', 'Uploading...') : t('opencase', 'Upload organisationsstruktur') }}
			</button>
			<p v-if="orgUploadResult" class="opencase-access-profiles__result">
				{{ t('opencase', 'Importeret: {fetched} rækker, {created} oprettet, {updated} opdateret, {deactivated} deaktiveret.', orgUploadResult) }}
			</p>
			<p v-if="orgUploadError" class="opencase-access-profiles__error">
				{{ orgUploadError }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection v-if="type === 'group'" :name="t('opencase', 'Roller fra grupper')">
			<p>{{ t('opencase', 'Medlemmer af de valgte Nextcloud-grupper får OpenCase-rollen, f.eks. grupper fra Microsoft Entra ID. Roller fra FK Adgang og Rettigheder påvirkes ikke.') }}</p>

			<NcLoadingIcon v-if="roleGroupsLoading" :size="32" />
			<template v-else>
				<div v-for="role in roles" :key="role.id" class="opencase-access-profiles__role">
					<label>{{ role.label }}</label>
					<NcSelect v-model="roleGroups[role.id]"
						:options="groupOptions"
						:multiple="true"
						:taggable="true"
						:create-option="label => ({ id: label, label, exists: false })"
						:loading="groupSearchLoading"
						:filterable="false"
						:placeholder="t('opencase', 'Søg efter eller skriv navnet på en gruppe')"
						@search="onGroupSearch" />
					<p v-if="missingGroups(role.id).length > 0" class="opencase-access-profiles__hint opencase-access-profiles__hint--flush">
						{{ t('opencase', 'Findes ikke endnu: {groups}. Gruppen oprettes når det første medlem logger ind.', { groups: missingGroups(role.id).join(', ') }) }}
					</p>
				</div>
				<button class="primary" :disabled="savingRoleGroups" @click="saveRoleGroups">
					{{ t('opencase', 'Gem') }}
				</button>
			</template>
		</NcSettingsSection>

		<NcSettingsSection v-if="type === 'group'" :name="t('opencase', 'Adgangsgrupper')">
			<p>{{ t('opencase', 'Nextcloud-grupper der kan vælges som adgangsgruppe på en sag.') }}</p>

			<div class="opencase-access-profiles__prefix">
				<NcTextField v-model="prefix"
					:label="t('opencase', 'Præfiks for adgangsgrupper')"
					:disabled="savingPrefix" />
				<button class="primary" :disabled="savingPrefix || prefix === savedPrefix" @click="savePrefix">
					{{ t('opencase', 'Gem') }}
				</button>
			</div>
			<p class="opencase-access-profiles__hint opencase-access-profiles__hint--flush">
				{{ prefix
					? t('opencase', 'Grupper hvis navn starter med "{prefix}" bliver automatisk adgangsgrupper, f.eks. grupper fra Microsoft Entra ID. Præfikset vises ikke i OpenCase.', { prefix })
					: t('opencase', 'Uden præfiks bliver alle grupper adgangsgrupper, undtagen admin, org_-grupper og grupper der giver roller.') }}
			</p>

			<NcLoadingIcon v-if="loading" :size="32" />
			<table v-else class="opencase-access-profiles__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Gruppe') }}</th>
						<th>{{ t('opencase', 'Gruppe-id') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="g in groups" :key="g.id">
						<td>{{ g.group_name }}</td>
						<td>{{ g.group_id }}</td>
						<td>
							<button :disabled="removingId === g.id" @click="removeGroup(g)">
								{{ t('opencase', 'Fjern') }}
							</button>
						</td>
					</tr>
					<tr v-if="groups.length === 0">
						<td colspan="3">{{ t('opencase', 'Ingen adgangsgrupper endnu.') }}</td>
					</tr>
				</tbody>
			</table>

			<div class="opencase-access-profiles__add">
				<NcSelect v-model="groupToAdd"
					:options="groupOptions"
					:loading="groupSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter Nextcloud-gruppe')"
					@search="onGroupSearch" />
				<button class="primary" :disabled="!groupToAdd || adding" @click="addGroup">
					{{ t('opencase', 'Tilføj') }}
				</button>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const ocsData = (response) => response.data?.ocs?.data ?? response.data
const profilesUrl = generateOcsUrl('/apps/opencase/api/v1/admin/profiles')
const roleGroupsUrl = generateOcsUrl('/apps/opencase/api/v1/admin/role-groups')

/**
 * Display names for the known roles. The roles themselves come from the
 * server (oc_opencase_roles); a role without an entry here is shown by its name.
 */
const ROLE_LABELS = {
	User: t('opencase', 'Bruger'),
	Administrator: t('opencase', 'OpenCase Administrator'),
	'Template Designer': t('opencase', 'Skabelon administrator'),
	'Process Designer': t('opencase', 'Procesadministrator'),
}

/**
 * Admin settings for the access profile type (accessprofile_type: 'fk' = FK
 * Adgang, 'group' = Grupper), the classification plan (classification_plan:
 * 'KLE' or 'Emneplan'), with Grupper the Nextcloud groups that are
 * registered as access groups, and the Nextcloud groups whose members get an
 * OpenCase role.
 *
 * The customer type (chosen under Version) limits the choices: a municipality
 * always classifies by KLE (the plan is not shown) and gets the organisation
 * structure from Serviceplatformen when that integration is on; another
 * public authority always uses Grupper and an Emneplan.
 */
export default {
	name: 'AccessProfileSettings',

	components: { NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcSettingsSection, NcTextField },

	props: {
		initialType: {
			type: String,
			default: 'fk',
		},
		initialPrefix: {
			type: String,
			default: 'OpenCase - ',
		},
		initialClassificationPlan: {
			type: String,
			default: 'KLE',
		},
		/** customer_type: 'municipality' (Kommune) or 'other' (Anden offentlig myndighed) */
		customerType: {
			type: String,
			default: 'municipality',
		},
		/** Whether the Serviceplatformen organisation integration is on (organisation_enable) */
		organisationSyncEnabled: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			type: this.initialType || 'fk',
			savedType: this.initialType || 'fk',
			saving: false,
			classificationPlan: this.initialClassificationPlan || 'KLE',
			savedClassificationPlan: this.initialClassificationPlan || 'KLE',
			savingClassificationPlan: false,
			uploadingPlan: false,
			uploadingOrgs: false,
			orgUploadResult: null,
			orgUploadError: '',
			planUploadResult: null,
			planUploadError: '',
			prefix: this.initialPrefix,
			savedPrefix: this.initialPrefix,
			savingPrefix: false,
			groups: [],
			loading: false,
			groupToAdd: null,
			groupOptions: [],
			groupSearchLoading: false,
			groupSearchTimeout: null,
			adding: false,
			removingId: null,
			// [{ id: role name, label }], in the order the server lists the roles
			roles: [],
			// role name => [{ id: group id, label: group name, exists }]
			roleGroups: {},
			roleGroupsLoading: false,
			savingRoleGroups: false,
		}
	},

	computed: {
		isMunicipality() {
			return this.customerType === 'municipality'
		},
	},

	watch: {
		// Set by the server when the customer type changes: show, don't save
		initialType(value) {
			this.savedType = value
			this.type = value
			if (value === 'group') {
				this.loadGroups()
			}
		},

		initialClassificationPlan(value) {
			this.savedClassificationPlan = value
			this.classificationPlan = value
		},

		async classificationPlan(value) {
			if (value === this.savedClassificationPlan) return
			this.savingClassificationPlan = true
			try {
				await axios.post(generateUrl('/apps/opencase/settings/config'), { key: 'classification_plan', value })
				this.savedClassificationPlan = value
			} catch (e) {
				showError(e.response?.data?.error || t('opencase', 'Kunne ikke gemme klassifikationsplanen'))
				this.classificationPlan = this.savedClassificationPlan
			} finally {
				this.savingClassificationPlan = false
			}
		},

		async type(value) {
			if (value === this.savedType) return
			this.saving = true
			try {
				await axios.post(generateUrl('/apps/opencase/settings/config'), { key: 'accessprofile_type', value })
				this.savedType = value
				if (value === 'group') {
					this.loadGroups()
				}
			} catch (e) {
				showError(e.response?.data?.error || t('opencase', 'Kunne ikke gemme adgangsprofiltypen'))
				this.type = this.savedType
			} finally {
				this.saving = false
			}
		},
	},

	mounted() {
		if (this.type === 'group') {
			this.loadGroups()
		}
		this.loadRoleGroups()
		this.onGroupSearch('')
	},

	methods: {
		async loadGroups() {
			this.loading = true
			try {
				this.groups = ocsData(await axios.get(profilesUrl, { params: { type: 'group' } })).profiles
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente adgangsgrupper'))
			} finally {
				this.loading = false
			}
		},

		onGroupSearch(query) {
			clearTimeout(this.groupSearchTimeout)
			this.groupSearchLoading = true
			this.groupSearchTimeout = setTimeout(async () => {
				try {
					const { groups } = ocsData(await axios.get(generateOcsUrl('/cloud/groups/details'), {
						params: { search: query || '', limit: 20 },
					}))
					this.groupOptions = groups.map(g => ({ id: g.id, label: g.displayname || g.id }))
				} catch (e) {
					// silently ignore search errors
				} finally {
					this.groupSearchLoading = false
				}
			}, 300)
		},

		async addGroup() {
			this.adding = true
			try {
				await axios.post(profilesUrl, { group_id: this.groupToAdd.id })
				showSuccess(t('opencase', 'Adgangsgruppe tilføjet'))
				this.groupToAdd = null
				await this.loadGroups()
			} catch (e) {
				showError(ocsData(e.response ?? {})?.error || t('opencase', 'Kunne ikke tilføje adgangsgruppe'))
			} finally {
				this.adding = false
			}
		},

		setRoleGroups(roleGroups) {
			this.roles = Object.keys(roleGroups).map(name => ({ id: name, label: ROLE_LABELS[name] ?? name }))
			this.roleGroups = Object.fromEntries(Object.entries(roleGroups).map(([name, groups]) => [
				name,
				groups.map(g => ({ id: g.group_id, label: g.group_name, exists: g.exists })),
			]))
		},

		missingGroups(roleId) {
			return this.roleGroups[roleId].filter(g => g.exists === false).map(g => g.label)
		},

		onPlanFileSelected(event) {
			const file = event.target.files[0]
			event.target.value = ''
			if (file) {
				this.uploadPlan(file)
			}
		},

		async uploadPlan(file) {
			this.uploadingPlan = true
			this.planUploadResult = null
			this.planUploadError = ''
			try {
				const formData = new FormData()
				formData.append('file', file)
				const response = await axios.post(
					generateUrl('/apps/opencase/settings/classification/plan/upload'),
					formData,
					{ headers: { 'Content-Type': 'multipart/form-data' } },
				)
				this.planUploadResult = response.data
			} catch (e) {
				this.planUploadError = e.response?.data?.error || t('opencase', 'Upload failed')
			} finally {
				this.uploadingPlan = false
			}
		},

		onOrgFileSelected(event) {
			const file = event.target.files[0]
			event.target.value = ''
			if (file) {
				this.uploadOrgs(file)
			}
		},

		async uploadOrgs(file) {
			this.uploadingOrgs = true
			this.orgUploadResult = null
			this.orgUploadError = ''
			try {
				const formData = new FormData()
				formData.append('file', file)
				const response = await axios.post(
					generateUrl('/apps/opencase/settings/organisations/upload'),
					formData,
					{ headers: { 'Content-Type': 'multipart/form-data' } },
				)
				this.orgUploadResult = response.data
			} catch (e) {
				this.orgUploadError = e.response?.data?.error || t('opencase', 'Upload failed')
			} finally {
				this.uploadingOrgs = false
			}
		},

		async savePrefix() {
			this.savingPrefix = true
			try {
				await axios.post(generateUrl('/apps/opencase/settings/config'), { key: 'access_group_prefix', value: this.prefix })
				this.savedPrefix = this.prefix
				showSuccess(t('opencase', 'Præfiks gemt'))
				await this.loadGroups()
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme præfikset'))
			} finally {
				this.savingPrefix = false
			}
		},

		async loadRoleGroups() {
			this.roleGroupsLoading = true
			try {
				this.setRoleGroups(ocsData(await axios.get(roleGroupsUrl)).role_groups)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente roller fra grupper'))
			} finally {
				this.roleGroupsLoading = false
			}
		},

		async saveRoleGroups() {
			this.savingRoleGroups = true
			try {
				const roleGroups = Object.fromEntries(this.roles.map(role => [role.id, this.roleGroups[role.id].map(g => g.id)]))
				this.setRoleGroups(ocsData(await axios.put(roleGroupsUrl, { role_groups: roleGroups })).role_groups)
				showSuccess(t('opencase', 'Roller fra grupper gemt'))
			} catch (e) {
				showError(ocsData(e.response ?? {})?.error || t('opencase', 'Kunne ikke gemme roller fra grupper'))
			} finally {
				this.savingRoleGroups = false
			}
		},

		async removeGroup(group) {
			this.removingId = group.id
			try {
				await axios.delete(`${profilesUrl}/${group.id}`)
				await this.loadGroups()
			} catch (e) {
				showError(e.response?.status === 409
					? t('opencase', 'Gruppen kan ikke fjernes, fordi den bruges af sager')
					: t('opencase', 'Kunne ikke fjerne adgangsgruppe'))
			} finally {
				this.removingId = null
			}
		},
	},
}
</script>

<style scoped>
.opencase-access-profiles__hint {
	margin: 0 0 12px 36px;
	color: var(--color-text-maxcontrast);
}

.opencase-access-profiles__hint--flush {
	margin-inline-start: 0;
}

.opencase-access-profiles__prefix {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	max-width: 500px;
	margin-bottom: 4px;
}

.opencase-access-profiles__prefix > :first-child {
	flex: 1;
}

.opencase-access-profiles__note {
	margin-top: 8px;
	font-style: italic;
}

.opencase-access-profiles__hint--flush {
	margin-left: 0;
}

.opencase-access-profiles__upload {
	margin: 0 0 12px 36px;
}

.opencase-access-profiles__upload .opencase-access-profiles__hint {
	margin-left: 0;
}

.opencase-access-profiles__result {
	margin-top: 8px;
}

.opencase-access-profiles__error {
	margin-top: 8px;
	color: var(--color-error-text);
}

.opencase-access-profiles__table {
	margin-bottom: 16px;
	border-collapse: collapse;
}

.opencase-access-profiles__table th,
.opencase-access-profiles__table td {
	padding: 6px 16px 6px 0;
	text-align: start;
}

.opencase-access-profiles__add {
	display: flex;
	gap: 8px;
	align-items: center;
	max-width: 500px;
}

.opencase-access-profiles__add > :first-child {
	flex: 1;
}

.opencase-access-profiles__role {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 500px;
	margin-bottom: 12px;
}

.opencase-access-profiles__role label {
	font-weight: 600;
}
</style>
