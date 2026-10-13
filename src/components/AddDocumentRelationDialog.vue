<template>
	<NcDialog :name="title"
		size="normal"
		@close="$emit('close')">
		<div class="opencase-add-attachment">
			<div class="opencase-add-attachment__search">
				<NcTextField v-model="caseNumber"
					:label="t('opencase', 'Sagsnummer')"
					:placeholder="t('opencase', 'F.eks. 2026-00004')"
					:disabled="saving"
					@keydown.enter.prevent="search" />
				<NcButton :disabled="!caseNumber.trim() || loading || saving" @click="search">
					<template #icon>
						<MagnifyIcon :size="20" />
					</template>
					{{ t('opencase', 'Søg') }}
				</NcButton>
			</div>

			<p v-if="errorMessage" class="opencase-add-attachment__error">
				{{ errorMessage }}
			</p>

			<p v-if="foundCase" class="opencase-add-attachment__case">
				{{ foundCase.case_number }} – {{ foundCase.title }}
			</p>

			<NcLoadingIcon v-if="loading" :size="32" />

			<NcEmptyContent v-else-if="foundCase && documents.length === 0"
				:title="t('opencase', 'Ingen dokumenter')">
				<template #icon>
					<FileDocumentOutlineIcon :size="48" />
				</template>
			</NcEmptyContent>

			<template v-else-if="documents.length > 0">
				<div class="opencase-add-attachment__list">
					<label v-for="doc in documents"
						:key="doc.id"
						class="opencase-add-attachment__item"
						:class="{ 'opencase-add-attachment__item--disabled': doc.already_related }">
						<input type="checkbox"
							:checked="doc.already_related || selected.has(doc.id)"
							:disabled="doc.already_related || saving"
							@change="toggle(doc.id)">
						<span class="opencase-add-attachment__number">{{ doc.document_number || '–' }}</span>
						<span class="opencase-add-attachment__title">{{ doc.title }}</span>
						<span v-if="doc.already_related" class="opencase-add-attachment__hint">
							{{ alreadyRelatedLabel }}
						</span>
					</label>
				</div>

				<div v-if="total > limit" class="opencase-add-attachment__pagination">
					<NcButton :disabled="offset === 0 || loading" @click="goTo(offset - limit)">
						{{ t('opencase', 'Forrige') }}
					</NcButton>
					<span>
						{{ t('opencase', '{start}–{end} af {total}', {
							start: offset + 1,
							end: Math.min(offset + limit, total),
							total,
						}) }}
					</span>
					<NcButton :disabled="offset + limit >= total || loading" @click="goTo(offset + limit)">
						{{ t('opencase', 'Næste') }}
					</NcButton>
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="selected.size === 0 || saving"
				@click="confirm">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

import api from '../services/api.js'

// opencase_documentrelationtype ids
export const RELATION_TYPE_ATTACHMENT = 1
export const RELATION_TYPE_RELATION = 4

export default {
	name: 'AddDocumentRelationDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		MagnifyIcon,
		FileDocumentOutlineIcon,
	},

	props: {
		documentId: { type: [String, Number], required: true },
		initialCaseNumber: { type: String, default: '' },
		relationTypeId: { type: Number, default: RELATION_TYPE_ATTACHMENT },
	},

	emits: ['close', 'added'],

	data() {
		return {
			caseNumber: this.initialCaseNumber,
			// Case number the current result list belongs to — paging keeps
			// using it even if the text field has been edited since.
			searchedCaseNumber: '',
			foundCase: null,
			documents: [],
			total: 0,
			offset: 0,
			limit: 10,
			// Selection survives paging and new case searches, so documents
			// from several cases can be added in one go.
			selected: new Set(),
			loading: false,
			saving: false,
			errorMessage: null,
		}
	},

	computed: {
		isAttachment() {
			return this.relationTypeId === RELATION_TYPE_ATTACHMENT
		},
		title() {
			return this.isAttachment ? t('opencase', 'Tilføj bilag') : t('opencase', 'Tilføj relation')
		},
		alreadyRelatedLabel() {
			return this.isAttachment ? t('opencase', 'Allerede bilag') : t('opencase', 'Allerede relateret')
		},
		confirmLabel() {
			if (this.selected.size === 0) return this.title
			return this.isAttachment
				? t('opencase', 'Tilføj {count} bilag', { count: this.selected.size })
				: t('opencase', 'Tilføj {count} relationer', { count: this.selected.size })
		},
	},

	mounted() {
		if (this.caseNumber.trim()) this.search()
	},

	methods: {
		search() {
			const cn = this.caseNumber.trim()
			if (!cn) return
			this.searchedCaseNumber = cn
			this.goTo(0)
		},

		async goTo(offset) {
			this.loading = true
			this.errorMessage = null
			try {
				const data = await api.getDocumentRelationCandidates(this.documentId, {
					case_number: this.searchedCaseNumber,
					documentrelationtype_id: this.relationTypeId,
					limit: this.limit,
					offset,
				})
				this.foundCase = data.case
				this.documents = data.documents
				this.total = data.total
				this.offset = offset
			} catch (err) {
				this.foundCase = null
				this.documents = []
				this.total = 0
				this.errorMessage = t('opencase', 'Sagen blev ikke fundet eller du har ikke adgang.')
			} finally {
				this.loading = false
			}
		},

		toggle(id) {
			const next = new Set(this.selected)
			next.has(id) ? next.delete(id) : next.add(id)
			this.selected = next
		},

		async confirm() {
			if (this.selected.size === 0 || this.saving) return
			this.saving = true
			this.errorMessage = null
			try {
				await api.createDocumentRelations(this.documentId, [...this.selected], this.relationTypeId)
				this.$emit('added')
			} catch (err) {
				this.errorMessage = err?.response?.data?.ocs?.data?.error
					|| err?.response?.data?.error
					|| (this.isAttachment
						? t('opencase', 'Bilag kunne ikke tilføjes')
						: t('opencase', 'Relationer kunne ikke tilføjes'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-add-attachment {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
	min-height: 200px;
}

.opencase-add-attachment__search {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.opencase-add-attachment__error {
	color: var(--color-error-text);
}

.opencase-add-attachment__case {
	font-weight: 600;
}

.opencase-add-attachment__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-add-attachment__item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 10px;
	border-radius: 8px;
	cursor: pointer;
}

.opencase-add-attachment__item:hover {
	background: var(--color-background-hover);
}

.opencase-add-attachment__item--disabled {
	cursor: default;
	color: var(--color-text-maxcontrast);
}

.opencase-add-attachment__number {
	font-family: monospace;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.opencase-add-attachment__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.opencase-add-attachment__hint {
	font-size: 0.85em;
	font-style: italic;
	flex-shrink: 0;
}

.opencase-add-attachment__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
}
</style>
