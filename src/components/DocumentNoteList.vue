<template>
	<div class="opencase-doc-notes">
		<div class="opencase-doc-notes__header">
			<NcButton v-if="canWrite" type="primary" @click="$emit('new')">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Ny note') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="notes.length === 0"
			:title="t('opencase', 'Ingen noter')">
			<template #icon>
				<NoteTextIcon :size="48" />
			</template>
		</NcEmptyContent>

		<div v-else class="opencase-doc-notes__list">
			<div v-for="note in notes"
				:key="note.id"
				class="opencase-doc-notes__item">
				<div class="opencase-doc-notes__icon">
					<NoteTextIcon :size="20" />
				</div>

				<div class="opencase-doc-notes__info">
					<span class="opencase-doc-notes__title">{{ note.title }}</span>
					<span class="opencase-doc-notes__meta">
						{{ note.created_by }} · {{ formatDate(note.created_at) }}
					</span>
				</div>

				<div class="opencase-doc-notes__actions">
					<NcButton :aria-label="t('opencase', 'Rediger note')"
						type="tertiary"
						@click="$emit('edit', note)">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
					</NcButton>
					<NcButton :aria-label="t('opencase', 'Slet note')"
						type="tertiary"
						@click="$emit('delete', note)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import NoteTextIcon from 'vue-material-design-icons/NoteText.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'DocumentNoteList',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PlusIcon,
		NoteTextIcon,
		PencilIcon,
		DeleteIcon,
	},

	props: {
		notes: {
			type: Array,
			required: true,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		canWrite: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['new', 'edit', 'delete'],

	methods: {
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.opencase-doc-notes__header {
	margin-bottom: 16px;
}

.opencase-doc-notes__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-doc-notes__item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;
	border-radius: 8px;
	transition: background 0.15s;
}

.opencase-doc-notes__item:hover {
	background: var(--color-background-hover);
}

.opencase-doc-notes__icon {
	color: var(--color-text-lighter);
	flex-shrink: 0;
}

.opencase-doc-notes__info {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-doc-notes__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.opencase-doc-notes__meta {
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.opencase-doc-notes__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}
</style>
