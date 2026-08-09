<template>
	<NcDialog :name="t('opencase', 'Send med email')"
		size="large"
		:close-on-click-outside="false"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-send-email-dialog">
			<NcLoadingIcon v-if="loading" :size="32" class="opencase-send-email-dialog__loading" />

			<template v-else>
				<div class="opencase-send-email-dialog__field opencase-send-email-dialog__field--inline">
					<label>{{ t('opencase', 'Fra') }}</label>
					<span class="opencase-send-email-dialog__from">
						{{ fromLabel }}
					</span>
				</div>

				<div class="opencase-send-email-dialog__field">
					<div class="opencase-send-email-dialog__label-row">
						<label>{{ t('opencase', 'Til') }} *</label>
						<button v-if="!showCcBcc"
							type="button"
							class="opencase-send-email-dialog__cc-bcc-toggle"
							@click="showCcBcc = true">
							{{ t('opencase', 'Cc/Bcc') }}
						</button>
					</div>
					<NcSelect v-model="to"
						:options="recipientOptions"
						:loading="recipientsLoading"
						:filterable="false"
						multiple
						taggable
						:create-option="createOption"
						label="label"
						:placeholder="t('opencase', 'Søg efter navn eller email')"
						@search="onSearch" />
				</div>

				<template v-if="showCcBcc">
					<div class="opencase-send-email-dialog__field">
						<label>{{ t('opencase', 'Cc') }}</label>
						<NcSelect v-model="cc"
							:options="recipientOptions"
							:loading="recipientsLoading"
							:filterable="false"
							multiple
							taggable
							:create-option="createOption"
							label="label"
							:placeholder="t('opencase', 'Søg efter navn eller email')"
							@search="onSearch" />
					</div>

					<div class="opencase-send-email-dialog__field">
						<label>{{ t('opencase', 'Bcc') }}</label>
						<NcSelect v-model="bcc"
							:options="recipientOptions"
							:loading="recipientsLoading"
							:filterable="false"
							multiple
							taggable
							:create-option="createOption"
							label="label"
							:placeholder="t('opencase', 'Søg efter navn eller email')"
							@search="onSearch" />
					</div>
				</template>

				<div class="opencase-send-email-dialog__field">
					<label>{{ t('opencase', 'Emne') }} *</label>
					<NcTextField v-model="subject"
						:placeholder="t('opencase', 'Emne')" />
				</div>

				<div class="opencase-send-email-dialog__field">
					<label>{{ t('opencase', 'Besked') }}</label>
					<div class="opencase-send-email-dialog__editor">
						<div class="opencase-send-email-dialog__toolbar">
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
							<span class="opencase-send-email-dialog__toolbar-sep" />
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
							<span class="opencase-send-email-dialog__toolbar-sep" />
							<button type="button"
								:class="{ active: editor && editor.isActive('blockquote') }"
								:title="t('opencase', 'Citat')"
								@click="editor && editor.chain().focus().toggleBlockquote().run()">
								<FormatQuoteIcon :size="18" />
							</button>
						</div>
						<editor-content class="opencase-send-email-dialog__editor-content" :editor="editor" />
					</div>
				</div>

				<div class="opencase-send-email-dialog__field">
					<NcCheckboxRadioSwitch v-model="saveAsDocument">
						{{ t('opencase', 'Gem som dokument på sagen') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="opencase-send-email-dialog__field">
					<label>{{ t('opencase', 'Vedhæftede filer') }}</label>
					<p v-if="attachments.length === 0" class="opencase-send-email-dialog__empty">
						{{ t('opencase', 'Ingen filer vedhæftet') }}
					</p>
					<ul v-else class="opencase-send-email-dialog__attachments">
						<li v-for="file in attachments" :key="file.id" class="opencase-send-email-dialog__attachment">
							<PaperclipIcon :size="16" />
							<span class="opencase-send-email-dialog__attachment-name">
								{{ file.virtual_filename || file.original_filename }}
							</span>
							<button type="button"
								class="opencase-send-email-dialog__attachment-remove"
								:title="t('opencase', 'Fjern vedhæftning')"
								@click="attachments = attachments.filter(f => f.id !== file.id)">
								<CloseIcon :size="16" />
							</button>
						</li>
					</ul>
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSend" @click="send">
				<template v-if="sending" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencase', 'Send') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { showError, showSuccess } from '@nextcloud/dialogs'

import FormatBoldIcon from 'vue-material-design-icons/FormatBold.vue'
import FormatItalicIcon from 'vue-material-design-icons/FormatItalic.vue'
import FormatUnderlineIcon from 'vue-material-design-icons/FormatUnderline.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumberedIcon from 'vue-material-design-icons/FormatListNumbered.vue'
import FormatQuoteIcon from 'vue-material-design-icons/FormatQuoteOpen.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'

import api from '../services/api.js'

export default {
	name: 'SendEmailDialog',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		EditorContent,
		FormatBoldIcon,
		FormatItalicIcon,
		FormatUnderlineIcon,
		FormatListBulletedIcon,
		FormatListNumberedIcon,
		FormatQuoteIcon,
		PaperclipIcon,
		CloseIcon,
	},

	props: {
		documentId: { type: [String, Number], required: true },
		fromAddress: { type: String, default: '' },
		fromLabelText: { type: String, default: '' },
	},

	emits: ['close', 'sent'],

	data() {
		return {
			loading: true,
			sending: false,
			editor: null,
			showCcBcc: false,
			to: [],
			cc: [],
			bcc: [],
			subject: '',
			saveAsDocument: true,
			attachments: [],
			recipientOptions: [],
			recipientsLoading: false,
			searchTimeout: null,
		}
	},

	computed: {
		fromLabel() {
			return this.fromLabelText && this.fromLabelText !== this.fromAddress
				? `${this.fromLabelText} <${this.fromAddress}>`
				: this.fromAddress
		},
		canSend() {
			return !this.sending && !this.loading && this.to.length > 0 && this.subject.trim() !== ''
		},
	},

	mounted() {
		this.editor = new Editor({
			extensions: [StarterKit, Underline],
			content: '',
		})
		this.load()
	},

	beforeUnmount() {
		this.editor?.destroy()
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.attachments = await api.getFilesByDocument(this.documentId)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse filer'))
			} finally {
				this.loading = false
			}
		},

		createOption(query) {
			const email = query.trim()
			return { label: email, name: email, email }
		},

		serializeRecipient(r) {
			return {
				name: r.name,
				email: r.email,
				contact_type: r.contact_type ?? null,
				user_id: r.user_id ?? null,
				cpr_cvr: r.cpr_cvr ?? null,
				streetname: r.streetname ?? null,
				housenumber: r.housenumber ?? null,
				floor: r.floor ?? null,
				door: r.door ?? null,
				zipcode: r.zipcode ?? null,
				zipdistrict: r.zipdistrict ?? null,
				phone: r.phone ?? null,
				has_address_protection: !!r.has_address_protection,
				pnumber: r.pnumber ?? null,
			}
		},

		onSearch(query) {
			clearTimeout(this.searchTimeout)
			if (!query || query.length < 2) return
			this.recipientsLoading = true
			this.searchTimeout = setTimeout(async () => {
				try {
					const results = await api.searchEmailRecipients(this.documentId, query)
					this.recipientOptions = results.map(r => ({
						...r,
						label: `${r.name} <${r.email}>`,
					}))
				} catch (e) {
					// silently ignore
				} finally {
					this.recipientsLoading = false
				}
			}, 300)
		},

		async send() {
			if (!this.canSend) return
			this.sending = true
			try {
				const payload = {
					to: this.to.map(this.serializeRecipient),
					cc: this.cc.map(this.serializeRecipient),
					bcc: this.bcc.map(this.serializeRecipient),
					subject: this.subject.trim(),
					body_html: this.editor.getHTML(),
					file_ids: this.attachments.map(f => f.id),
					save_as_document: this.saveAsDocument,
				}
				await api.sendDocumentEmail(this.documentId, payload)
				showSuccess(t('opencase', 'Email sendt'))
				this.$emit('sent')
				this.$emit('close')
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Kunne ikke sende email'))
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-send-email-dialog {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 8px 0;
}

.opencase-send-email-dialog__loading {
	margin: 40px auto;
}

.opencase-send-email-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.opencase-send-email-dialog__field--inline {
	flex-direction: row;
	align-items: center;
	gap: 10px;
}

.opencase-send-email-dialog__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-send-email-dialog__label-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.opencase-send-email-dialog__cc-bcc-toggle {
	background: none;
	border: none;
	padding: 0;
	color: var(--color-primary-element);
	font-size: 0.9em;
	font-weight: 600;
	cursor: pointer;
}

.opencase-send-email-dialog__cc-bcc-toggle:hover {
	text-decoration: underline;
}

.opencase-send-email-dialog__from {
	color: var(--color-main-text);
}

.opencase-send-email-dialog__empty {
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 0;
}

.opencase-send-email-dialog__attachments {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.opencase-send-email-dialog__attachment {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	border-radius: 6px;
	background: var(--color-background-dark);
}

.opencase-send-email-dialog__attachment-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.opencase-send-email-dialog__attachment-remove {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	background: none;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	color: var(--color-text-lighter);
}

.opencase-send-email-dialog__attachment-remove:hover {
	background: var(--color-background-hover);
	color: var(--color-error);
}

.opencase-send-email-dialog__editor {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: 6px;
	background: var(--color-main-background);
}

.opencase-send-email-dialog__editor:focus-within {
	border-color: var(--color-primary-element);
}

.opencase-send-email-dialog__toolbar {
	display: flex;
	align-items: center;
	gap: 2px;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-dark);
	flex-wrap: wrap;
}

.opencase-send-email-dialog__toolbar button {
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

.opencase-send-email-dialog__toolbar button:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.opencase-send-email-dialog__toolbar button.active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.opencase-send-email-dialog__toolbar-sep {
	width: 1px;
	height: 20px;
	background: var(--color-border);
	margin: 0 4px;
}

.opencase-send-email-dialog__editor-content {
	padding: 12px;
	min-height: 180px;
	width: 100%;
	box-sizing: border-box;
}
</style>

<style>
/* Unscoped: TipTap editor needs global styles */
.opencase-send-email-dialog__editor-content > div {
	width: 100%;
}

.opencase-send-email-dialog__editor-content .ProseMirror {
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

.opencase-send-email-dialog__editor-content .ProseMirror p {
	margin: 0 0 8px 0;
}

.opencase-send-email-dialog__editor-content .ProseMirror ul,
.opencase-send-email-dialog__editor-content .ProseMirror ol {
	padding-left: 20px;
	margin: 0 0 8px 0;
}

.opencase-send-email-dialog__editor-content .ProseMirror blockquote {
	border-left: 3px solid var(--color-border-maxcontrast);
	padding-left: 12px;
	margin: 0 0 8px 0;
	color: var(--color-text-lighter);
}
</style>
