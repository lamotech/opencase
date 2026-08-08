<template>
<div>
	<NcDialog :name="dialogTitle"
		size="large"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">

		<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

	<div v-show="activeTab === 'info'">
		<!-- Employee context card -->
		<div v-if="isEmployeeParticipant" class="ncd-citizen-card">
			<div class="ncd-citizen-card__title">
				<AccountIcon :size="22" />
				<span>{{ selectedEmployee.personname || selectedEmployee.username }}</span>
			</div>
			<div class="ncd-citizen-card__fields">
				<div v-if="selectedEmployee.username" class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'Brugernavn') }}</span>
					<span class="ncd-citizen-card__mono">{{ selectedEmployee.username }}</span>
				</div>
				<div v-if="selectedEmployee.email" class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'E-mail') }}</span>
					<span>{{ selectedEmployee.email }}</span>
				</div>
				<div v-if="selectedEmployee.phone" class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'Telefon') }}</span>
					<span>{{ selectedEmployee.phone }}</span>
				</div>
				<div v-if="selectedEmployee.location" class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'Sted') }}</span>
					<span>{{ selectedEmployee.location }}</span>
				</div>
			</div>
		</div>

		<!-- Citizen / company context card -->
		<div v-else-if="participant" class="ncd-citizen-card">
			<div class="ncd-citizen-card__title">
				<OfficeBuildingIcon v-if="isCompanyParticipant" :size="22" />
				<AccountIcon v-else :size="22" />
				<span>{{ participant.name }}</span>
			</div>
			<div class="ncd-citizen-card__fields">
				<div class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ isCompanyParticipant ? t('opencase', 'CVR-nummer') : t('opencase', 'CPR-nummer') }}</span>
					<CprDisplay :value="participant.cpr_cvr" />
				</div>
				<div v-if="participant.pnumber" class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'P-nummer') }}</span>
					<span class="ncd-citizen-card__mono">{{ participant.pnumber }}</span>
				</div>
				<div class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'Adresse') }}</span>
					<template v-if="participant.has_address_protection">
						<div v-if="!revealCardAddress"
							class="ncd-protected-badge"
							@click="revealCardAddress = true">
							{{ t('opencase', 'Beskyttet adresse') }}
						</div>
						<div v-else
							class="ncd-protected-badge"
							@click="revealCardAddress = false">
							{{ formatAddress(participant) }}
						</div>
					</template>
					<span v-else>{{ formatAddress(participant) }}</span>
				</div>
				<div v-if="participant.phone" class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'Telefon') }}</span>
					<span>{{ participant.phone }}</span>
				</div>
				<div v-if="participant.email" class="ncd-citizen-card__field">
					<span class="ncd-citizen-card__label">{{ t('opencase', 'E-mail') }}</span>
					<span>{{ participant.email }}</span>
				</div>
			</div>
		</div>

		<!-- Required participant picker (case type dictates a primary citizen/company/employee) -->
		<div v-else-if="requiresCitizen || requiresCompany || requiresEmployee" class="ncd-field">
			<NcButton @click="requiresCitizen ? (showSelectCitizenDialog = true) : requiresCompany ? (showSelectCompanyDialog = true) : (showSelectEmployeeDialog = true)">
				<template #icon>
					<AccountIcon v-if="requiresCitizen || requiresEmployee" :size="20" />
					<OfficeBuildingIcon v-else :size="20" />
				</template>
				{{ requiresCitizen ? t('opencase', 'Vælg borger') : requiresCompany ? t('opencase', 'Vælg virksomhed') : t('opencase', 'Vælg medarbejder') }}
			</NcButton>
			<span v-if="errors.participant" class="ncd-field__error">{{ errors.participant }}</span>
		</div>

		<div class="ncd-content">
			<div class="ncd-field">
				<label>{{ t('opencase', 'Titel') }} *</label>
				<NcTextField v-model="form.title"
					:placeholder="t('opencase', 'Sagstitel')"
					:error="!!errors.title"
					:helper-text="errors.title" />
			</div>

			<div class="ncd-field">
				<label>{{ t('opencase', 'Organisation') }} *</label>
				<NcSelect v-model="selectedOrg"
					:options="orgOptions"
					:loading="orgSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter organisation')"
					@search="onOrgSearch"
					@update:model-value="v => form.organisation = v?.id || ''" />
				<span v-if="errors.organisation" class="ncd-field__error">{{ errors.organisation }}</span>
			</div>

			<div class="ncd-row">
				<div class="ncd-field">
					<label>{{ t('opencase', 'KLE-nummer') }} *</label>
					<NcSelect v-model="selectedClassification"
						:options="classificationOptions"
						:placeholder="t('opencase', 'Vælg KLE-emneord')"
						@update:model-value="v => form.classification_code = v?.id || ''" />
					<span v-if="errors.classification_code" class="ncd-field__error">{{ errors.classification_code }}</span>
				</div>

				<div class="ncd-field">
					<label>{{ t('opencase', 'Følsomhed') }} *</label>
					<NcSelect v-model="selectedSensitivity"
						:options="sensitivityOptions"
						:placeholder="t('opencase', 'Vælg følsomhed')"
						@update:model-value="v => form.sensitivity = v?.id || ''" />
					<span v-if="errors.sensitivity" class="ncd-field__error">{{ errors.sensitivity }}</span>
				</div>
			</div>

			<div class="ncd-field">
				<label>{{ t('opencase', 'Handlingsfacet') }} *</label>
				<NcSelect v-model="selectedClassificationFacet"
					:options="classificationFacetOptions"
					:placeholder="t('opencase', 'Vælg handlingsfacet')"
					@update:model-value="v => form.classification_facet_uuid = v?.id || ''" />
				<span v-if="errors.classification_facet_uuid" class="ncd-field__error">{{ errors.classification_facet_uuid }}</span>
			</div>

			<div class="ncd-field">
				<label>{{ t('opencase', 'Indsigtsgrad') }}</label>
				<NcSelect v-model="selectedInsightLevel"
					:options="insightLevelOptions"
					:placeholder="t('opencase', 'Vælg indsigtsgrad')"
					:clearable="true"
					@update:model-value="v => form.insight_level_id = v?.id || null" />
			</div>

			<div class="ncd-field">
				<label>{{ t('opencase', 'Ansvarlig') }} *</label>
				<NcSelect v-model="selectedResponsibleUser"
					:options="userOptions"
					:loading="userSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter bruger')"
					@search="onUserSearch"
					@update:model-value="v => form.responsible_user_id = v?.id || ''" />
				<span v-if="errors.responsible_user_id" class="ncd-field__error">{{ errors.responsible_user_id }}</span>
			</div>

			<div class="ncd-field">
				<label>{{ t('opencase', 'Sagsresume') }}</label>
				<div class="ncd-editor">
					<div class="ncd-editor__toolbar">
						<button type="button"
							:class="{ active: editor && editor.isActive('bold') }"
							:title="t('opencase', 'Fed')"
							@click="editor && editor.chain().focus().toggleBold().run()">
							<FormatBoldIcon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('italic') }"
							:title="t('opencase', 'Kursiv')"
							@click="editor && editor.chain().focus().toggleItalic().run()">
							<FormatItalicIcon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('underline') }"
							:title="t('opencase', 'Understreget')"
							@click="editor && editor.chain().focus().toggleUnderline().run()">
							<FormatUnderlineIcon :size="18" />
						</button>
						<span class="ncd-editor__toolbar-sep" />
						<button type="button"
							:class="{ active: editor && editor.isActive('heading', { level: 1 }) }"
							:title="t('opencase', 'Overskrift 1')"
							@click="editor && editor.chain().focus().toggleHeading({ level: 1 }).run()">
							<FormatHeader1Icon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('heading', { level: 2 }) }"
							:title="t('opencase', 'Overskrift 2')"
							@click="editor && editor.chain().focus().toggleHeading({ level: 2 }).run()">
							<FormatHeader2Icon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('heading', { level: 3 }) }"
							:title="t('opencase', 'Overskrift 3')"
							@click="editor && editor.chain().focus().toggleHeading({ level: 3 }).run()">
							<FormatHeader3Icon :size="18" />
						</button>
						<span class="ncd-editor__toolbar-sep" />
						<button type="button"
							:class="{ active: editor && editor.isActive('bulletList') }"
							:title="t('opencase', 'Punktliste')"
							@click="editor && editor.chain().focus().toggleBulletList().run()">
							<FormatListBulletedIcon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('orderedList') }"
							:title="t('opencase', 'Nummereret liste')"
							@click="editor && editor.chain().focus().toggleOrderedList().run()">
							<FormatListNumberedIcon :size="18" />
						</button>
						<span class="ncd-editor__toolbar-sep" />
						<button type="button"
							:class="{ active: editor && editor.isActive('blockquote') }"
							:title="t('opencase', 'Citat')"
							@click="editor && editor.chain().focus().toggleBlockquote().run()">
							<FormatQuoteIcon :size="18" />
						</button>
					</div>
					<editor-content class="ncd-editor__content" :editor="editor" />
				</div>
			</div>
		</div>
	</div>

	<div v-show="activeTab === 'participants'">
		<CaseParticipantList ref="participantList" :case-id="null" :can-write="true" @count-changed="participantsCount = $event" />
	</div>

	<div v-show="activeTab === 'caseworkers'">
		<CaseCaseworkerList ref="caseworkerList" :case-id="null" :can-write="true" @count-changed="caseworkersCount = $event" />
	</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="16" />
				</template>
				{{ saving ? t('opencase', 'Opretter…') : t('opencase', 'Opret sag') }}
			</NcButton>
		</template>
	</NcDialog>

	<SelectCitizenDialog v-if="showSelectCitizenDialog && citizenSearchEnabled"
		@selected="onCitizenSelected"
		@close="showSelectCitizenDialog = false" />
	<ManualCitizenDialog v-else-if="showSelectCitizenDialog"
		@selected="onCitizenSelected"
		@close="showSelectCitizenDialog = false" />

	<SelectCompanyDialog v-if="showSelectCompanyDialog && companySearchEnabled"
		@selected="onCompanySelected"
		@close="showSelectCompanyDialog = false" />
	<ManualCompanyDialog v-else-if="showSelectCompanyDialog"
		@selected="onCompanySelected"
		@close="showSelectCompanyDialog = false" />

	<SelectEmployeeDialog v-if="showSelectEmployeeDialog"
		@selected="onEmployeeSelected"
		@close="showSelectEmployeeDialog = false" />
