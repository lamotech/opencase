<template>
	<NcDialog :name="t('opencase', 'Rediger sag')"
		size="large"
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

			<div class="opencase-edit-case__field">
				<label>{{ t('opencase', 'Sagsresume') }}</label>
				<div class="opencase-edit-case__editor">
					<div class="opencase-edit-case__toolbar">
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
						<span class="opencase-edit-case__toolbar-sep" />
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
						<span class="opencase-edit-case__toolbar-sep" />
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
						<span class="opencase-edit-case__toolbar-sep" />
						<button type="button"
							:class="{ active: editor && editor.isActive('blockquote') }"
							:title="t('opencase', 'Citat')"
							@click="editor && editor.chain().focus().toggleBlockquote().run()">
							<FormatQuoteIcon :size="18" />
						</button>
					</div>
					<editor-content class="opencase-edit-case__editor-content" :editor="editor" />
				</div>
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

import api from '../services/api.js'

export default {
	name: 'EditCaseDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		EditorContent,
		FormatBoldIcon,
		FormatItalicIcon,
		FormatUnderlineIcon,
		FormatHeader1Icon,
		FormatHeader2Icon,
		FormatHeader3Icon,
		FormatListBulletedIcon,
		FormatListNumberedIcon,
		FormatQuoteIcon,
	},
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
			editor: null,
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
		this.editor = new Editor({
			extensions: [StarterKit, Underline],
			content: this.caseData.summary || '',
		})

		this.$store.dispatch('fetchClassificationSubjects')
		this.$store.dispatch('fetchSensitivities')
		this.$store.dispatch('fetchInsightLevels')
		this.$store.dispatch('fetchClassificationFacets')
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
					// silently ignore search errors
				} finally {
					this.userSearchLoading = false
				}
			}, 300)
		},

		/**
		 * Editor HTML, or '' when the summary is empty — TipTap serialises an
		 * empty document as '<p></p>', which the API would otherwise store.
		 */
		summaryHtml() {
			if (!this.editor || this.editor.isEmpty) return ''
			return this.editor.getHTML()
		},

		async save() {
			this.saving = true
			try {
				const updated = await this.$store.dispatch('updateCase', {
					id: this.caseData.id,
					payload: { ...this.form, summary: this.summaryHtml() },
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

/* Sagsresume rich-text editor */
.opencase-edit-case__editor {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
}

.opencase-edit-case__editor:focus-within {
	border-color: var(--color-primary-element);
}

.opencase-edit-case__toolbar {
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
	flex-wrap: wrap;
}

.opencase-edit-case__toolbar button {
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

.opencase-edit-case__toolbar button:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.opencase-edit-case__toolbar button.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.opencase-edit-case__toolbar-sep {
	width: 1px;
	height: 20px;
	background: var(--color-border);
	margin: 0 4px;
}

.opencase-edit-case__editor-content {
	padding: 12px;
	min-height: 180px;
	width: 100%;
	box-sizing: border-box;
}
</style>

<style>
/* Unscoped: TipTap renders the ProseMirror tree outside scoped-style reach */
.opencase-edit-case__editor-content > div {
	width: 100%;
}

.opencase-edit-case__editor-content .ProseMirror {
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

.opencase-edit-case__editor-content .ProseMirror p {
	margin: 0 0 8px 0;
}

.opencase-edit-case__editor-content .ProseMirror h1 {
	font-size: 1.5em;
	font-weight: 700;
	margin: 14px 0 6px 0;
}

.opencase-edit-case__editor-content .ProseMirror h2 {
	font-size: 1.2em;
	font-weight: 700;
	margin: 12px 0 6px 0;
}

.opencase-edit-case__editor-content .ProseMirror h3 {
	font-size: 1.05em;
	font-weight: 700;
	margin: 10px 0 4px 0;
}

.opencase-edit-case__editor-content .ProseMirror ul,
.opencase-edit-case__editor-content .ProseMirror ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.opencase-edit-case__editor-content .ProseMirror blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}
</style>
