<template>
	<NcDialog :name="dialogTitle"
		@update:open="v => !v && $emit('close')">
		<div class="wf-action">
			<p class="wf-action__desc">{{ dialogDescription }}</p>

			<div v-if="showComment" class="wf-action__field">
				<label>{{ commentLabel }}</label>
				<textarea v-model="comment"
					class="wf-action__textarea"
					:placeholder="commentPlaceholder"
					rows="4" />
			</div>

			<p v-if="error" class="wf-action__error">{{ error }}</p>
		</div>

		<template #actions>
			<NcButton v-if="workflowType === 'review'"
				:disabled="saving"
				type="primary"
				@click="act('reviewed')">
				{{ saving ? t('opencase', 'Gemmer...') : t('opencase', 'Markér som gennemset') }}
			</NcButton>
			<template v-else-if="rejecting">
				<NcButton :disabled="saving"
					type="tertiary"
					@click="rejecting = false">
					{{ t('opencase', 'Tilbage') }}
				</NcButton>
				<NcButton :disabled="saving"
					type="error"
					@click="act('rejected')">
					{{ saving ? t('opencase', 'Gemmer...') : t('opencase', 'Bekræft afvisning') }}
				</NcButton>
			</template>
			<template v-else>
				<NcButton :disabled="saving"
					type="primary"
					@click="act('approved')">
					{{ saving ? t('opencase', 'Gemmer...') : t('opencase', 'Godkend') }}
				</NcButton>
				<NcButton :disabled="saving"
					type="error"
					@click="startReject">
					{{ t('opencase', 'Afvis') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import api from '../services/api.js'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'WorkflowActionDialog',
	components: { NcDialog, NcButton },

	props: {
		workflowId:   { type: [String, Number], required: true },
		workflowType: { type: String, required: true },
	},

	emits: ['close', 'acted'],

	data() {
		return {
			comment: '',
			rejecting: false,
			saving: false,
			error: null,
		}
	},

	computed: {
		dialogTitle() {
			return this.workflowType === 'review'
				? t('opencase', 'Gennemse dokument')
				: t('opencase', 'Godkend eller afvis dokument')
		},

		dialogDescription() {
			return this.workflowType === 'review'
				? t('opencase', 'Bekræft at du har gennemset dokumentet. Du kan tilføje en kommentar.')
				: this.rejecting
					? t('opencase', 'Du er ved at afvise dette dokument. Angiv en begrundelse.')
					: t('opencase', 'Godkend dokumentet eller afvis det med en begrundelse.')
		},

		showComment() {
			return this.workflowType === 'review' || this.rejecting
		},

		commentLabel() {
			return this.workflowType === 'review'
				? t('opencase', 'Kommentar (valgfri)')
				: t('opencase', 'Begrundelse for afvisning')
		},

		commentPlaceholder() {
			return this.workflowType === 'review'
				? t('opencase', 'Skriv en kommentar...')
				: t('opencase', 'Angiv årsagen til afvisning...')
		},
	},

	methods: {
		startReject() {
			this.rejecting = true
		},

		async act(action) {
			this.error = null
			this.saving = true
			try {
				const step = await api.submitWorkflowAction(this.workflowId, {
					action,
					comment: this.comment || null,
				})
				this.$emit('acted', step)
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.message || e.message
				showError(this.error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.wf-action {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
}

.wf-action__desc {
	color: var(--color-text-lighter);
	font-size: 0.95em;
}

.wf-action__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.wf-action__field label {
	font-weight: 600;
	font-size: 0.9em;
}

.wf-action__textarea {
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	padding: 8px 10px;
	font-size: 1em;
	background: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
	font-family: inherit;
	width: 100%;
}

.wf-action__error {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