</div>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import AccountMultipleOutlineIcon from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountCogOutlineIcon from 'vue-material-design-icons/AccountCogOutline.vue'
import FormatBoldIcon from 'vue-material-design-icons/FormatBold.vue'
import FormatItalicIcon from 'vue-material-design-icons/FormatItalic.vue'
import FormatUnderlineIcon from 'vue-material-design-icons/FormatUnderline.vue'
import FormatHeader1Icon from 'vue-material-design-icons/FormatHeader1.vue'
import FormatHeader2Icon from 'vue-material-design-icons/FormatHeader2.vue'
import FormatHeader3Icon from 'vue-material-design-icons/FormatHeader3.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumberedIcon from 'vue-material-design-icons/FormatListNumbered.vue'
import FormatQuoteIcon from 'vue-material-design-icons/FormatQuoteOpen.vue'

import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'

import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'
import CprDisplay from './CprDisplay.vue'
import SelectCitizenDialog from './SelectCitizenDialog.vue'
import ManualCitizenDialog from './ManualCitizenDialog.vue'
import SelectCompanyDialog from './SelectCompanyDialog.vue'
import ManualCompanyDialog from './ManualCompanyDialog.vue'
import SelectEmployeeDialog from './SelectEmployeeDialog.vue'
import OverflowTabs from './OverflowTabs.vue'
import CaseParticipantList from './CaseParticipantList.vue'
import CaseCaseworkerList from './CaseCaseworkerList.vue'

