<template>
	<NcDialog :name="t('opencase', 'Journaliser på eksisterende sag')"
		@close="$emit('close')">
		<div class="opencase-move-to-case">
			<p class="opencase-move-to-case__intro">
				{{ t('opencase', 'Angiv sagsnummeret på den sag, du vil flytte dokumentet til.') }}
			</p>

			<NcTextField v-model="caseNumber"
				:label="t('opencase', 'Sagsnummer')"
				:placeholder="t('opencase', 'F.eks. 2026-00004')"
				:disabled="saving"
				@keydown.enter.prevent="confirm" />

			<p v-if="errorMessage" class="opencase-move-to-case__error">
				{{ errorMessage }}
			</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!caseNumber.trim() || saving"
				@click="confirm">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('opencase', 'Journaliser') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import api from '../services/api.js'

export default {
	name: 'MoveToCaseDialog',

	components: { NcDialog, NcButton, NcTextField, NcLoadingIcon },

	props: {
		documentId: { type: [String, Number], required: true },
	},

	emits: ['close', 'moved'],

	data() {
		return {
			caseNumber: '',
			saving: false,
			errorMessage: null,
		}
	},

	methods: {
		async confirm() {
			const cn = this.caseNumber.trim()
			if (!cn || this.saving) return
			this.saving = true
			this.errorMessage = null
			try {
				const doc = await api.moveDocumentToCase(this.documentId, cn)
				this.$emit('moved', doc)
			} catch (err) {
				this.errorMessage = err?.response?.data?.ocs?.data?.error
					|| err?.response?.data?.error
					|| t('opencase', 'Sagen blev ikke fundet eller du har ikke adgang.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-move-to-case {
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.opencase-move-to-case__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.opencase-move-to-case__error {
	margin: 0;
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
