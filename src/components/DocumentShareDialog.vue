<template>
	<NcModal v-if="show"
		class="opencase-share-modal"
		:name="t('opencase', 'Del dokument')"
		size="normal"
		@close="$emit('close')">
		<div class="doc-share-dialog">
			<h3 class="doc-share-dialog__title">
				{{ t('opencase', 'Del dokument') }}
			</h3>

			<!-- Add new share -->
			<div class="doc-share-dialog__add">
				<NcSelect
					v-model="selectedUser"
					:options="userOptions"
					:placeholder="t('opencase', 'Søg bruger...')"
					:loading="searching"
					:filterable="false"
					label="label"
					track-by="id"
					@search="searchUsers" />
				<div class="doc-share-dialog__add-options">
					<label v-if="!isReadOnly" class="doc-share-dialog__write-label">
						<input v-model="canWrite" type="checkbox" />
						{{ t('opencase', 'Kan redigere') }}
					</label>
					<NcButton :disabled="!selectedUser || saving" @click="addShare">
						{{ t('opencase', 'Del') }}
					</NcButton>
				</div>
			</div>

			<!-- Existing shares -->
			<div v-if="shares.length > 0" class="doc-share-dialog__list">
				<div v-for="share in shares"
					:key="share.shared_with"
					class="doc-share-dialog__share-row">
					<div class="doc-share-dialog__share-info">
						<span class="doc-share-dialog__user">{{ share.display_name || share.shared_with }}</span>
						<span class="doc-share-dialog__perms">
							{{ share.can_write ? t('opencase', 'Kan redigere') : t('opencase', 'Kan læse') }}
						</span>
						<span v-if="share.expires_at" class="doc-share-dialog__expires">
							· {{ t('opencase', 'Udløber') }} {{ formatDate(share.expires_at) }}
						</span>
					</div>
					<NcButton type="error"
						:title="t('opencase', 'Fjern adgang')"
						@click="removeShare(share.shared_with)">
						<template #icon>
							<DeleteIcon :size="18" />
						</template>
					</NcButton>
				</div>
			</div>
			<p v-else-if="!loading" class="doc-share-dialog__empty">
				{{ t('opencase', 'Ingen aktive delinger') }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import api from '../services/api.js'

export default {
	name: 'DocumentShareDialog',
	components: { NcModal, NcButton, NcSelect, DeleteIcon },
	props: {
		show:       { type: Boolean, required: true },
		documentId: { type: [String, Number], required: true },
		isReadOnly: { type: Boolean, default: false },
	},
	emits: ['close'],
	data() {
		return {
			shares:       [],
			loading:      false,
			saving:       false,
			searching:    false,
			selectedUser: null,
			canWrite:     false,
			userOptions:  [],
		}
	},
	watch: {
		show(val) {
			if (val && this.documentId) {
				this.loadShares()
			}
		},
	},
	methods: {
		async loadShares() {
			this.loading = true
			try {
				this.shares = await api.getDocumentShares(this.documentId)
			} catch (e) {
				console.error('OpenCase: failed to load document shares', e)
			} finally {
				this.loading = false
			}
		},
		async searchUsers(query) {
			if (!query || query.length < 2) {
				this.userOptions = []
				return
			}
			this.searching = true
			try {
				this.userOptions = await api.searchUsers(query)
			} catch (e) {
				this.userOptions = []
			} finally {
				this.searching = false
			}
		},
		async addShare() {
			if (!this.selectedUser) return
			this.saving = true
			try {
				this.shares = await api.createDocumentShare(
					this.documentId,
					this.selectedUser.id,
					this.canWrite,
				)
				this.selectedUser = null
				this.canWrite = false
			} catch (e) {
				console.error('OpenCase: failed to create document share', e)
			} finally {
				this.saving = false
			}
		},
		async removeShare(userId) {
			try {
				await api.revokeDocumentShare(this.documentId, userId)
				this.shares = this.shares.filter(s => s.shared_with !== userId)
			} catch (e) {
				console.error('OpenCase: failed to revoke document share', e)
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.doc-share-dialog {
	padding: 20px 24px 24px;
	min-width: 420px;
	max-height: 70vh;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.doc-share-dialog__title {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
}

.doc-share-dialog__add {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.doc-share-dialog__add-options {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.doc-share-dialog__write-label {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	user-select: none;
}

.doc-share-dialog__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.doc-share-dialog__share-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 10px;
	border-radius: 6px;
	background: var(--color-background-hover);
}

.doc-share-dialog__share-info {
	display: flex;
	align-items: baseline;
	gap: 8px;
	min-width: 0;
}

.doc-share-dialog__user {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.doc-share-dialog__perms,
.doc-share-dialog__expires {
	font-size: 0.8em;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.doc-share-dialog__empty {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin: 0;
}
</style>

<style>
.modal-mask.opencase-share-modal {
	background-color: rgba(0, 0, 0, 0.25);
}
</style>