export default {
	name: 'NewCaseDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon, AccountIcon, OfficeBuildingIcon, CprDisplay,
		SelectCitizenDialog, ManualCitizenDialog, SelectCompanyDialog, ManualCompanyDialog, SelectEmployeeDialog,
		OverflowTabs, CaseParticipantList, CaseCaseworkerList,
		EditorContent,
		FormatBoldIcon, FormatItalicIcon, FormatUnderlineIcon,
		FormatHeader1Icon, FormatHeader2Icon, FormatHeader3Icon,
		FormatListBulletedIcon, FormatListNumberedIcon, FormatQuoteIcon,
	},

	props: {
		citizen: { type: Object, default: null },
		company: { type: Object, default: null },
		employee: { type: Object, default: null },
		caseType: { type: Object, default: null },
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
				casetype_id: this.caseType?.id || null,
				summary: '',
			},
			editor: null,
			selectedOrg: null,
			orgOptions: [],
			orgSearchLoading: false,
			orgSearchTimeout: null,
			selectedClassification: null,
			selectedSensitivity: null,
			selectedInsightLevel: null,
			selectedClassificationFacet: null,
			selectedResponsibleUser: responsibleUserOption,
			userOptions: responsibleUserOption ? [responsibleUserOption] : [],
			userSearchLoading: false,
			userSearchTimeout: null,
			errors: {},
			saving: false,
			revealCardAddress: false,
			pickedParticipant: null,
			pickedParticipantIsCompany: false,
			pickedEmployee: null,
			showSelectCitizenDialog: false,
			showSelectCompanyDialog: false,
			showSelectEmployeeDialog: false,
			activeTab: 'info',
			participantsCount: null,
			caseworkersCount: null,
		}
	},

	computed: {
		tabs() {
			return [
				{ id: 'info', label: t('opencase', 'Info'), icon: InformationOutlineIcon },
				{ id: 'participants', label: t('opencase', 'Parter'), count: this.participantsCount, icon: AccountMultipleOutlineIcon },
				{ id: 'caseworkers', label: t('opencase', 'Andre sagsbehandlere'), count: this.caseworkersCount, icon: AccountCogOutlineIcon },
			]
		},
		citizenSearchEnabled() {
			return this.$store.state.citizenSearchEnabled
		},
		companySearchEnabled() {
			return this.$store.state.companySearchEnabled
		},
		participant() {
			return this.citizen || this.company || this.pickedParticipant
		},
		selectedEmployee() {
			return this.employee || this.pickedEmployee
		},
		isEmployeeParticipant() {
			return !!this.selectedEmployee
		},
		isCompanyParticipant() {
			if (this.company) return true
			if (this.citizen) return false
			return this.pickedParticipantIsCompany
		},
		requiresCitizen() {
			return this.caseType?.primary_participant === 'Citizen' && !this.participant
		},
		requiresCompany() {
			return this.caseType?.primary_participant === 'Company' && !this.participant
		},
		requiresEmployee() {
			return this.caseType?.primary_participant === 'Employee' && !this.selectedEmployee
		},
		dialogTitle() {
			return this.caseType
				? t('opencase', 'Opret ny {name}', { name: this.caseType.name })
				: t('opencase', 'Opret ny sag')
		},
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
		this.editor = new Editor({
			extensions: [StarterKit, Underline],
			content: '',
		})

		this.$store.dispatch('fetchClassificationSubjects')
		this.$store.dispatch('fetchSensitivities')
		this.$store.dispatch('fetchInsightLevels')
		this.$store.dispatch('fetchClassificationFacets')
		try {
			this.orgOptions = await api.searchOrganisations('')
		} catch (e) {
			// silently ignore
		}
		try {
			const orgName = await api.getMyPrimaryOrg()
			if (orgName) {
				const option = { id: orgName, label: orgName }
				if (!this.orgOptions.find(o => o.id === orgName)) {
					this.orgOptions = [option, ...this.orgOptions]
				}
				this.selectedOrg = option
				this.form.organisation = orgName
			}
		} catch (e) {
			// silently ignore
		}
	},

	beforeUnmount() {
		this.editor?.destroy()
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
					// silently ignore
				} finally {
					this.userSearchLoading = false
				}
			}, 300)
		},

		onCitizenSelected(citizen) {
			this.pickedParticipant = citizen
			this.pickedParticipantIsCompany = false
			if (this.errors.participant) delete this.errors.participant
		},

		onCompanySelected(company) {
			this.pickedParticipant = company
			this.pickedParticipantIsCompany = true
			if (this.errors.participant) delete this.errors.participant
		},

		onEmployeeSelected(employee) {
			this.pickedEmployee = employee
			if (this.errors.participant) delete this.errors.participant
		},

		/**
		 * Editor HTML, or '' when the user left the summary untouched —
		 * TipTap serialises an empty document as '<p></p>'.
		 */
		summaryHtml() {
			if (!this.editor || this.editor.isEmpty) return ''
			return this.editor.getHTML()
		},

		validate() {
			this.errors = {}
			if (!this.form.title.trim()) this.errors.title = t('opencase', 'Påkrævet')
			if (!this.form.organisation) this.errors.organisation = t('opencase', 'Påkrævet')
			if (!this.form.classification_code) this.errors.classification_code = t('opencase', 'Påkrævet')
			if (!this.form.sensitivity) this.errors.sensitivity = t('opencase', 'Påkrævet')
			if (!this.form.classification_facet_uuid) this.errors.classification_facet_uuid = t('opencase', 'Påkrævet')
			if (!this.form.responsible_user_id) this.errors.responsible_user_id = t('opencase', 'Påkrævet')
			if (this.requiresCitizen) this.errors.participant = t('opencase', 'Vælg en borger')
			if (this.requiresCompany) this.errors.participant = t('opencase', 'Vælg en virksomhed')
			if (this.requiresEmployee) this.errors.participant = t('opencase', 'Vælg en medarbejder')
			return Object.keys(this.errors).length === 0
		},

		async save() {
			if (!this.validate()) {
				this.activeTab = 'info'
				return
			}

			this.saving = true
			try {
				const created = await this.$store.dispatch('createCase', {
					...this.form,
					summary: this.summaryHtml(),
				})

				if (this.selectedEmployee) {
					try {
						await api.createCaseParticipant(created.id, {
							participantrole_id: 0,
							user_id:             this.selectedEmployee.username,
							name:                this.selectedEmployee.personname || this.selectedEmployee.username,
							phone:               this.selectedEmployee.phone || null,
							email:               this.selectedEmployee.email || null,
						})
					} catch (e) {
						showError(t('opencase', 'Sag oprettet, men medarbejderen kunne ikke tilføjes'))
					}
				} else if (this.participant) {
					try {
						await api.createCaseParticipant(created.id, {
							participantrole_id:     0,
							cpr_cvr:                this.participant.cpr_cvr,
							name:                   this.participant.name,
							streetname:             this.participant.streetname,
							housenumber:            this.participant.housenumber,
							floor:                  this.participant.floor,
							door:                   this.participant.door,
							zipcode:                this.participant.zipcode,
							zipdistrict:            this.participant.zipdistrict,
							phone:                  this.participant.phone,
							email:                  this.participant.email,
							has_address_protection: this.participant.has_address_protection ? 1 : 0,
							pnumber:                this.participant.pnumber || null,
						})
					} catch (e) {
						showError(t('opencase', 'Sag oprettet, men parten kunne ikke tilføjes'))
					}
				}

				const pendingParticipants = this.$refs.participantList?.participants || []
				for (const p of pendingParticipants) {
					const { id, role_name, ...payload } = p
					try {
						await api.createCaseParticipant(created.id, payload)
					} catch (e) {
						showError(t('opencase', 'Sag oprettet, men en part kunne ikke tilføjes'))
					}
				}

				const pendingCaseworkers = this.$refs.caseworkerList?.caseworkers || []
				for (const cw of pendingCaseworkers) {
					try {
						await api.addCaseworker(created.id, cw.user_id)
					} catch (e) {
						showError(t('opencase', 'Sag oprettet, men en sagsbehandler kunne ikke tilføjes'))
					}
				}

				showSuccess(t('opencase', 'Sag oprettet'))
				this.$emit('close')
				this.$router.push({ name: 'case-detail', params: { id: created.id } })
			} catch (e) {
				const msg = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke oprette sag')
				showError(msg)
			} finally {
				this.saving = false
			}
		},

		formatAddress(citizen) {
			const street = [citizen.streetname, citizen.housenumber, citizen.floor, citizen.door]
				.filter(Boolean).join(' ')
			const city = [citizen.zipcode, citizen.zipdistrict].filter(Boolean).join(' ')
			return [street, city].filter(Boolean).join(', ') || '–'
		},
	},
}
</script>

