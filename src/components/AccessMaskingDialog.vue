<template>
	<NcDialog :name="t('opencase', 'Foreslå maskering')"
		:open="true"
		size="large"
		@closing="$emit('close')">
		<div class="oc-mask">
			<p class="oc-mask__file">
				<strong>{{ row.document_title }}</strong> · {{ row.file_name }}
			</p>

			<NcLoadingIcon v-if="loading" :size="32" class="oc-mask__loading" />

			<template v-else>
				<p v-if="pagesWithoutText > 0" class="oc-mask__warning">
					<AlertIcon :size="16" />
					{{ n('opencase',
						'{count} side har ingen tekst (scannet?) og kan ikke gennemsøges – kontrollér den manuelt.',
						'{count} sider har ingen tekst (scannet?) og kan ikke gennemsøges – kontrollér dem manuelt.',
						pagesWithoutText, { count: pagesWithoutText }) }}
				</p>

				<div class="oc-mask__toolbar">
					<NcTextField v-model="term"
						:label="t('opencase', 'Tilføj tekst der skal maskeres (f.eks. et navn)')"
						class="oc-mask__term"
						@keydown.enter.prevent="addTerm" />
					<NcButton :disabled="!term.trim() || addingTerm" @click="addTerm">
						<template #icon>
							<NcLoadingIcon v-if="addingTerm" :size="20" />
							<PlusIcon v-else :size="20" />
						</template>
						{{ t('opencase', 'Tilføj') }}
					</NcButton>
					<NcButton type="tertiary" :disabled="analyzing" @click="analyze">
						<template #icon>
							<NcLoadingIcon v-if="analyzing" :size="20" />
							<RefreshIcon v-else :size="20" />
						</template>
						{{ t('opencase', 'Analysér igen') }}
					</NcButton>
				</div>

				<NcEmptyContent v-if="suggestions.length === 0"
					:name="t('opencase', 'Ingen personoplysninger fundet')"
					:description="t('opencase', 'Maskeringsreglerne fandt intet i filen. Tilføj selv tekst ovenfor, hvis noget skal maskeres.')" />

				<table v-else class="oc-mask__table">
					<thead>
						<tr>
							<th class="oc-mask__check-col">
								<NcCheckboxRadioSwitch :modelValue="allAccepted"
									:indeterminate="someAccepted"
									@update:modelValue="setAll" />
							</th>
							<th>{{ t('opencase', 'Side') }}</th>
							<th>{{ t('opencase', 'Type') }}</th>
							<th>{{ t('opencase', 'Tekst') }}</th>
							<th>{{ t('opencase', 'Sammenhæng') }}</th>
							<th>{{ t('opencase', 'Hjemmel') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="s in suggestions" :key="s.id"
							:class="{ 'oc-mask__row--rejected': s.status === 'rejected' }">
							<td class="oc-mask__check-col">
								<NcCheckboxRadioSwitch :modelValue="s.status === 'accepted'"
									@update:modelValue="v => setStatus(s, v ? 'accepted' : 'rejected')" />
							</td>
							<td>{{ s.page }}</td>
							<td>
								<span :class="['oc-mask__cat', 'oc-mask__cat--' + s.category]">{{ s.label || categoryLabel(s.category) }}</span>
							</td>
							<td class="oc-mask__text">
								{{ s.text }}
								<span v-if="s.count > 1" class="oc-mask__count">×{{ s.count }}</span>
							</td>
							<td class="oc-mask__context">{{ s.context }}</td>
							<td class="oc-mask__legal">{{ s.legal_reference || '—' }}</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>

		<template #actions>
			<span v-if="!loading && suggestions.length > 0" class="oc-mask__summary">
				{{ t('opencase', '{accepted} af {total} markeret til maskering', { accepted: acceptedCount, total: suggestions.length }) }}
			</span>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Luk') }}
			</NcButton>
			<NcButton type="primary" :disabled="applying || acceptedCount === 0" @click="apply">
				<template #icon>
					<NcLoadingIcon v-if="applying" :size="20" />
					<EraserVariantIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Anvend maskering') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import EraserVariantIcon from 'vue-material-design-icons/EraserVariant.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'

const CATEGORY_LABELS = {
	cpr:     'CPR-nr.',
	email:   'E-mail',
	phone:   'Telefon',
	account: 'Kontonr.',
	manual:  'Egen tekst',
}

const errorMessage = (e, fallback) => e?.response?.data?.ocs?.data?.message ?? fallback

export default {
	name: 'AccessMaskingDialog',
	components: {
		NcDialog, NcButton, NcTextField, NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon,
		AlertIcon, PlusIcon, RefreshIcon, EraserVariantIcon,
	},
	props: {
		// A row from the masking-files list: item_id, file_id, file_name, document_title
		row: { type: Object, required: true },
	},
	emits: ['close', 'applied'],
	data() {
		return {
			loading: true,
			analyzing: false,
			addingTerm: false,
			applying: false,
			suggestions: [],
			pagesWithoutText: 0,
			term: '',
		}
	},
	computed: {
		acceptedCount() {
			return this.suggestions.filter(s => s.status === 'accepted').length
		},
		allAccepted() {
			return this.suggestions.length > 0 && this.acceptedCount === this.suggestions.length
		},
		someAccepted() {
			return this.acceptedCount > 0 && this.acceptedCount < this.suggestions.length
		},
	},
	async mounted() {
		// Always re-analyse so findings reflect the current file and detection
		// rules; the server keeps the caseworker's earlier accept/reject choices.
		if (!await this.analyze()) {
			// Service unavailable: still show what was found earlier
			try {
				this.suggestions = await api.getAccessMaskSuggestions(this.row.item_id, this.row.file_id)
			} catch {
				// error already shown by analyze()
			}
		}
		this.loading = false
	},
	methods: {
		categoryLabel(category) {
			return t('opencase', CATEGORY_LABELS[category] ?? category)
		},
		async analyze() {
			this.analyzing = true
			try {
				const result = await api.analyzeAccessMasking(this.row.item_id, this.row.file_id)
				this.suggestions = result.suggestions ?? []
				this.pagesWithoutText = result.pages_without_text ?? 0
				return true
			} catch (e) {
				showError(errorMessage(e, t('opencase', 'Analysen fejlede')))
				return false
			} finally {
				this.analyzing = false
			}
		},
		async addTerm() {
			const term = this.term.trim()
			if (!term) return
			this.addingTerm = true
			try {
				const result = await api.addAccessMaskTerm(this.row.item_id, this.row.file_id, term)
				this.suggestions = result.suggestions ?? this.suggestions
				if (!result.found_on_pages) {
					showError(t('opencase', '"{term}" blev ikke fundet i filen', { term }))
				} else {
					this.term = ''
				}
			} catch (e) {
				showError(errorMessage(e, t('opencase', 'Søgningen fejlede')))
			} finally {
				this.addingTerm = false
			}
		},
		async setStatus(s, status) {
			const previous = s.status
			s.status = status
			try {
				await api.updateAccessMaskSuggestion(s.id, status)
			} catch (e) {
				s.status = previous
				showError(errorMessage(e, t('opencase', 'Opdatering fejlede')))
			}
		},
		async setAll(accept) {
			const status = accept ? 'accepted' : 'rejected'
			await Promise.all(this.suggestions.filter(s => s.status !== status).map(s => this.setStatus(s, status)))
		},
		async apply() {
			this.applying = true
			try {
				const result = await api.applyAccessMasking(this.row.item_id, this.row.file_id)
				showSuccess(n('opencase', '{count} område maskeret', '{count} områder maskeret', result.area_count, { count: result.area_count }))
				this.$emit('applied', this.row)
			} catch (e) {
				showError(errorMessage(e, t('opencase', 'Maskering fejlede')))
			} finally {
				this.applying = false
			}
		},
	},
}
</script>

<style scoped>
.oc-mask { display: flex; flex-direction: column; gap: 12px; min-height: 200px; }
.oc-mask__file { margin: 0; color: var(--color-text-maxcontrast); }
.oc-mask__loading { margin: 32px auto; }
.oc-mask__warning {
	display: flex; align-items: center; gap: 6px; margin: 0;
	padding: 8px 12px; border-radius: var(--border-radius-large);
	background: #fff8e1; color: #8d5f00;
}
.oc-mask__toolbar { display: flex; align-items: flex-end; gap: 8px; }
.oc-mask__term { flex: 1; }
.oc-mask__table { width: 100%; border-collapse: collapse; }
.oc-mask__table th,
.oc-mask__table td { padding: 6px 10px; border-bottom: 1px solid var(--color-border); text-align: left; vertical-align: middle; }
.oc-mask__table th { font-weight: 600; background: var(--color-background-hover); }
.oc-mask__check-col { width: 44px; }
.oc-mask__row--rejected td:not(.oc-mask__check-col) { color: var(--color-text-maxcontrast); text-decoration: line-through; }
.oc-mask__text { font-family: var(--font-face-monospace, monospace); white-space: nowrap; }
.oc-mask__count { margin-inline-start: 4px; font-size: 0.85em; color: var(--color-text-maxcontrast); }
.oc-mask__context { font-size: 0.85em; color: var(--color-text-maxcontrast); }
.oc-mask__legal { font-size: 0.85em; white-space: nowrap; }
.oc-mask__cat { display: inline-block; padding: 1px 8px; border-radius: 8px; font-size: 0.78em; font-weight: 600; background: #f5f5f5; color: #424242; white-space: nowrap; }
.oc-mask__cat--cpr     { background: #ffebee; color: #b71c1c; }
.oc-mask__cat--email   { background: #e3f2fd; color: #1565c0; }
.oc-mask__cat--phone   { background: #e8f5e9; color: #2e7d32; }
.oc-mask__cat--account { background: #fff8e1; color: #8d5f00; }
.oc-mask__cat--manual  { background: #ede7f6; color: #4527a0; }
.oc-mask__summary { margin-inline-end: auto; color: var(--color-text-maxcontrast); font-size: 0.9em; align-self: center; }
</style>
