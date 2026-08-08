<template>
	<NcModal v-if="show"
		class="opencase-share-modal"
		:name="t('opencase', 'Del {name}', { name: file.original_filename || '' })"
		size="normal"
		@close="$emit('close')">
		<div class="file-share-dialog">
			<h3 class="file-share-dialog__title">
				{{ t('opencase', 'Del dokument') }}
				<span class="file-share-dialog__filename">{{ file.original_filename }}</span>
			</h3>

			<!-- Add new share -->
			<div class="file-share-dialog__add">
				<NcSelect
					v-model="selectedUser"
					:options="userOptions"
					:placeholder="t('opencase', 'Søg bruger...')"
					:loading="searching"
					:filterable="false"
					label="label"
					track-by="id"
					@search="searchUsers" />
				<div class="file-share-dialog__add-options">
					<label v-if="!isReadOnly" class="file-share-dialog__write-label">
						<input v-model="canWrite" type="checkbox" />
						{{ t('opencase', 'Kan redigere') }}
					</label>
					<NcButton :disabled="!selectedUser || saving" @click="addShare">
						{{ t('opencase', 'Del') }}
					</NcButton>
				</div>
			</div>

			<!-- Existing shares -->
			<div v-if="shares.length > 0" class="file-share-dialog__list">
				<div v-for="share in shares"
					:key="share.id"
					class="file-share-dialog__share-row">
					<div class="file-share-dialog__share-info">
						<span class="file-share-dialog__user">{{ share.display_name || share.shared_with }}</span>
						<span class="file-share-dialog__perms">
							{{ share.can_write ? t('opencase', 'Kan redigere') : t('opencase', 'Kan læse') }}
						</span>
						<span v-if="share.expires_at" class="file-share-dialog__expires">
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
			<p v-else-if="!loading" class="file-share-dialog__empty">
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
	name: 'FileShareDialog',
	components: { NcModal, NcButton, NcSelect, DeleteIcon },
	props: {
		show: { type: Boolean, required: true },
		file: { type: Object, required: true },
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
			if (val && this.file?.id) {
				this.loadShares()
			}
		},
	},
	methods: {
		async loadShares() {
			this.loading = true
			try {
				this.shares = await api.getFileShares(this.file.id)
			} catch (e) {
				console.error('OpenCase: failed to load file shares', e)
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
				const share = await api.createFileShare(
					this.file.id,
					this.selectedUser.id,
					this.canWrite,
				)
				// Replace or add in list
				const idx = this.shares.findIndex(s => s.shared_with === share.shared_with)
				if (idx >= 0) {
					this.shares.splice(idx, 1, share)
				} else {
					this.shares.push(share)
				}
				this.selectedUser = null
				this.canWrite = false
			} catch (e) {
				console.error('OpenCase: failed to create file share', e)
			} finally {
				this.saving = false
			}
		},
		async removeShare(userId) {
			try {
				await api.revokeFileShare(this.file.id, userId)
				this.shares = this.shares.filter(s => s.shared_with !== userId)
			} catch (e) {
				console.error('OpenCase: failed to revoke file share', e)
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
.file-share-dialog {
	padding: 20px 24px 24px;
	min-width: 420px;
	max-height: 70vh;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.file-share-dialog__title {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
}

.file-share-dialog__filename {
	font-weight: 400;
	color: var(--color-text-lighter);
	margin-left: 6px;
}

.file-share-dialog__add {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.file-share-dialog__add-options {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.file-share-dialog__write-label {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	user-select: none;
}

.file-share-dialog__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.file-share-dialog__share-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 10px;
	border-radius: 6px;
	background: var(--color-background-hover);
}

.file-share-dialog__share-info {
	display: flex;
	align-items: baseline;
	gap: 8px;
	min-width: 0;
}

.file-share-dialog__user {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-share-dialog__perms,
.file-share-dialog__expires {
	font-size: 0.8em;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.file-share-dialog__empty {
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
