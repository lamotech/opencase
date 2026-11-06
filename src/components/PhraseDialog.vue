<template>
	<NcDialog :name="phrase ? t('opencase', 'Rediger frase') : t('opencase', 'Opret frase')"
		size="large"
		@closing="$emit('close')">
		<div class="opencase-phrase">
			<div class="opencase-phrase__field">
				<label>{{ t('opencase', 'Titel') }} *</label>
				<NcTextField v-model="form.title" />
			</div>

			<div class="opencase-phrase__field">
				<label>{{ t('opencase', 'Beskrivelse') }}</label>
				<textarea v-model="form.description"
					rows="2"
					class="opencase-phrase__textarea"
					:placeholder="t('opencase', 'Kort beskrivelse af, hvornår frasen bruges')" />
			</div>

			<div class="opencase-phrase__field">
				<label>{{ t('opencase', 'Tekst') }}</label>
				<RichTextEditor ref="textEditor" :content="phrase?.content || ''" class="opencase-phrase__text" />
				<span class="opencase-phrase__hint">{{ textHint }}</span>
			</div>

			<div class="opencase-phrase__row">
				<div class="opencase-phrase__field">
					<label>{{ t('opencase', 'Kategori') }}</label>
					<NcSelect v-model="categoryOption"
						:options="categoryOptions"
						:placeholder="t('opencase', 'Ingen kategori')" />
				</div>
				<div class="opencase-phrase__field">
					<label>{{ t('opencase', 'Status') }}</label>
					<NcSelect v-model="statusOption"
						:options="statusOptions"
						:clearable="false" />
				</div>
			</div>

			<div v-if="!phrase" class="opencase-phrase__field">
				<label>{{ t('opencase', 'Frasefil (valgfri)') }}</label>
				<div class="opencase-phrase__file-row">
					<input ref="fileInput"
						type="file"
						:accept="acceptedFileTypes"
						class="opencase-phrase__file-input"
						@change="onFileSelected">
					<NcButton @click="$refs.fileInput.click()">
						<template #icon>
							<UploadIcon :size="16" />
						</template>
						{{ selectedFile ? selectedFile.name : t('opencase', 'Vælg fil') }}
					</NcButton>
					<span class="opencase-phrase__hint">
						{{ t('opencase', 'Word (.docx) eller ODF (.odt). Du kan også oprette et tomt dokument og redigere det i Nextcloud Office bagefter.') }}
					</span>
				</div>
			</div>

			<div v-if="phrase" class="opencase-phrase__field">
				<label>{{ t('opencase', 'Bruges i skabeloner') }}</label>
				<NcLoadingIcon v-if="usagesLoading" :size="20" />
				<span v-else-if="usages.length === 0" class="opencase-phrase__hint">
					{{ t('opencase', 'Frasen er ikke tilknyttet nogen skabeloner.') }}
				</span>
				<ul v-else class="opencase-phrase__usages">
					<li v-for="usage in usages" :key="usage.id">
						{{ usage.name }}
						<span v-if="usage.is_required" class="opencase-phrase__muted">({{ t('opencase', 'obligatorisk') }})</span>
						<span v-else-if="usage.is_default" class="opencase-phrase__muted">({{ t('opencase', 'forudvalgt') }})</span>
						<span v-if="!usageMatches(usage)" class="opencase-phrase__mismatch">
							{{ t('opencase', 'Afgrænsningen passer ikke til skabelonens organisationer') }}
						</span>
					</li>
				</ul>
			</div>

			<dl v-if="phrase" class="opencase-phrase__info">
				<dt>{{ t('opencase', 'Fil') }}</dt>
				<dd>
					<template v-if="phrase.has_file">
						{{ phrase.original_filename }}
						<span class="opencase-phrase__muted">({{ formatSize(phrase.size) }})</span>
					</template>
					<span v-else class="opencase-phrase__muted">
						{{ t('opencase', 'Ingen fil – teksten ovenfor bruges') }}
					</span>
				</dd>
				<dt>{{ t('opencase', 'Oprettet') }}</dt>
				<dd>{{ formatDateTime(phrase.created_at) }}<span v-if="phrase.created_by" class="opencase-phrase__muted"> · {{ phrase.created_by }}</span></dd>
				<dt>{{ t('opencase', 'Opdateret') }}</dt>
				<dd>{{ formatDateTime(phrase.updated_at) }}<span v-if="phrase.updated_by" class="opencase-phrase__muted"> · {{ phrase.updated_by }}</span></dd>
			</dl>

			<h4 class="opencase-phrase__heading">
				{{ t('opencase', 'Afgrænsning (valgfri — uden afgrænsning kan frasen bruges på alle sager)') }}
			</h4>

			<ul v-if="form.scopes.length" class="opencase-phrase__scope-list">
				<li v-for="(scope, index) in form.scopes" :key="index">
					{{ scopeTypeLabel(scope.scope_type) }}: {{ scopeValueLabel(scope) }}
					<NcButton @click="removeScope(index)">{{ t('opencase', 'Fjern') }}</NcButton>
				</li>
			</ul>

			<div class="opencase-phrase__field">
				<label>{{ t('opencase', 'Afgræns til') }}</label>
				<NcSelect v-model="newScopeTypeOption" :options="scopeTypeOptions" :clearable="false" />

				<NcSelect v-if="newScopeType === 'casetype'"
					v-model="newScopeValueOption"
					:options="caseTypeOptions"
					:placeholder="t('opencase', 'Vælg sagstype')" />
				<NcSelect v-else-if="newScopeType === 'organisation'"
					v-model="newScopeValueOption"
					:options="orgOptions"
					:placeholder="t('opencase', 'Vælg organisation')" />
				<ClassificationPlanSelect v-else-if="newScopeType === 'class_plan'"
					v-model="newScopeValue" />
				<NcTextField v-else
					v-model="newScopeValue"
					:placeholder="t('opencase', 'F.eks. 27.69.00 eller 27.% for hele gruppen')" />

				<NcButton @click="addScope">{{ t('opencase', 'Tilføj afgrænsning') }}</NcButton>
			</div>

			<p v-if="error" class="opencase-phrase__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="!form.title.trim() || saving" @click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="16" />
				</template>
				{{ saving ? t('opencase', 'Gemmer…') : t('opencase', 'Gem') }}
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
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import { showError } from '@nextcloud/dialogs'
import RichTextEditor from './RichTextEditor.vue'
import api from '../services/api.js'
import ClassificationPlanSelect from './ClassificationPlanSelect.vue'
import { caseScopeTypeLabel, classPlanScopeLabel, ensureClassificationPlanItems, scopeTypeOptions } from '../utils/caseScopes.js'
import { categoryTree, PHRASE_FILE_ACCEPT, PHRASE_STATUSES, phraseMatchesOrgs, phraseStatusLabel } from '../utils/phrases.js'

