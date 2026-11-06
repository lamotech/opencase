<template>
	<div class="phrase-categories">
		<div class="phrase-categories__toolbar">
			<p class="phrase-categories__description">
				{{ t('opencase', 'Kategorier bruges til at organisere frasebiblioteket. En kategori kan have underkategorier.') }}
			</p>
			<NcButton type="primary" @click="editing = {}">
				<template #icon>
					<PlusIcon :size="16" />
				</template>
				{{ t('opencase', 'Opret kategori') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="phrase-categories__loading" />

		<NcEmptyContent v-else-if="categories.length === 0"
			:name="t('opencase', 'Ingen frasekategorier')">
			<template #icon>
				<FolderOutlineIcon :size="64" />
			</template>
			<template #description>
				{{ t('opencase', 'Opret en kategori for at komme i gang') }}
			</template>
		</NcEmptyContent>

		<table v-else class="phrase-categories__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Navn') }}</th>
					<th>{{ t('opencase', 'Beskrivelse') }}</th>
					<th>{{ t('opencase', 'Sortering') }}</th>
					<th>{{ t('opencase', 'Fraser') }}</th>
					<th>{{ t('opencase', 'Opdateret') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="{ category, depth } in tree" :key="category.id">
					<td>
						<span class="phrase-categories__name" :style="{ paddingLeft: (depth * 20) + 'px' }">
							<FolderOutlineIcon :size="16" />
							{{ category.name }}
						</span>
					</td>
					<td class="phrase-categories__desc">{{ category.description }}</td>
					<td>{{ category.sort_order }}</td>
					<td>{{ category.phrase_count }}</td>
					<td>{{ formatDate(category.updated_at) }} <span v-if="category.updated_by" class="phrase-categories__muted">{{ category.updated_by }}</span></td>
					<td>
						<div class="phrase-categories__actions">
							<NcButton type="tertiary"
								:title="t('opencase', 'Rediger')"
								@click="editing = category">
								<template #icon>
									<PencilIcon :size="16" />
								</template>
							</NcButton>
							<NcButton type="tertiary-no-background"
								:title="deleteBlockedReason(category) || t('opencase', 'Slet')"
								:disabled="!!deleteBlockedReason(category)"
								@click="remove(category)">
								<template #icon>
									<DeleteIcon :size="16" />
								</template>
							</NcButton>
						</div>
					</td>
				</tr>
			</tbody>
		</table>

		<PhraseCategoryDialog v-if="editing"
			:category="editing.id ? editing : null"
			:categories="categories"
			@close="editing = null"
			@saved="onSaved" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { showError } from '@nextcloud/dialogs'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'

import PhraseCategoryDialog from './PhraseCategoryDialog.vue'
import api from '../services/api.js'
import { categoryTree } from '../utils/phrases.js'

/**
 * Brevdesigner tab: create and edit phrase categories (shown as a tree).
 */
export default {
	name: 'PhraseCategoriesTab',

	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		PlusIcon,
		PencilIcon,
		DeleteIcon,
		FolderOutlineIcon,
		PhraseCategoryDialog,
	},

	data() {
		return {
			loading: false,
			categories: [],
			editing: null,
		}
	},

	computed: {
		tree() {
			return categoryTree(this.categories)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.categories = await api.getPhraseCategories()
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente frasekategorier'))
			} finally {
				this.loading = false
			}
		},

		async onSaved() {
			this.editing = null
			await this.load()
		},

		deleteBlockedReason(category) {
			if (this.categories.some(c => c.parent_id === category.id)) {
				return t('opencase', 'Kategorien har underkategorier og kan ikke slettes')
			}
			if (category.phrase_count > 0) {
				return t('opencase', 'Kategorien indeholder fraser og kan ikke slettes')
			}
			return ''
		},

		async remove(category) {
			if (!window.confirm(t('opencase', 'Slet kategorien "{name}"?', { name: category.name }))) {
				return
			}
			try {
				await api.deletePhraseCategory(category.id)
				this.categories = this.categories.filter(c => c.id !== category.id)
			} catch (e) {
				showError(t('opencase', 'Kategorien kunne ikke slettes'))
				await this.load()
			}
		},

		formatDate(iso) {
			if (!iso) return ''
			return new Date(iso).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.phrase-categories__toolbar {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 16px;
}

.phrase-categories__description {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.phrase-categories__loading {
	display: block;
	margin: 24px auto;
}

.phrase-categories__table {
	width: 100%;
	border-collapse: collapse;
}

.phrase-categories__table th,
.phrase-categories__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.phrase-categories__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
}

.phrase-categories__name {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-weight: 500;
}

.phrase-categories__desc {
	color: var(--color-text-maxcontrast);
	max-width: 360px;
}

.phrase-categories__muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.phrase-categories__actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}
</style>
