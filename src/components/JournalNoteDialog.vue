<template>
	<NcDialog :name="dialogTitle"
		size="large"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-journal-note-dialog">
			<div class="opencase-journal-note-dialog__field">
				<label for="jn-title">{{ t('opencase', 'Titel') }} *</label>
				<NcTextField id="jn-title"
					v-model="form.title"
					:placeholder="t('opencase', 'Titel på journalnotat')" />
			</div>

			<div class="opencase-journal-note-dialog__field">
				<label>{{ t('opencase', 'Tekst') }}</label>
				<div class="opencase-journal-note-dialog__editor">
					<div class="opencase-journal-note-dialog__toolbar">
						<button type="button"
							:class="{ active: editor && editor.isActive('bold') }"
							:title="t('opencase', 'Fed')"
							@click="editor && editor.chain().focus().toggleBold().run()">
							<FormatBoldIcon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('italic') }"
							:title="t('opencase', 'Kursiv')"
							@click="editor && editor.chain().focus().toggleItalic().run()">
							<FormatItalicIcon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('underline') }"
							:title="t('opencase', 'Understreget')"
							@click="editor && editor.chain().focus().toggleUnderline().run()">
							<FormatUnderlineIcon :size="18" />
						</button>
						<span class="opencase-journal-note-dialog__toolbar-sep" />
						<button type="button"
							:class="{ active: editor && editor.isActive('heading', { level: 1 }) }"
							:title="t('opencase', 'Overskrift 1')"
							@click="editor && editor.chain().focus().toggleHeading({ level: 1 }).run()">
							<FormatHeader1Icon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('heading', { level: 2 }) }"
							:title="t('opencase', 'Overskrift 2')"
							@click="editor && editor.chain().focus().toggleHeading({ level: 2 }).run()">
							<FormatHeader2Icon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('heading', { level: 3 }) }"
							:title="t('opencase', 'Overskrift 3')"
							@click="editor && editor.chain().focus().toggleHeading({ level: 3 }).run()">
							<FormatHeader3Icon :size="18" />
						</button>
						<span class="opencase-journal-note-dialog__toolbar-sep" />
						<button type="button"
							:class="{ active: editor && editor.isActive('bulletList') }"
							:title="t('opencase', 'Punktliste')"
							@click="editor && editor.chain().focus().toggleBulletList().run()">
							<FormatListBulletedIcon :size="18" />
						</button>
						<button type="button"
							:class="{ active: editor && editor.isActive('orderedList') }"
							:title="t('opencase', 'Nummereret liste')"
							@click="editor && editor.chain().focus().toggleOrderedList().run()">
							<FormatListNumberedIcon :size="18" />
						</button>
						<span class="opencase-journal-note-dialog__toolbar-sep" />
						<button type="button"
							:class="{ active: editor && editor.isActive('blockquote') }"
							:title="t('opencase', 'Citat')"
							@click="editor && editor.chain().focus().toggleBlockquote().run()">
							<FormatQuoteIcon :size="18" />
						</button>
					</div>
					<editor-content class="opencase-journal-note-dialog__editor-content" :editor="editor" />
				</div>
			</div>

			<div class="opencase-journal-note-dialog__field opencase-journal-note-dialog__field--inline">
				<NcCheckboxRadioSwitch v-model="form.is_locked">
					{{ t('opencase', 'Lås journalnotat') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !form.title.trim()" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencase', 'Gem') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'

import FormatBoldIcon from 'vue-material-design-icons/FormatBold.vue'
import FormatItalicIcon from 'vue-material-design-icons/FormatItalic.vue'
import FormatUnderlineIcon from 'vue-material-design-icons/FormatUnderline.vue'
import FormatHeader1Icon from 'vue-material-design-icons/FormatHeader1.vue'
import FormatHeader2Icon from 'vue-material-design-icons/FormatHeader2.vue'
import FormatHeader3Icon from 'vue-material-design-icons/FormatHeader3.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumberedIcon from 'vue-material-design-icons/FormatListNumbered.vue'
import FormatQuoteIcon from 'vue-material-design-icons/FormatQuoteOpen.vue'

import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'

import api from '../services/api.js'

export default {
	name: 'JournalNoteDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		EditorContent,
		FormatBoldIcon,
		FormatItalicIcon,
		FormatUnderlineIcon,
		FormatHeader1Icon,
		FormatHeader2Icon,
		FormatHeader3Icon,
		FormatListBulletedIcon,
		FormatListNumberedIcon,
		FormatQuoteIcon,
	},

	props: {
		caseId: {
			type: [String, Number],
			required: true,
		},
		note: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			editor: null,
			form: {
				title: this.note?.title ?? '',
				is_locked: this.note?.is_locked ?? false,
			},
			saving: false,
		}
	},

	computed: {
		dialogTitle() {
			return this.note
				? t('opencase', 'Rediger journalnotat')
				: t('opencase', 'Ny journalnotat')
		},
	},

	mounted() {
		this.editor = new Editor({
			extensions: [StarterKit, Underline],
			content: this.note?.text ?? '',
		})
	},

	beforeUnmount() {
		this.editor?.destroy()
	},

	methods: {
		async save() {
			const title = this.form.title.trim()
			if (!title) return

			this.saving = true
			try {
				const payload = {
					title,
					text: this.editor.getHTML(),
					is_locked: this.form.is_locked,
				}

				let saved
				if (this.note) {
					saved = await api.updateJournalNote(this.note.id, payload)
					showSuccess(t('opencase', 'Journalnotat opdateret'))
				} else {
					saved = await api.createJournalNote(this.caseId, payload)
					showSuccess(t('opencase', 'Journalnotat oprettet'))
				}

				this.$emit('saved', saved)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme journalnotat'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-journal-note-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.opencase-journal-note-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.opencase-journal-note-dialog__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-journal-note-dialog__field--inline {
	flex-direction: row;
	align-items: center;
}

.opencase-journal-note-dialog__editor {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
}

.opencase-journal-note-dialog__editor:focus-within {
	border-color: var(--color-primary-element);
}

.opencase-journal-note-dialog__toolbar {
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
	flex-wrap: wrap;
}

.opencase-journal-note-dialog__toolbar button {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 30px;
	height: 30px;
	background: none;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	color: var(--color-text-lighter);
	transition: background 0.15s, color 0.15s;
}

.opencase-journal-note-dialog__toolbar button:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.opencase-journal-note-dialog__toolbar button.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.opencase-journal-note-dialog__toolbar-sep {
	width: 1px;
	height: 20px;
	background: var(--color-border);
	margin: 0 4px;
}

.opencase-journal-note-dialog__editor-content {
	padding: 12px;
	min-height: 300px;
	width: 100%;
	box-sizing: border-box;
}
</style>

<style>
/* Unscoped: TipTap editor needs global styles */
.opencase-journal-note-dialog__editor-content > div {
	width: 100%;
}

.opencase-journal-note-dialog__editor-content .ProseMirror {
	outline: none;
	min-height: 280px;
	width: 100%;
	box-sizing: border-box;
	overflow-wrap: break-word;
	word-break: break-word;
	font-size: 0.95em;
	line-height: 1.6;
	color: var(--color-main-text);
}

.opencase-journal-note-dialog__editor-content .ProseMirror p {
	margin: 0 0 8px 0;
}

.opencase-journal-note-dialog__editor-content .ProseMirror h1 {
	font-size: 1.5em;
	font-weight: 700;
	margin: 14px 0 6px 0;
}

.opencase-journal-note-dialog__editor-content .ProseMirror h2 {
	font-size: 1.2em;
	font-weight: 700;
	margin: 12px 0 6px 0;
}

.opencase-journal-note-dialog__editor-content .ProseMirror h3 {
	font-size: 1.05em;
	font-weight: 700;
	margin: 10px 0 4px 0;
}

.opencase-journal-note-dialog__editor-content .ProseMirror ul,
.opencase-journal-note-dialog__editor-content .ProseMirror ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.opencase-journal-note-dialog__editor-content .ProseMirror blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}

.opencase-journal-note-dialog__editor-content .ProseMirror p.is-editor-empty:first-child::before {
	content: attr(data-placeholder);
	color: var(--color-text-lighter);
	pointer-events: none;
	float: left;
	height: 0;
}
</style>
