<template>
	<div class="tps">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="phrases.length === 0" class="tps__muted">
			{{ t('opencase', 'Skabelonen har ingen fraser, der kan bruges i denne sag.') }}
		</p>
		<ul v-else class="tps__phrases">
			<li v-for="phrase in phrases"
				:key="phrase.phrase_id"
				class="tps__phrase"
				:class="{ 'tps__phrase--disabled': phrase.is_required }">
				<label class="tps__phrase-label">
					<input type="checkbox"
						:checked="isChecked(phrase)"
						:disabled="phrase.is_required"
						@change="toggle(phrase, $event.target.checked)">
					<span class="tps__phrase-text">
						<span class="tps__phrase-title">
							{{ phrase.title }}
							<span v-if="phrase.is_required" class="tps__badge tps__badge--required">
								{{ t('opencase', 'Obligatorisk') }}
							</span>
							<span v-else-if="phrase.is_default" class="tps__badge">
								{{ t('opencase', 'Forudvalgt') }}
							</span>
							<span v-if="!phrase.has_content" class="tps__warning">
								{{ t('opencase', 'Intet indhold – indsættes ikke') }}
							</span>
						</span>
						<span v-if="phrase.description" class="tps__muted tps__phrase-desc">{{ phrase.description }}</span>
					</span>
				</label>
			</li>
		</ul>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

/**
 * Choose which of a template's phrases are merged into {{fraser.indhold}}
 * when a file is created from it: required phrases are always included and
 * cannot be unticked, pre-selected ones start ticked, optional ones can be
 * ticked. Only phrases usable in a letter for the case are offered: active,
 * with scopes (case type, organisation, KLE) matching the case — the server
 * merges no others (PhraseService::usableForCase()).
 *
 * v-model: the selected phrase ids (required ones included). It is set to the
 * template's defaults whenever the phrases of a (new) template are loaded.
 * Emits "loading" (boolean) while the phrases load.
 */
export default {
	name: 'TemplatePhraseSelector',

	components: { NcLoadingIcon },

	props: {
		templateId: { type: Number, required: true },
		modelValue: { type: Array, default: null },
		/** The case the letter is for — give one of documentId / caseId */
		documentId: { type: Number, default: null },
		caseId: { type: Number, default: null },
	},

	emits: ['update:modelValue', 'loading'],

	data() {
		return {
			loading: false,
			phrases: [],
		}
	},

	watch: {
		templateId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
		loading(value) {
			this.$emit('loading', value)
		},
	},

	methods: {
		async load() {
			const templateId = this.templateId
			this.phrases = []
			this.loading = true
			try {
				const context = this.documentId ? { document_id: this.documentId } : (this.caseId ? { case_id: this.caseId } : {})
				const phrases = (await api.getTemplatePhrases(templateId, context)).filter(p => p.status === 'active')
				if (templateId !== this.templateId) return
				this.phrases = phrases
				this.$emit('update:modelValue', phrases.filter(p => p.is_required || p.is_default).map(p => p.phrase_id))
			} catch (e) {
				showError(t('opencase', 'Kunne ikke hente skabelonens fraser'))
			} finally {
				if (templateId === this.templateId) {
					this.loading = false
				}
			}
		},

		isChecked(phrase) {
			return phrase.is_required || (this.modelValue ?? []).includes(phrase.phrase_id)
		},

		toggle(phrase, checked) {
			const ids = (this.modelValue ?? []).filter(id => id !== phrase.phrase_id)
			if (checked) {
				ids.push(phrase.phrase_id)
			}
			// Keep the template's phrase order
			const order = this.phrases.map(p => p.phrase_id)
			this.$emit('update:modelValue', ids.sort((a, b) => order.indexOf(a) - order.indexOf(b)))
		},
	},
}
</script>

<style scoped>
.tps__muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.tps__phrases {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	max-height: 320px;
	overflow-y: auto;
}

.tps__phrase + .tps__phrase {
	border-top: 1px solid var(--color-border);
}

.tps__phrase-label {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 12px;
	cursor: pointer;
}

.tps__phrase-label input {
	margin-top: 3px;
}

.tps__phrase--disabled .tps__phrase-label {
	cursor: default;
}

.tps__phrase-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.tps__warning {
	color: var(--color-warning-text);
	font-size: 0.85em;
	margin-left: 6px;
}

.tps__phrase-title {
	font-weight: 500;
	overflow-wrap: anywhere;
}

.tps__phrase-desc {
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	line-clamp: 2;
	overflow: hidden;
	overflow-wrap: anywhere;
}

/* Badge style as CaseStatusBadge */
.tps__badge {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	background: #e3f2fd;
	color: #1565c0;
}

.tps__badge--required {
	background: #fff3e0;
	color: #e65100;
}
</style>