<style scoped>
/* Citizen context card */
.ncd-citizen-card {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px 20px;
	margin-bottom: 20px;
}

.ncd-citizen-card__title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1.1em;
	font-weight: 700;
	margin-bottom: 12px;
}

.ncd-citizen-card__fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 10px 24px;
}

.ncd-citizen-card__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ncd-citizen-card__label {
	font-size: 0.78em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.ncd-citizen-card__mono {
	font-family: var(--font-monospace, monospace);
}

.ncd-content {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.ncd-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ncd-field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.ncd-field__error {
	color: var(--color-error-text);
	font-size: 0.85em;
}

.ncd-row {
	display: flex;
	gap: 16px;
}

.ncd-row .ncd-field {
	flex: 1;
}

.ncd-protected-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	background: #fce4ec;
	color: #c62828;
	border: none;
	cursor: pointer;
}

.ncd-protected-badge:hover {
	opacity: 0.85;
}

/* Sagsresume rich-text editor */
.ncd-editor {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
}

.ncd-editor:focus-within {
	border-color: var(--color-primary-element);
}

.ncd-editor__toolbar {
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
	flex-wrap: wrap;
}

.ncd-editor__toolbar button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 30px;
	height: 30px;
	background: none;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	color: var(--color-text-lighter);
	transition: background 0.15s, color 0.15s;
}

