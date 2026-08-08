<template>
	<NcDialog :name="t('opencase', 'Journalnotat')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-journal-note-view">
			<div class="opencase-journal-note-view__meta">
				<span class="opencase-journal-note-view__label">{{ t('opencase', 'Titel') }}</span>
				<h3 class="opencase-journal-note-view__title">{{ note.title }}</h3>
			</div>

			<div class="opencase-journal-note-view__meta">
				<span class="opencase-journal-note-view__label">{{ t('opencase', 'Oprettet af') }}</span>
				<span>{{ note.created_by }} · {{ formatDate(note.created_at) }}</span>
			</div>

			<div v-if="note.updated_at" class="opencase-journal-note-view__meta">
				<span class="opencase-journal-note-view__label">{{ t('opencase', 'Sidst opdateret') }}</span>
				<span>{{ formatDate(note.updated_at) }}</span>
			</div>

			<div class="opencase-journal-note-view__locked-badge">
				<LockIcon :size="16" />
				{{ t('opencase', 'Låst') }}
			</div>

			<div class="opencase-journal-note-view__divider" />

			<div class="opencase-journal-note-view__text"
				v-html="note.text || ''" />
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Luk') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import LockIcon from 'vue-material-design-icons/Lock.vue'

export default {
	name: 'JournalNoteViewDialog',

	components: {
		NcDialog,
		NcButton,
		LockIcon,
	},

	props: {
		note: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	methods: {
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('da-DK', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.opencase-journal-note-view {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
	min-width: 480px;
}

.opencase-journal-note-view__meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.opencase-journal-note-view__label {
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-lighter);
}

.opencase-journal-note-view__title {
	margin: 0;
	font-size: 1.2em;
	font-weight: 700;
}

.opencase-journal-note-view__locked-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 3px 10px;
	background: var(--color-warning-soft, #fff3cd);
	color: var(--color-warning, #856404);
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 600;
	align-self: flex-start;
}

.opencase-journal-note-view__divider {
	height: 1px;
	background: var(--color-border);
	margin: 4px 0;
}

.opencase-journal-note-view__text {
	font-size: 0.95em;
	line-height: 1.6;
	color: var(--color-main-text);
}
</style>

<style>
/* Unscoped: rendered HTML content styles */
.opencase-journal-note-view__text p {
	margin: 0 0 8px 0;
}

.opencase-journal-note-view__text h1 {
	font-size: 1.5em;
	font-weight: 700;
	margin: 14px 0 6px 0;
}

.opencase-journal-note-view__text h2 {
	font-size: 1.2em;
	font-weight: 700;
	margin: 12px 0 6px 0;
}

.opencase-journal-note-view__text h3 {
	font-size: 1.05em;
	font-weight: 700;
	margin: 10px 0 4px 0;
}

.opencase-journal-note-view__text ul,
.opencase-journal-note-view__text ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.opencase-journal-note-view__text blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}
</style>
