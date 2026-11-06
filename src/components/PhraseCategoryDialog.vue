<template>
	<NcDialog :name="category ? t('opencase', 'Rediger frasekategori') : t('opencase', 'Opret frasekategori')"
		size="normal"
		@closing="$emit('close')">
		<div class="opencase-phrase-category">
			<div class="opencase-phrase-category__field">
				<label>{{ t('opencase', 'Navn') }} *</label>
				<NcTextField v-model="form.name" />
			</div>

			<div class="opencase-phrase-category__field">
				<label>{{ t('opencase', 'Beskrivelse') }}</label>
				<textarea v-model="form.description" rows="3" class="opencase-phrase-category__textarea" />
			</div>

			<div class="opencase-phrase-category__field">
				<label>{{ t('opencase', 'Overordnet kategori') }}</label>
				<NcSelect v-model="parentOption"
					:options="parentOptions"
					:placeholder="t('opencase', 'Ingen (topniveau)')" />
			</div>

			<div class="opencase-phrase-category__field">
				<label>{{ t('opencase', 'Sortering') }}</label>
				<NcTextField v-model="form.sort_order" type="number" />
			</div>

			<p v-if="error" class="opencase-phrase-category__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="!form.name.trim() || saving" @click="save">
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
import api from '../services/api.js'
import { categoryTree } from '../utils/phrases.js'

/**
 * Create (category = null) or edit a Brevdesigner phrase category.
 */
export default {
	name: 'PhraseCategoryDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect },

	props: {
		category: { type: Object, default: null },
		categories: { type: Array, default: () => [] },
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: {
				name: this.category?.name || '',
				description: this.category?.description || '',
				parent_id: this.category?.parent_id ?? null,
				sort_order: String(this.category?.sort_order ?? 0),
			},
			saving: false,
			error: '',
		}
	},

	computed: {
		/** All categories except this one and its descendants (to prevent cycles). */
		parentOptions() {
			const options = []
			let skipDepth = null
			for (const { category, depth } of categoryTree(this.categories)) {
				if (skipDepth !== null && depth > skipDepth) continue
				skipDepth = null
				if (this.category && category.id === this.category.id) {
					skipDepth = depth
					continue
				}
				options.push({ id: category.id, label: ' '.repeat(depth) + category.name })
			}
			return options
		},
		parentOption: {
			get() {
				return this.parentOptions.find(o => o.id === this.form.parent_id) || null
			},
			set(v) {
				this.form.parent_id = v?.id ?? null
			},
		},
	},

	methods: {
		async save() {
			this.error = ''
			this.saving = true
			try {
				const payload = {
					name: this.form.name,
					description: this.form.description,
					parent_id: this.form.parent_id,
					sort_order: parseInt(this.form.sort_order, 10) || 0,
				}
				const saved = this.category
					? await api.updatePhraseCategory(this.category.id, payload)
					: await api.createPhraseCategory(payload)
				this.$emit('saved', saved)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke gemme kategorien')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-phrase-category { display: flex; flex-direction: column; gap: 14px; padding: 8px 0; }
.opencase-phrase-category__field { display: flex; flex-direction: column; gap: 4px; }
.opencase-phrase-category__field label { font-weight: 600; font-size: 0.9em; color: var(--color-text-maxcontrast); }
.opencase-phrase-category__textarea { width: 100%; resize: vertical; }
.opencase-phrase-category__error { color: var(--color-error-text); margin: 0; }
</style>