.ncd-editor__toolbar button:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.ncd-editor__toolbar button.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.ncd-editor__toolbar-sep {
	width: 1px;
	height: 20px;
	background: var(--color-border);
	margin: 0 4px;
}

.ncd-editor__content {
	padding: 12px;
	min-height: 180px;
	width: 100%;
	box-sizing: border-box;
}
</style>

<style>
/* Unscoped: TipTap renders the ProseMirror tree outside scoped-style reach */
.ncd-editor__content > div {
	width: 100%;
}

.ncd-editor__content .ProseMirror {
	outline: none;
	min-height: 160px;
	width: 100%;
	box-sizing: border-box;
	overflow-wrap: break-word;
	word-break: break-word;
	font-size: 0.95em;
	line-height: 1.6;
	color: var(--color-main-text);
}

.ncd-editor__content .ProseMirror p {
	margin: 0 0 8px 0;
}

.ncd-editor__content .ProseMirror h1 {
	font-size: 1.5em;
	font-weight: 700;
	margin: 14px 0 6px 0;
}

.ncd-editor__content .ProseMirror h2 {
	font-size: 1.2em;
	font-weight: 700;
	margin: 12px 0 6px 0;
}

.ncd-editor__content .ProseMirror h3 {
	font-size: 1.05em;
	font-weight: 700;
	margin: 10px 0 4px 0;
}

.ncd-editor__content .ProseMirror ul,
.ncd-editor__content .ProseMirror ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.ncd-editor__content .ProseMirror blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}
</style>
