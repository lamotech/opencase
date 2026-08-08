<template>
	<div class="oc-doc-table">
		<!-- Search bar -->
		<div class="oc-doc-table__search">
			<NcTextField v-model="query"
				:label="t('opencase', 'Søg i sagens dokumenter')"
				:placeholder="t('opencase', 'Søg...')"
				class="oc-doc-table__search-input"
				@update:modelValue="onSearch" />
			<NcButton :disabled="searching" @click="onSearch">
				<template #icon>
					<NcLoadingIcon v-if="searching" :size="20" />
					<MagnifyIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Søg') }}
			</NcButton>
			<NcButton v-if="unadded.length > 0" :disabled="addingAll" @click="addAll">
				<template #icon>
					<NcLoadingIcon v-if="addingAll" :size="20" />
					<PlusIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Tilføj alle') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="searching" :size="32" class="oc-doc-table__loader" />

		<NcEmptyContent v-else-if="candidates.length === 0"
			:title="t('opencase', 'Ingen dokumenter fundet')">
			<template #icon>
				<FileSearchOutlineIcon :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="oc-doc-table__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Dokument') }}</th>
					<th>{{ t('opencase', 'Type') }}</th>
					<th>{{ t('opencase', 'Dato') }}</th>
					<th>{{ t('opencase', 'Beslutning') }}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="c in candidates" :key="c.source_type + ':' + c.source_id"
					:class="{ 'oc-doc-table__row--added': c.already_added }">
					<td>{{ c.title }}</td>
					<td>{{ c.document_type ?? '—' }}</td>
					<td>{{ c.document_date ?? '—' }}</td>
					<td>
						<span v-if="c.already_added">
							<AccessDecisionChip :decision="c.decision" />
						</span>
					</td>
					<td class="oc-doc-table__actions">
						<NcButton v-if="!c.already_added"
							size="small"
							@click="addItem(c)">
							<template #icon>
								<PlusIcon :size="16" />
							</template>
							{{ t('opencase', 'Tilføj') }}
						</NcButton>
						<NcButton v-else
							size="small"
							type="tertiary"
							@click="$emit('open-item', c.item_id)">
							{{ t('opencase', 'Åbn') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import FileSearchOutlineIcon from 'vue-material-design-icons/FileSearchOutline.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

const DECISION_LABELS = {
	pending:       { label: 'Afventer', class: 'pending' },
	include:       { label: 'Medtaget', class: 'include' },
	exclude:       { label: 'Udeladt',  class: 'exclude' },
	partly_include: { label: 'Delvist', class: 'partial' },
}

const AccessDecisionChip = {
	name: 'AccessDecisionChip',
	props: { decision: String },
	template: `<span :class="['oc-dec-chip', 'oc-dec-chip--' + (DECISION_LABELS[decision]?.class ?? 'pending')]">{{ t('opencase', DECISION_LABELS[decision]?.label ?? decision) }}</span>`,
	data: () => ({ DECISION_LABELS }),
}

export default {
	name: 'AccessDocumentTable',
	components: { NcTextField, NcButton, NcLoadingIcon, NcEmptyContent, MagnifyIcon, PlusIcon, FileSearchOutlineIcon, AccessDecisionChip },
	props: {
		requestId: { type: Number, required: true },
	},
	emits: ['item-added', 'open-item'],
	data() {
		return {
			query: '',
			candidates: [],
			searching: false,
			addingAll: false,
		}
	},
	computed: {
		unadded() {
			return this.candidates.filter(c => !c.already_added)
		},
	},
	mounted() {
		this.onSearch()
	},
	methods: {
		async onSearch() {
			this.searching = true
			try {
				const result = await api.searchAccessCandidates(this.requestId, { q: this.query })
				this.candidates = result.candidates ?? []
			} catch {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},
		async addItem(candidate) {
			try {
				const item = await api.addAccessItem(this.requestId, {
					source_type:   candidate.source_type,
					source_id:     candidate.source_id,
					title:         candidate.title,
					document_date: candidate.document_date,
					mime_type:     candidate.mime_type,
				})
				candidate.already_added = true
				candidate.decision      = 'pending'
				candidate.item_id       = item.id
				this.$emit('item-added', item)
			} catch {
				showError(t('opencase', 'Tilføjelse fejlede'))
			}
		},
		markRemoved(itemId) {
			const c = this.candidates.find(c => c.item_id === itemId)
			if (c) {
				c.already_added = false
				c.decision      = null
				c.item_id       = null
			}
		},
		async addAll() {
			this.addingAll = true
			try {
				for (const c of this.unadded) {
					await this.addItem(c)
				}
			} finally {
				this.addingAll = false
			}
		},
	},
}
</script>

<style scoped>
.oc-doc-table__search { display: flex; gap: 8px; margin-bottom: 16px; align-items: flex-end; }
.oc-doc-table__search-input { flex: 1; }
.oc-doc-table__loader { display: block; margin: 24px auto; }
.oc-doc-table__table { width: 100%; border-collapse: collapse; }
.oc-doc-table__table th,
.oc-doc-table__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.oc-doc-table__table th { font-weight: 600; background: var(--color-background-hover); }
.oc-doc-table__row--added { background: var(--color-background-hover); }
.oc-doc-table__actions { text-align: right; }
.oc-dec-chip { display: inline-block; padding: 1px 8px; border-radius: 8px; font-size: 0.78em; font-weight: 600; }
.oc-dec-chip--pending { background: #f5f5f5; color: #616161; }
.oc-dec-chip--include { background: #e8f5e9; color: #2e7d32; }
.oc-dec-chip--exclude { background: #ffebee; color: #b71c1c; }
.oc-dec-chip--partial { background: #fff8e1; color: #f57f17; }
</style>
