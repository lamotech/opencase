<template>
	<div class="tpl-phrases">
		<NcLoadingIcon v-if="loading" :size="32" class="tpl-phrases__loading" />
		<template v-else>
			<div class="tpl-phrases__field">
				<label>{{ t('opencase', 'Tilføj frase') }}</label>
				<NcSelect v-model="pickerValue"
					:options="availableOptions"
					:placeholder="t('opencase', 'Søg efter frase…')"
					:filter-by="filterOption">
					<template #option="option">
						<div class="tpl-phrases__option">
							<span>{{ option.label }}</span>
							<span class="tpl-phrases__muted">{{ option.categoryPath }}</span>
						</div>
					</template>
				</NcSelect>
			</div>

			<p v-if="orgUuids.length" class="tpl-phrases__muted">
				{{ t('opencase', 'Kun fraser, der matcher skabelonens organisationer, kan vælges.') }}
			</p>

			<p v-if="modelValue.length === 0" class="tpl-phrases__muted">
				{{ t('opencase', 'Der er ikke tilknyttet nogen fraser til skabelonen.') }}
			</p>

			<table v-else class="tpl-phrases__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Frase') }}</th>
						<th class="tpl-phrases__center">{{ t('opencase', 'Obligatorisk') }}</th>
						<th class="tpl-phrases__center">{{ t('opencase', 'Forudvalgt') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, index) in modelValue" :key="row.phrase_id">
						<td>
							<div class="tpl-phrases__title">
								{{ phraseById[row.phrase_id]?.title || t('opencase', 'Ukendt frase') }}
								<span v-if="phraseById[row.phrase_id] && phraseById[row.phrase_id].status !== 'active'"
									class="tpl-phrases__status">
									{{ statusLabel(phraseById[row.phrase_id].status) }}
								</span>
							</div>
							<div v-if="formatNote(phraseById[row.phrase_id])" class="tpl-phrases__draft">
								{{ formatNote(phraseById[row.phrase_id]) }}
							</div>
							<div v-if="phraseById[row.phrase_id] && phraseById[row.phrase_id].status === 'draft'" class="tpl-phrases__draft">
								{{ t('opencase', 'Kladde — indsættes ikke i breve, før frasen er aktiv.') }}
							</div>
							<div v-if="phraseById[row.phrase_id] && !matchesOrgs(phraseById[row.phrase_id])" class="tpl-phrases__mismatch">
								{{ t('opencase', 'Matcher ikke skabelonens organisationer — fjern frasen eller ret afgrænsningen.') }}
							</div>
							<div class="tpl-phrases__muted">{{ categoryPath(categories, phraseById[row.phrase_id]?.category_id) }}</div>
						</td>
						<td class="tpl-phrases__center">
							<input type="checkbox"
								:checked="row.is_required"
								:aria-label="t('opencase', 'Obligatorisk')"
								@change="update(index, { is_required: $event.target.checked, is_default: $event.target.checked || row.is_default })">
						</td>
						<td class="tpl-phrases__center">
							<input type="checkbox"
								:checked="row.is_default || row.is_required"
								:disabled="row.is_required"
								:title="row.is_required ? t('opencase', 'Obligatoriske fraser er altid forudvalgt') : ''"
								:aria-label="t('opencase', 'Forudvalgt')"
								@change="update(index, { is_default: $event.target.checked })">
						</td>
						<td>
							<div class="tpl-phrases__actions">
								<NcButton type="tertiary"
									:title="t('opencase', 'Flyt op')"
									:disabled="index === 0"
									@click="move(index, -1)">
									<template #icon>
										<ArrowUpIcon :size="16" />
									</template>
								</NcButton>
								<NcButton type="tertiary"
									:title="t('opencase', 'Flyt ned')"
									:disabled="index === modelValue.length - 1"
									@click="move(index, 1)">
									<template #icon>
										<ArrowDownIcon :size="16" />
									</template>
								</NcButton>
								<NcButton type="tertiary-no-background"
									:title="t('opencase', 'Fjern')"
									@click="remove(index)">
									<template #icon>
										<CloseIcon :size="16" />
									</template>
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import api from '../services/api.js'
import { categoryPath, phraseMatchesOrgs, phraseStatusLabel } from '../utils/phrases.js'

/**
 * Select the phrases attached to a template, in order, each marked
 * required (is_required) and/or pre-selected (is_default).
 *
 * v-model: [{ phrase_id, is_required, is_default }]
 * Emits "mismatch" with the number of attached phrases outside the
 * template's organisations (orgUuids) — the server rejects those.
 */
export default {
	name: 'TemplatePhrasePicker',

	components: { NcButton, NcSelect, NcLoadingIcon, ArrowUpIcon, ArrowDownIcon, CloseIcon },

	props: {
		modelValue: { type: Array, required: true },
		/** Organisations the template is limited to ([] = all) */
		orgUuids: { type: Array, default: () => [] },
		/** All organisations (org_uuid, org_parent_uuid), to resolve parents */
		orgs: { type: Array, default: () => [] },
		/** The template file's extension (docx, odt, …), if known */
		templateFormat: { type: String, default: null },
	},

	emits: ['update:modelValue', 'mismatch'],

	data() {
		return {
			loading: false,
			phrases: [],
			categories: [],
		}
	},

	computed: {
		/** Attached phrases that do not match the template's organisations. */
		mismatchCount() {
			return this.modelValue.filter(r => this.phraseById[r.phrase_id] && !this.matchesOrgs(this.phraseById[r.phrase_id])).length
		},
		parentOf() {
			return Object.fromEntries(this.orgs.map(o => [o.org_uuid, o.org_parent_uuid]))
		},
		phraseById() {
			return Object.fromEntries(this.phrases.map(p => [p.id, p]))
		},
		/**
		 * Phrases not yet attached; archived phrases and phrases outside the
		 * template's organisations cannot be added.
		 */
		availableOptions() {
			const selected = new Set(this.modelValue.map(r => r.phrase_id))
			return this.phrases
				.filter(p => p.status !== 'archived' && !selected.has(p.id) && this.matchesOrgs(p))
				.map(p => ({
					id: p.id,
					label: p.title,
					categoryPath: categoryPath(this.categories, p.category_id),
					description: p.description || '',
				}))
		},
		pickerValue: {
			get() {
				return null
			},
			set(option) {
				if (option) {
					this.$emit('update:modelValue', [
						...this.modelValue,
						{ phrase_id: option.id, is_required: false, is_default: false },
					])
				}
			},
		},
	},

	watch: {
		mismatchCount: {
			immediate: true,
			handler(count) {
				this.$emit('mismatch', count)
			},
		},
	},

	async mounted() {
		this.loading = true
		try {
			const [phrases, categories] = await Promise.all([
				api.getPhrases(),
				api.getPhraseCategories(),
			])
			this.phrases = phrases
			this.categories = categories
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente fraser'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Warning for a phrase file the template cannot take with formatting:
		 * a .docx phrase goes into .docx templates, .odt into .odt; otherwise
		 * (and in spreadsheets) it is inserted as plain text. Rich-text phrases
		 * fit both text formats.
		 */
		formatNote(phrase) {
			if (!phrase || !this.templateFormat) return ''
			if (['xlsx', 'ods'].includes(this.templateFormat)) {
				return t('opencase', 'Skabelonen er et regneark — frasen indsættes som ren tekst.')
			}
			const phraseFormat = phrase.has_file ? (phrase.original_filename || '').split('.').pop().toLowerCase() : null
			if (phraseFormat && phraseFormat !== this.templateFormat) {
				return t('opencase', 'Frasens fil er .{phrase}, skabelonen er .{template} — frasen indsættes uden formatering.', { phrase: phraseFormat, template: this.templateFormat })
			}
			return ''
		},

		matchesOrgs(phrase) {
			return phraseMatchesOrgs(phrase, this.orgUuids, this.parentOf)
		},

		categoryPath,

		statusLabel: phraseStatusLabel,

		filterOption(option, label, search) {
			const query = search.toLowerCase()
			return [option.label, option.categoryPath, option.description]
				.some(text => text.toLowerCase().includes(query))
		},

		update(index, changes) {
			const rows = this.modelValue.slice()
			rows[index] = { ...rows[index], ...changes }
			this.$emit('update:modelValue', rows)
		},

		move(index, delta) {
			const rows = this.modelValue.slice()
			const [row] = rows.splice(index, 1)
			rows.splice(index + delta, 0, row)
			this.$emit('update:modelValue', rows)
		},

		remove(index) {
			this.$emit('update:modelValue', this.modelValue.filter((_, i) => i !== index))
		},
	},
}
</script>

<style scoped>
.tpl-phrases { display: flex; flex-direction: column; gap: 14px; }
.tpl-phrases__loading { display: block; margin: 24px auto; }
.tpl-phrases__field { display: flex; flex-direction: column; gap: 4px; }
.tpl-phrases__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.tpl-phrases__option { display: flex; flex-direction: column; }
.tpl-phrases__muted { color: var(--color-text-maxcontrast); font-size: 0.9em; margin: 0; }
.tpl-phrases__title { font-weight: 500; overflow-wrap: anywhere; }
.tpl-phrases__status {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	background: #e3f2fd;
	color: #1565c0;
}

.tpl-phrases__draft { color: var(--color-warning-text); font-size: 0.85em; }
.tpl-phrases__mismatch { color: var(--color-error-text); font-size: 0.85em; }
.tpl-phrases__table { width: 100%; border-collapse: collapse; }
.tpl-phrases__table th,
.tpl-phrases__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}
.tpl-phrases__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
}
.tpl-phrases__table .tpl-phrases__center { text-align: center; width: 1%; white-space: nowrap; }
.tpl-phrases__actions { display: flex; gap: 2px; justify-content: flex-end; white-space: nowrap; }
</style>