/**
 * Create (phrase = null) or edit a Brevdesigner phrase's metadata and scopes.
 * When creating, a file can be chosen and is uploaded right after the phrase
 * is created. Scopes work like custom field scopes.
 */
export default {
	name: 'PhraseDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcLoadingIcon, UploadIcon, RichTextEditor, ClassificationPlanSelect },

	props: {
		phrase: { type: Object, default: null },
		categories: { type: Array, default: () => [] },
		caseTypes: { type: Array, default: () => [] },
		orgUnits: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: {
				title: this.phrase?.title || '',
				description: this.phrase?.description || '',
				category_id: this.phrase?.category_id ?? null,
				status: this.phrase?.status || 'draft',
				scopes: (this.phrase?.scopes || []).map(s => ({ ...s })),
			},
			selectedFile: null,
			acceptedFileTypes: PHRASE_FILE_ACCEPT,
			newScopeType: 'casetype',
			newScopeValue: '',
			saving: false,
			error: '',
			usages: [],
			usagesLoading: false,
		}
	},

	async mounted() {
		ensureClassificationPlanItems(this.$store)
		if (!this.phrase) return
		this.usagesLoading = true
		try {
			this.usages = await api.getPhraseTemplates(this.phrase.id)
		} catch (e) {
			// the usage list just stays empty
		} finally {
			this.usagesLoading = false
		}
	},

	computed: {
		textHint() {
			// Built here — a {{…}} example inside the template would end the interpolation.
			return this.phrase?.has_file
				? t('opencase', 'Frasen har en fil, som bruges i stedet for teksten. Flettefelter som {field} kan bruges.', { field: '{{sag.nummer}}' })
				: t('opencase', 'Teksten indsættes i brevet med formateringen. Flettefelter som {field} kan bruges.', { field: '{{sag.nummer}}' })
		},
		categoryOptions() {
			return categoryTree(this.categories).map(({ category, depth }) => ({
				id: category.id,
				label: ' '.repeat(depth) + category.name,
			}))
		},
		categoryOption: {
			get() {
				return this.categoryOptions.find(o => o.id === this.form.category_id) || null
			},
			set(v) {
				this.form.category_id = v?.id ?? null
			},
		},
		statusOptions() {
			return PHRASE_STATUSES.map(id => ({ id, label: phraseStatusLabel(id) }))
		},
		statusOption: {
			get() {
				return this.statusOptions.find(o => o.id === this.form.status) || null
			},
			set(v) {
				this.form.status = v?.id || 'draft'
			},
		},
		scopeTypeOptions() {
			return scopeTypeOptions(this.$store.state)
		},
		newScopeTypeOption: {
			get() {
				return this.scopeTypeOptions.find(o => o.id === this.newScopeType) || null
			},
			set(v) {
				this.newScopeType = v?.id || 'casetype'
				this.newScopeValue = ''
			},
		},
		caseTypeOptions() {
			return this.caseTypes.map(ct => ({ id: String(ct.id), label: ct.name }))
		},
		orgOptions() {
			return this.orgUnits.map(o => ({ id: o.org_uuid, label: o.org_name }))
		},
		newScopeValueOption: {
			get() {
				const options = this.newScopeType === 'casetype' ? this.caseTypeOptions : this.orgOptions
				return options.find(o => o.id === this.newScopeValue) || null
			},
			set(v) {
				this.newScopeValue = v?.id || ''
			},
		},
	},

	methods: {
		/**
		 * Whether the phrase — with the scopes as currently edited — still
		 * matches the template's organisations (checked live, before saving).
		 */
		usageMatches(usage) {
			const parentOf = Object.fromEntries(this.orgUnits.map(o => [o.org_uuid, o.org_parent_uuid]))
			return phraseMatchesOrgs({ scopes: this.form.scopes }, usage.org_uuids, parentOf)
		},
		formatSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
		},
		formatDateTime(iso) {
			if (!iso) return '–'
			return new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
		},
		onFileSelected(event) {
			this.selectedFile = event.target.files[0] ?? null
		},
		scopeValueLabel(scope) {
			if (scope.scope_type === 'casetype') {
				return this.caseTypes.find(c => String(c.id) === String(scope.scope_value))?.name || scope.scope_value
			}
			if (scope.scope_type === 'organisation') {
				return this.orgUnits.find(o => o.org_uuid === scope.scope_value)?.org_name || scope.scope_value
			}
			if (scope.scope_type === 'class_plan') return classPlanScopeLabel(this.$store.state, scope.scope_value)
			return scope.scope_value
		},
		scopeTypeLabel(type) {
			return caseScopeTypeLabel(type)
		},
		addScope() {
			if (!this.newScopeValue) return
			this.form.scopes.push({ scope_type: this.newScopeType, scope_value: this.newScopeValue })
			this.newScopeValue = ''
		},
		removeScope(index) {
			this.form.scopes.splice(index, 1)
		},
		async save() {
			this.error = ''
			this.saving = true
			try {
				const payload = {
					title: this.form.title,
					description: this.form.description,
					content: this.$refs.textEditor.getHtml(),
					category_id: this.form.category_id,
					status: this.form.status,
					scopes: this.form.scopes,
				}
				let saved = this.phrase
					? await api.updatePhrase(this.phrase.id, payload)
					: await api.createPhrase(payload)
				if (!this.phrase && this.selectedFile) {
					try {
						saved = await api.uploadPhraseFile(saved.id, this.selectedFile)
					} catch (e) {
						// The phrase exists now — keep it and report the failed upload.
						showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Frasen er oprettet, men filen kunne ikke uploades'))
					}
				}
				this.$emit('saved', saved)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke gemme frasen')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-phrase { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-phrase__field { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
.opencase-phrase__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-phrase__row { display: flex; gap: 12px; flex-wrap: wrap; }
.opencase-phrase__info {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0;
}
.opencase-phrase__info dt { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-phrase__info dd { margin: 0; overflow-wrap: anywhere; }
.opencase-phrase__muted { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.opencase-phrase__usages { margin: 0; padding-left: 18px; list-style: disc; display: flex; flex-direction: column; gap: 2px; }
.opencase-phrase__mismatch { display: block; color: var(--color-error-text); font-size: 0.85em; }
.opencase-phrase__textarea { width: 100%; resize: vertical; }
.opencase-phrase__text :deep(.ProseMirror) { min-height: 140px; }
.opencase-phrase__heading { margin: 8px 0 0; font-size: 1em; font-weight: 600; }
.opencase-phrase__error { color: var(--color-error-text); margin: 0; }
.opencase-phrase__file-input { display: none; }
.opencase-phrase__file-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.opencase-phrase__hint { color: var(--color-text-maxcontrast); font-size: 0.9em; flex: 1; min-width: 200px; }

.opencase-phrase__scope-list {
	margin: 0;
	padding-left: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.opencase-phrase__scope-list li {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
