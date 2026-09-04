<template>
	<NcDialog :name="t('opencase', 'Promptbibliotek')"
		size="large"
		@closing="$emit('close')">
		<div class="ai-prompt-dialog">
			<!-- Left: prompt list -->
			<div class="ai-prompt-dialog__list-panel">
				<div class="ai-prompt-dialog__filter">
					<NcSelect v-model="filterScopeOption"
						:options="filterOptions"
						:clearable="false"
						:searchable="false"
						@update:model-value="filterScope = $event?.id ?? null" />
				</div>
				<div class="ai-prompt-dialog__name-filter">
					<NcTextField v-model="filterText" :placeholder="t('opencase', 'Filtrer efter navn…')" />
				</div>
				<div class="ai-prompt-dialog__favorites-filter">
					<NcCheckboxRadioSwitch v-model="favoritesOnly" type="switch">
						{{ t('opencase', 'Kun favoritter') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="ai-prompt-dialog__list">
					<div v-for="p in filteredPrompts"
						:key="p.id"
						:class="['ai-prompt-dialog__list-item', { 'ai-prompt-dialog__list-item--active': selected && selected.id === p.id }]"
						@click="selectPrompt(p)">
						<div class="ai-prompt-dialog__list-item-info">
							<span class="ai-prompt-dialog__list-item-title">{{ p.title }}</span>
							<span class="ai-prompt-dialog__list-item-scope">{{ scopeLabel(p.scope) }}</span>
						</div>
						<FavoriteButton entity="ai_prompt" :entity-key="p.id" />
					</div>
					<div v-if="filteredPrompts.length === 0 && !loading" class="ai-prompt-dialog__list-empty">
						{{ t('opencase', 'Ingen gemte prompts') }}
					</div>
				</div>
				<NcButton class="ai-prompt-dialog__new-btn" @click="newPrompt">
					<template #icon>
						<PlusIcon :size="18" />
					</template>
					{{ t('opencase', 'Ny prompt') }}
				</NcButton>
			</div>

			<!-- Right: editor -->
			<div class="ai-prompt-dialog__editor">
				<template v-if="selected">
					<div class="ai-prompt-dialog__field">
						<label>{{ t('opencase', 'Titel') }}</label>
						<div class="ai-prompt-dialog__title-row">
							<div class="ai-prompt-dialog__title-input">
								<NcTextField v-model="form.title" :placeholder="t('opencase', 'Prompt titel')" />
							</div>
							<FavoriteButton v-if="selected.id" entity="ai_prompt" :entity-key="selected.id" />
						</div>
					</div>

					<div class="ai-prompt-dialog__field">
						<label>{{ t('opencase', 'Anvendelse') }}</label>
						<NcSelect v-model="selectedScope"
							:options="scopeOptions"
							:clearable="false"
							:searchable="false"
							@update:model-value="form.scope = $event?.id ?? 'all'" />
					</div>

					<OverflowTabs :tabs="dialogTabs" :value="activeTab" @input="onTabChange" />

					<div class="ai-prompt-dialog__tab-content">
						<template v-if="activeTab === 'prompt'">
							<div class="ai-prompt-dialog__field ai-prompt-dialog__field--grow">
								<div class="ai-prompt-dialog__prompt-header">
									<label>{{ t('opencase', 'Prompt') }}</label>
									<NcActions :force-menu="true"
										:menu-name="t('opencase', 'Indsæt parameter')">
										<template #icon>
											<PlusIcon :size="18" />
										</template>
										<NcActionButton v-for="param in insertableParameters"
											:key="param.token"
											@click="insertParameter(param.token)">
											{{ param.label }}
										</NcActionButton>
									</NcActions>
								</div>
								<textarea ref="promptTextarea"
									v-model="form.prompt"
									class="ai-prompt-dialog__textarea"
									:placeholder="t('opencase', 'Skriv din prompt her…')" />
								<p class="ai-prompt-dialog__ph-hint">
									{{ t('opencase', '[Navn] giver et tekstfelt. [Navn:type] giver en vælger — typer: {types}', { types: placeholderTypeHint }) }}
								</p>
							</div>

							<!-- Placeholder inputs rendered when prompt contains [Name] tokens -->
							<div v-if="placeholders.length > 0" class="ai-prompt-dialog__ph-section">
								<label class="ai-prompt-dialog__ph-section-label">
									{{ t('opencase', 'Udfyld felter') }}
								</label>
								<div v-for="ph in placeholders"
									:key="ph.token"
									class="ai-prompt-dialog__field">
									<label>{{ ph.label }}</label>

									<!-- Document template picker (opens its own dialog) -->
									<NcButton v-if="ph.type === 'template'"
										class="ai-prompt-dialog__picker-btn"
										@click="templatePickerToken = ph.token">
										<template #icon>
											<FileDocumentOutlineIcon :size="18" />
										</template>
										{{ placeholderValues[ph.token] || t('opencase', 'Vælg skabelon') }}
									</NcButton>

									<!-- Date picker -->
									<input v-else-if="ph.type === 'date'"
										v-model="placeholderValues[ph.token]"
										type="date"
										class="ai-prompt-dialog__date-input">

									<!-- Remote search field (organisation, user) -->
									<NcSelect v-else-if="isSearchType(ph.type)"
										:model-value="placeholderSelections[ph.token] ?? null"
										:options="searchOptions[ph.token] ?? []"
										:loading="searchLoading[ph.token] === true"
										:filterable="false"
										:placeholder="typePlaceholder(ph.type)"
										@search="query => onPlaceholderSearch(ph, query)"
										@update:model-value="option => onPlaceholderSelect(ph, option)" />

									<!-- Dropdown backed by a code list -->
									<NcSelect v-else-if="ph.type !== 'text'"
										:model-value="placeholderSelections[ph.token] ?? null"
										:options="listOptions[ph.type] ?? []"
										:loading="listLoading[ph.type] === true"
										:placeholder="typePlaceholder(ph.type)"
										@update:model-value="option => onPlaceholderSelect(ph, option)" />

									<!-- Plain textbox (default) -->
									<NcTextField v-else
										v-model="placeholderValues[ph.token]"
										:placeholder="ph.label" />

									<SelectTemplateDialog v-if="templatePickerToken === ph.token"
										select-on-click
										@selected="tpl => onTemplateSelected(ph, tpl)"
										@close="templatePickerToken = null" />
								</div>
							</div>
						</template>

						<template v-else-if="activeTab === 'actions'">
							<div class="ai-prompt-dialog__actions-tab">
								<div v-if="loadingActions" class="ai-prompt-dialog__actions-loading">
									<NcLoadingIcon :size="20" />
									{{ t('opencase', 'Henter handlinger…') }}
								</div>
								<div v-else-if="promptActions.length === 0" class="ai-prompt-dialog__actions-empty">
									{{ t('opencase', 'Ingen handlinger fundet. Gem prompten for at generere handlingerne.') }}
								</div>
								<div v-else class="ai-prompt-dialog__action-list">
									<div v-for="(action, idx) in promptActions"
										:key="idx"
										class="ai-prompt-dialog__action-card">
										<div class="ai-prompt-dialog__action-card-header">
											<span class="ai-prompt-dialog__action-card-index">{{ t('opencase', 'Handling') }} {{ idx + 1 }}</span>
											<code class="ai-prompt-dialog__action-card-type">{{ action.type }}</code>
										</div>
										<div v-if="actionParamEntries(action).length > 0" class="ai-prompt-dialog__action-card-params">
											<div v-for="[key, value] in actionParamEntries(action)" :key="key" class="ai-prompt-dialog__action-card-param">
												<span class="ai-prompt-dialog__action-card-param-key">{{ key }}:</span>
												<span class="ai-prompt-dialog__action-card-param-value">{{ value }}</span>
											</div>
										</div>
									</div>
								</div>
							</div>
						</template>
					</div>

					<div class="ai-prompt-dialog__actions">
						<NcButton :disabled="saving || !isDirty" @click="save">
							<template v-if="saving" #icon>
								<NcLoadingIcon :size="18" />
							</template>
							{{ t('opencase', 'Gem') }}
						</NcButton>
						<NcButton type="primary"
							:disabled="!canExecute"
							@click="execute">
							{{ t('opencase', 'Udfør') }}
						</NcButton>
						<NcButton class="ai-prompt-dialog__delete-btn"
							:disabled="saving || selected.id === undefined"
							@click="remove">
							<template #icon>
								<DeleteIcon :size="18" />
							</template>
						</NcButton>
					</div>
					<p v-if="!scopeMatches" class="ai-prompt-dialog__scope-hint">
						{{ scopeHint }}
					</p>
					<p v-else-if="!allPlaceholdersFilled" class="ai-prompt-dialog__scope-hint">
						{{ t('opencase', 'Udfyld disse felter for at kunne udføre prompten: {fields}', { fields: missingPlaceholders.map(ph => ph.label).join(', ') }) }}
					</p>
				</template>
				<div v-else class="ai-prompt-dialog__placeholder">
					<AutoFixIcon :size="48" class="ai-prompt-dialog__placeholder-icon" />
					<p>{{ t('opencase', 'Vælg en prompt eller opret en ny') }}</p>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import OverflowTabs from './OverflowTabs.vue'
import FavoriteButton from './FavoriteButton.vue'
import SelectTemplateDialog from './SelectTemplateDialog.vue'

import api from '../services/api.js'

const SCOPE_OPTIONS = [
	{ id: 'all',             label: t('opencase', 'Alle') },
	{ id: 'case',            label: t('opencase', 'Sag') },
	{ id: 'document',        label: t('opencase', 'Dokument') },
	{ id: 'inbox_document',  label: t('opencase', 'Indkommet dokument') },
]

const FILTER_OPTIONS = [
	{ id: null, label: t('opencase', 'Alle scopes') },
	...SCOPE_OPTIONS,
]

/**
 * Field types that can be attached to a prompt placeholder as `[Name:type]`.
 *
 * `search: true` means the options are fetched per keystroke from the server,
 * `picker: true` means the value is chosen in a dialog of its own; everything
 * else (except `date` and `text`) is a code list loaded once.
 * The first alias is the canonical name shown in the syntax hint.
 */
const PLACEHOLDER_TYPES = {
	org:         { aliases: ['organisation', 'org'],                    search: true },
	kle:         { aliases: ['kle', 'klenummer', 'kle-nummer'] },
	sensitivity: { aliases: ['følsomhed', 'foelsomhed', 'sensitivity'] },
	facet:       { aliases: ['handlingsfacet', 'facet'] },
	insight:     { aliases: ['indsigtsgrad', 'insight'] },
	user:        { aliases: ['bruger', 'user', 'ansvarlig'],            search: true },
	doccategory: { aliases: ['dokumentkategori', 'doccategory'] },
	doctype:     { aliases: ['dokumenttype', 'doctype'] },
	template:    { aliases: ['skabelon', 'template'],                   picker: true },
	date:        { aliases: ['dato', 'date'] },
}

/**
 * Ready-made tokens offered by the "Indsæt parameter" menu. The type suffix of
 * each one is an alias in PLACEHOLDER_TYPES above, so an inserted token renders
 * its picker straight away.
 */
const INSERTABLE_PARAMETERS = [
	{ label: t('opencase', 'Min organisation'), token: '#MinAfdeling' },
	{ label: t('opencase', 'Organisation'),     token: '[Organisation:organisation]' },
	{ label: t('opencase', 'KLE Nummer'),       token: '[KLE Nummer:kle]' },
	{ label: t('opencase', 'Følsomhed'),        token: '[Følsomhed:følsomhed]' },
	{ label: t('opencase', 'Handlingsfacet'),   token: '[Handlingsfacet:handlingsfacet]' },
	{ label: t('opencase', 'Indsigtsgrad'),     token: '[Indsigtsgrad:indsigtsgrad]' },
	{ label: t('opencase', 'Ansvarlig'),        token: '[Ansvarlig:ansvarlig]' },
	{ label: t('opencase', 'Dokumentkategori'), token: '[Dokumentkategori:dokumentkategori]' },
	{ label: t('opencase', 'Dokumenttype'),     token: '[Dokumenttype:dokumenttype]' },
	{ label: t('opencase', 'Skabelon'),         token: '[Skabelon:skabelon]' },
]

// alias (lowercase) -> canonical type key
const TYPE_ALIASES = Object.fromEntries(
	Object.entries(PLACEHOLDER_TYPES).flatMap(([type, def]) => def.aliases.map(a => [a, type])),
)

/**
 * Split a `[…]` token into its label and field type.
 *
 * Everything after the last colon is treated as a type only when it names a
 * known type — so pre-existing placeholders that merely contain a colon
 * (`[Note: kort]`) keep working as plain textboxes.
 *
 * @param {string} raw the text between the brackets
 * @return {{token: string, label: string, type: string}}
 */
function parsePlaceholder(raw) {
	const idx = raw.lastIndexOf(':')
	if (idx > 0) {
		const type = TYPE_ALIASES[raw.slice(idx + 1).trim().toLowerCase()]
		if (type) return { token: raw, label: raw.slice(0, idx).trim(), type }
	}
	return { token: raw, label: raw, type: 'text' }
}

export default {
	name: 'AiPromptDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcLoadingIcon, NcActions, NcActionButton, PlusIcon, DeleteIcon, AutoFixIcon, FileDocumentOutlineIcon, OverflowTabs, FavoriteButton, SelectTemplateDialog },

	props: {
		currentScope:    { type: String, default: 'all' },
		initialPromptId: { type: Number, default: null },
	},

	emits: ['close', 'execute', 'execute-actions'],

	data() {
		const defaultScope = SCOPE_OPTIONS.find(o => o.id === this.currentScope) ?? SCOPE_OPTIONS[0]
		const defaultFilter = FILTER_OPTIONS.find(o => o.id === this.currentScope) ?? FILTER_OPTIONS[0]
		return {
			loading: false,
			saving: false,
			prompts: [],
			selected: null,
			form: { title: '', scope: defaultScope.id, prompt: '' },
			selectedScope: defaultScope,
			scopeOptions: SCOPE_OPTIONS,
			filterScope: defaultFilter.id,
			filterScopeOption: defaultFilter,
			filterOptions: FILTER_OPTIONS,
			filterText: '',
			favoritesOnly: false,
			placeholderValues: {},
			placeholderSelections: {},
			listOptions: {},
			listLoading: {},
			searchOptions: {},
			searchLoading: {},
			searchTimeouts: {},
			templatePickerToken: null,
			activeTab: 'prompt',
			promptActions: [],
			loadingActions: false,
		}
	},

	computed: {
		dialogTabs() {
			return [
				{ id: 'prompt',  label: t('opencase', 'Prompt') },
				{ id: 'actions', label: t('opencase', 'AI handlinger') },
			]
		},

		filteredPrompts() {
			let result = this.filterScope === null
				? this.prompts
				: this.prompts.filter(p => p.scope === this.filterScope)
			if (this.favoritesOnly) {
				const favoriteIds = new Set(
					this.$store.state.favorites.filter(f => f.entity === 'ai_prompt').map(f => f.key),
				)
				result = result.filter(p => favoriteIds.has(p.id))
			}
			const name = this.filterText.trim().toLowerCase()
			if (name) result = result.filter(p => p.title?.toLowerCase().includes(name))
			return result
		},

		isDirty() {
			if (!this.selected) return false
			return this.form.title !== (this.selected.title ?? '')
				|| this.form.scope  !== (this.selected.scope  ?? 'all')
				|| this.form.prompt !== (this.selected.prompt ?? '')
		},

		placeholders() {
			const matches = [...this.form.prompt.matchAll(/\[([^\]]+)\]/g)]
			const seen = new Set()
			return matches
				.map(m => parsePlaceholder(m[1]))
				.filter(ph => !seen.has(ph.token) && seen.add(ph.token))
		},

		insertableParameters() {
			return INSERTABLE_PARAMETERS
		},

		placeholderTypeHint() {
			return Object.values(PLACEHOLDER_TYPES).map(def => def.aliases[0]).join(', ')
		},

		missingPlaceholders() {
			return this.placeholders.filter(ph => (this.placeholderValues[ph.token] ?? '').trim() === '')
		},

		allPlaceholdersFilled() {
			return this.missingPlaceholders.length === 0
		},

		scopeMatches() {
			if (!this.selected) return false
			const scope = this.form.scope
			if (scope === 'all') return true
			if (scope === 'case') return ['case', 'document', 'inbox_document'].includes(this.currentScope)
			if (scope === 'document') return ['document', 'inbox_document'].includes(this.currentScope)
			if (scope === 'inbox_document') return this.currentScope === 'inbox_document'
			return false
		},

		canExecute() {
			return this.scopeMatches && this.allPlaceholdersFilled
		},

		scopeHint() {
			const map = {
				case:           t('opencase', 'Denne prompt kræver at en sag er åben.'),
				document:       t('opencase', 'Denne prompt kræver at et dokument er åbent.'),
				inbox_document: t('opencase', 'Denne prompt kræver at et indkommet dokument er åbent.'),
			}
			return map[this.form.scope] ?? ''
		},
	},

	watch: {
		placeholders: {
			immediate: true,
			handler(newPhs) {
				// Keep existing values for placeholders that are still present; drop removed ones
				const values = {}
				const selections = {}
				for (const ph of newPhs) {
					values[ph.token] = this.placeholderValues[ph.token] ?? ''
					if (this.placeholderSelections[ph.token]) {
						selections[ph.token] = this.placeholderSelections[ph.token]
					}
					this.ensureOptions(ph)
				}
				this.placeholderValues = values
				this.placeholderSelections = selections
			},
		},
	},

	async mounted() {
		await this.load()
		if (this.initialPromptId) {
			const p = this.prompts.find(p => p.id === this.initialPromptId)
			if (p) this.selectPrompt(p)
		}
	},

	methods: {
		scopeLabel(scope) {
			return SCOPE_OPTIONS.find(o => o.id === scope)?.label ?? scope
		},

		// Drop the token in at the caret (replacing any selection) and leave the
		// cursor just after it so the user can keep typing.
		insertParameter(token) {
			const el = this.$refs.promptTextarea
			const text = this.form.prompt ?? ''
			const start = el?.selectionStart ?? text.length
			const end = el?.selectionEnd ?? text.length

			this.form.prompt = text.slice(0, start) + token + text.slice(end)

			this.$nextTick(() => {
				if (!el) return
				el.focus()
				const caret = start + token.length
				el.setSelectionRange(caret, caret)
			})
		},

		isSearchType(type) {
			return PLACEHOLDER_TYPES[type]?.search === true
		},

		isPickerType(type) {
			return PLACEHOLDER_TYPES[type]?.picker === true
		},

		typePlaceholder(type) {
			const map = {
				org:         t('opencase', 'Søg efter organisation'),
				kle:         t('opencase', 'Vælg KLE-emneord'),
				sensitivity: t('opencase', 'Vælg følsomhed'),
				facet:       t('opencase', 'Vælg handlingsfacet'),
				insight:     t('opencase', 'Vælg indsigtsgrad'),
				user:        t('opencase', 'Søg efter bruger'),
				doccategory: t('opencase', 'Vælg kategori'),
				doctype:     t('opencase', 'Vælg dokumenttype'),
			}
			return map[type] ?? t('opencase', 'Vælg…')
		},

		// Kick off whatever data a placeholder needs before it can be rendered
		ensureOptions(ph) {
			if (ph.type === 'org' && this.searchOptions[ph.token] === undefined) {
				this.runPlaceholderSearch(ph, '')
			} else if (!this.isSearchType(ph.type) && !this.isPickerType(ph.type)
				&& ph.type !== 'text' && ph.type !== 'date') {
				this.loadListOptions(ph.type)
			}
		},

		/**
		 * Load a code list once per type. Each option carries a `value`, which is the
		 * string substituted into the prompt — deliberately the KLE/facet code or the
		 * name rather than the internal id, since that is what the AI action executor
		 * matches on.
		 *
		 * @param {string} type canonical placeholder type
		 */
		async loadListOptions(type) {
			if (this.listOptions[type] !== undefined || this.listLoading[type]) return
			this.listLoading = { ...this.listLoading, [type]: true }
			let options = []
			try {
				switch (type) {
				case 'kle':
					await this.$store.dispatch('fetchClassificationSubjects')
					options = this.$store.state.classificationSubjects
						.filter(s => /^\d{2}\.\d{2}\.\d{2}$/.test(s.code))
						.map(s => ({ id: s.code, label: `${s.code} – ${s.title}`, value: s.code }))
					break
				case 'sensitivity':
					await this.$store.dispatch('fetchSensitivities')
					options = this.$store.state.sensitivities
						.map(s => ({ id: s.key, label: s.title, value: s.title }))
					break
				case 'facet':
					await this.$store.dispatch('fetchClassificationFacets')
					options = this.$store.state.classificationFacets
						.filter(f => /^[A-Za-z]\d{2}$/.test(f.code))
						.map(f => ({ id: f.uuid, label: `${f.code} – ${f.title}`, value: f.code }))
					break
				case 'insight':
					await this.$store.dispatch('fetchInsightLevels')
					options = this.$store.state.insightLevels
						.map(l => ({ id: l.id, label: l.name, value: l.name }))
					break
				case 'doccategory':
					options = (await api.getDocumentCategories())
						.map(c => ({ id: c.id, label: c.name, value: c.name }))
					break
				case 'doctype':
					options = (await api.getDocumentTypes())
						.map(ty => ({ id: ty.name, label: ty.name, value: ty.name }))
					break
				}
			} catch (e) {
				options = []
			} finally {
				this.listOptions = { ...this.listOptions, [type]: options }
				this.listLoading = { ...this.listLoading, [type]: false }
			}
		},

		onPlaceholderSearch(ph, query) {
			clearTimeout(this.searchTimeouts[ph.token])
			if (ph.type === 'user' && (!query || query.length < 2)) return
			this.searchLoading = { ...this.searchLoading, [ph.token]: true }
			this.searchTimeouts[ph.token] = setTimeout(() => this.runPlaceholderSearch(ph, query), 300)
		},

		async runPlaceholderSearch(ph, query) {
			this.searchLoading = { ...this.searchLoading, [ph.token]: true }
			let options = []
			try {
				options = ph.type === 'user'
					? (await api.searchUsers(query)).map(u => ({ ...u, value: u.id }))
					: (await api.searchOrganisations(query)).map(o => ({ ...o, value: o.id }))
			} catch (e) {
				options = []
			} finally {
				this.searchOptions = { ...this.searchOptions, [ph.token]: options }
				this.searchLoading = { ...this.searchLoading, [ph.token]: false }
			}
		},

		onPlaceholderSelect(ph, option) {
			this.placeholderSelections = { ...this.placeholderSelections, [ph.token]: option }
			this.placeholderValues = {
				...this.placeholderValues,
				[ph.token]: option ? String(option.value ?? option.label ?? '') : '',
			}
		},

		// Templates are matched by name when the AI actions are executed, so that
		// — not the numeric id — is what goes into the prompt.
		onTemplateSelected(ph, template) {
			if (template) {
				this.placeholderValues = { ...this.placeholderValues, [ph.token]: template.name ?? '' }
			}
			this.templatePickerToken = null
		},

		resetPlaceholders() {
			for (const timeout of Object.values(this.searchTimeouts)) clearTimeout(timeout)
			this.searchTimeouts = {}
			this.placeholderValues = {}
			this.placeholderSelections = {}
			this.searchOptions = {}
			this.searchLoading = {}
			this.templatePickerToken = null
		},

		async load() {
			this.loading = true
			try {
				this.prompts = await api.getAiPrompts()
			} finally {
				this.loading = false
			}
		},

		selectPrompt(p) {
			this.selected = p
			this.form = { title: p.title, scope: p.scope, prompt: p.prompt }
			this.selectedScope = SCOPE_OPTIONS.find(o => o.id === p.scope) ?? SCOPE_OPTIONS[0]
			this.resetPlaceholders()
			this.activeTab = 'prompt'
			this.promptActions = []
		},

		newPrompt() {
			const defaultScope = SCOPE_OPTIONS.find(o => o.id === this.currentScope) ?? SCOPE_OPTIONS[0]
			this.selected = { title: '', scope: defaultScope.id, prompt: '' }
			this.form = { title: '', scope: defaultScope.id, prompt: '' }
			this.selectedScope = defaultScope
			this.resetPlaceholders()
			this.activeTab = 'prompt'
			this.promptActions = []
		},

		onTabChange(id) {
			this.activeTab = id
			if (id === 'actions') this.loadActions()
		},

		actionParamEntries(action) {
			return Object.entries(action).filter(([key]) => key !== 'type')
		},

		async loadActions() {
			if (!this.selected?.id) {
				this.promptActions = []
				return
			}
			this.loadingActions = true
			try {
				this.promptActions = await api.getAiPromptActions(this.selected.id)
			} catch {
				this.promptActions = []
			} finally {
				this.loadingActions = false
			}
		},

		async save() {
			if (this.saving) return
			this.saving = true
			try {
				const payload = { title: this.form.title, scope: this.form.scope, prompt: this.form.prompt }
				if (this.selected.id) {
					const updated = await api.updateAiPrompt(this.selected.id, payload)
					const idx = this.prompts.findIndex(p => p.id === updated.id)
					if (idx !== -1) this.prompts.splice(idx, 1, updated)
					this.selected = updated
				} else {
					const created = await api.createAiPrompt(payload)
					this.prompts.push(created)
					this.prompts.sort((a, b) => a.title.localeCompare(b.title))
					this.selected = created
				}
				this.form = { title: this.selected.title, scope: this.selected.scope, prompt: this.selected.prompt }
				if (this.activeTab === 'actions') await this.loadActions()
			} finally {
				this.saving = false
			}
		},

		async execute() {
			if (this.isDirty) await this.save()

			const substitute = (text) => {
				let result = text
				for (const ph of this.placeholders) {
					const value = (this.placeholderValues[ph.token] ?? '').trim()
					result = result.split(`[${ph.token}]`).join(value)
					// The action generator is asked to copy tokens verbatim, but it sometimes
					// drops the `:type` suffix — substitute the bare label too.
					if (ph.label !== ph.token) {
						result = result.split(`[${ph.label}]`).join(value)
					}
				}
				return result
			}

			if (this.selected?.id) {
				api.touchAiPrompt(this.selected.id).catch(() => {})

				const cachedActions = await api.getAiPromptActions(this.selected.id).catch(() => [])
				if (cachedActions.length > 0) {
					const actions = cachedActions.map(action => {
						const substituted = {}
						for (const [key, value] of Object.entries(action)) {
							substituted[key] = typeof value === 'string' ? substitute(value) : value
						}
						return substituted
					})
					this.$emit('execute-actions', actions, this.selected.title)
					return
				}
			}

			this.$emit('execute', substitute(this.form.prompt))
		},

		async remove() {
			if (!this.selected?.id) return
			await api.deleteAiPrompt(this.selected.id)
			this.prompts = this.prompts.filter(p => p.id !== this.selected.id)
			this.selected = null
		},
	},
}
</script>

<style scoped>
.ai-prompt-dialog {
	display: grid;
	grid-template-columns: 220px 1fr;
	grid-template-rows: 100%;
	gap: 0;
	height: 720px;
	overflow: hidden;
}

.ai-prompt-dialog__list-panel {
	display: flex;
	flex-direction: column;
	border-right: 1px solid var(--color-border);
	min-height: 0;
	overflow: hidden;
}

.ai-prompt-dialog__filter {
	padding: 6px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
}

.ai-prompt-dialog__filter :deep(.v-select) {
	width: 100%;
	min-width: 0;
}

.ai-prompt-dialog__name-filter {
	padding: 6px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
}

.ai-prompt-dialog__favorites-filter {
	padding: 6px 10px;
	flex-shrink: 0;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
}

.ai-prompt-dialog__list {
	flex: 1;
	overflow-y: auto;
	padding: 6px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ai-prompt-dialog__list-empty {
	padding: 12px 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-align: center;
}

.ai-prompt-dialog__list-item {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 3px 4px 3px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.1s;
}

.ai-prompt-dialog__list-item:hover {
	background: var(--color-background-hover);
}

.ai-prompt-dialog__list-item--active {
	background: var(--color-primary-element-light);
}

.ai-prompt-dialog__list-item-info {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
}

.ai-prompt-dialog__list-item-title {
	font-size: 0.9em;
	font-weight: 500;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ai-prompt-dialog__list-item-scope {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
}

.ai-prompt-dialog__new-btn {
	margin: 6px;
	flex-shrink: 0;
}

.ai-prompt-dialog__editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	min-height: 0;
	overflow: hidden;
}

.ai-prompt-dialog__tab-content {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ai-prompt-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ai-prompt-dialog__field--grow {
	flex-shrink: 0;
}

.ai-prompt-dialog__field label {
	font-size: 0.85em;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.ai-prompt-dialog__title-row {
	display: flex;
	align-items: center;
	gap: 4px;
}

.ai-prompt-dialog__title-input {
	flex: 1;
	min-width: 0;
}

.ai-prompt-dialog__textarea {
	height: 140px;
	min-height: 80px;
	padding: 8px 10px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	font-family: inherit;
	line-height: 1.5;
	resize: vertical;
	box-sizing: border-box;
	width:auto;
}

.ai-prompt-dialog__textarea:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.ai-prompt-dialog__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ai-prompt-dialog__delete-btn {
	margin-left: auto;
}

.ai-prompt-dialog__ph-section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
}

.ai-prompt-dialog__prompt-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	min-height: 34px;
}

.ai-prompt-dialog__ph-hint {
	margin: 4px 0 0;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
}

.ai-prompt-dialog__picker-btn {
	width: 100%;
}

.ai-prompt-dialog__date-input {
	width: 100%;
	box-sizing: border-box;
	margin: 0;
}

.ai-prompt-dialog__ph-section-label {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ai-prompt-dialog__actions-tab {
	flex: 1;
	min-height: 0;
}

.ai-prompt-dialog__actions-loading,
.ai-prompt-dialog__actions-empty {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ai-prompt-dialog__action-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ai-prompt-dialog__action-card {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
}

.ai-prompt-dialog__action-card-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ai-prompt-dialog__action-card-index {
	font-size: 0.8em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ai-prompt-dialog__action-card-type {
	font-size: 0.85em;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.ai-prompt-dialog__action-card-params {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.ai-prompt-dialog__action-card-param {
	display: flex;
	gap: 6px;
	font-size: 0.85em;
}

.ai-prompt-dialog__action-card-param-key {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.ai-prompt-dialog__action-card-param-value {
	color: var(--color-main-text);
	word-break: break-word;
}

.ai-prompt-dialog__scope-hint {
	font-size: 0.82em;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.ai-prompt-dialog__placeholder {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	flex: 1;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.ai-prompt-dialog__placeholder-icon {
	opacity: 0.3;
}
</style>
