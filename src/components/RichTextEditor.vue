<template>
	<div class="opencase-rte">
		<div class="opencase-rte__toolbar">
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
			<span class="opencase-rte__toolbar-sep" />
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
			<span class="opencase-rte__toolbar-sep" />
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
			<span class="opencase-rte__toolbar-sep" />
			<button type="button"
				:class="{ active: editor && editor.isActive('blockquote') }"
				:title="t('opencase', 'Citat')"
				@click="editor && editor.chain().focus().toggleBlockquote().run()">
				<FormatQuoteIcon :size="18" />
			</button>
		</div>
		<EditorContent class="opencase-rte__content" :editor="editor" />
	</div>
</template>

<script>
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

/**
 * TipTap rich-text editor with the same toolbar as the case summary
 * (Sagsresume) editor. Read the result with getHtml() via a template ref.
 */
export default {
	name: 'RichTextEditor',
	components: {
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
		/** Initial HTML content */
		content: { type: String, default: '' },
	},
	data() {
		return {
			editor: null,
		}
	},
	mounted() {
		this.editor = new Editor({
			extensions: [StarterKit, Underline],
			content: this.content || '',
		})
	},
	beforeUnmount() {
		this.editor?.destroy()
	},
	methods: {
		/**
		 * Editor HTML, or '' when empty — TipTap serialises an empty
		 * document as '<p></p>', which the API would otherwise store.
		 */
		getHtml() {
			if (!this.editor || this.editor.isEmpty) return ''
			return this.editor.getHTML()
		},
	},
}
</script>

<style scoped>
.opencase-rte {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
}

.opencase-rte:focus-within {
	border-color: var(--color-primary-element);
}

.opencase-rte__toolbar {
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
	flex-wrap: wrap;
}

.opencase-rte__toolbar button {
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

.opencase-rte__toolbar button:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.opencase-rte__toolbar button.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.opencase-rte__toolbar-sep {
	width: 1px;
	height: 20px;
	background: var(--color-border);
	margin: 0 4px;
}

.opencase-rte__content {
	padding: 12px;
	min-height: 180px;
	width: 100%;
	box-sizing: border-box;
}
</style>

<style>
/* Unscoped: TipTap renders the ProseMirror tree outside scoped-style reach */
.opencase-rte__content > div {
	width: 100%;
}

.opencase-rte__content .ProseMirror {
	outline: none;
	min-height: 160px;
	width: 100%;
	box-sizing: border-box;
	overflow-wrap: break-word;
	word-break: break-word;
	font-size: 0.95em;
	line-height: 1.6;
	color: var(--color-main-text);
}

.opencase-rte__content .ProseMirror p {
	margin: 0 0 8px 0;
}

.opencase-rte__content .ProseMirror h1 {
	font-size: 1.5em;
	font-weight: 700;
	margin: 14px 0 6px 0;
}

.opencase-rte__content .ProseMirror h2 {
	font-size: 1.2em;
	font-weight: 700;
	margin: 12px 0 6px 0;
}

.opencase-rte__content .ProseMirror h3 {
	font-size: 1.05em;
	font-weight: 700;
	margin: 10px 0 4px 0;
}

.opencase-rte__content .ProseMirror ul,
.opencase-rte__content .ProseMirror ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.opencase-rte__content .ProseMirror blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}
</style>
