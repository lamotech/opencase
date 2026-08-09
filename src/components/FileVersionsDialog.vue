<template>
	<NcModal v-if="show"
		class="opencase-versions-modal"
		:name="t('opencase', 'Versioner af {name}', { name: file.virtual_filename })"
		size="normal"
		@close="$emit('close')">
		<div class="file-versions-dialog">
			<h3 class="file-versions-dialog__title">
				{{ t('opencase', 'Versioner af {name}', { name: file.virtual_filename }) }}
			</h3>

			<div v-if="loading" class="file-versions-dialog__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('opencase', 'Indlæser versioner...') }}</span>
			</div>

			<div v-else-if="error" class="file-versions-dialog__error">
				{{ t('opencase', 'Kunne ikke hente versioner.') }}
			</div>

			<div v-else-if="versions.length === 0" class="file-versions-dialog__empty">
				{{ t('opencase', 'Ingen versioner tilgængelige.') }}
			</div>

			<table v-else class="file-versions-dialog__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Dato') }}</th>
						<th>{{ t('opencase', 'Størrelse') }}</th>
						<th>{{ t('opencase', 'Oprettet af') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(version, index) in versions"
						:key="index"
						:class="{ 'file-versions-dialog__row--current': version.is_current }">
						<td>
							{{ versionDate(version) }}
							<span v-if="version.is_current" class="file-versions-dialog__badge">
								{{ t('opencase', 'Aktuel') }}
							</span>
						</td>
						<td>{{ formatSize(version.size) }}</td>
						<td>{{ version.created_by }}</td>
						<td class="file-versions-dialog__actions-cell">
							<NcActions :force-menu="true" :disabled="!!pendingId">
								<NcActionButton @click="viewVersion(version)">
									<template #icon>
										<EyeIcon :size="18" />
									</template>
									{{ t('opencase', 'Vis version') }}
								</NcActionButton>
								<NcActionButton @click="downloadVersion(version)">
									<template #icon>
										<DownloadIcon :size="18" />
									</template>
									{{ t('opencase', 'Download version') }}
								</NcActionButton>
								<NcActionButton v-if="!version.is_current" @click="restoreVersion(version)">
									<template #icon>
										<HistoryIcon :size="18" />
									</template>
									{{ t('opencase', 'Gendan version') }}
								</NcActionButton>
							</NcActions>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</NcModal>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'
import api from '../services/api.js'

export default {
	name: 'FileVersionsDialog',
	components: { NcModal, NcLoadingIcon, NcActions, NcActionButton, EyeIcon, DownloadIcon, HistoryIcon },
	props: {
		show: { type: Boolean, required: true },
		file: { type: Object, required: true },
	},
	emits: ['close'],
	data() {
		return {
			loading: false,
			error: false,
			versions: [],
			pendingId: null,
		}
	},
	watch: {
		show(val) {
			if (val) {
				this.fetchVersions()
			} else {
				this.versions = []
				this.error = false
			}
		},
	},
	methods: {
		async fetchVersions() {
			this.loading = true
			this.error = false
			try {
				this.versions = await api.getFileVersions(this.file.id)
			} catch (e) {
				console.error('OpenCase: failed to load versions', e)
				this.error = true
			} finally {
				this.loading = false
			}
		},
		async viewVersion(version) {
			if (version.is_current) {
				// Open current version directly
				try {
					const ncFileId = await api.getEditUrl(this.file.id, 'view')
					window.open(generateUrl('/f/' + ncFileId), '_blank')
				} catch (e) {
					console.error('OpenCase: failed to open current version for viewing', e)
				}
				return
			}
			if (this.pendingId) return
			this.pendingId = version.id
			try {
				const ncFileId = await api.getVersionEditUrl(this.file.id, version.id)
				window.open(generateUrl('/f/' + ncFileId), '_blank')
			} catch (e) {
				console.error('OpenCase: failed to open version for viewing', e)
			} finally {
				this.pendingId = null
			}
		},
		async downloadVersion(version) {
			if (version.is_current) {
				await api.downloadFile(this.file.id, this.file.original_filename)
				return
			}
			if (this.pendingId) return
			this.pendingId = version.id
			try {
				await api.downloadFileVersion(this.file.id, version.id, this.file.original_filename)
			} catch (e) {
				console.error('OpenCase: failed to download version', e)
			} finally {
				this.pendingId = null
			}
		},
		async restoreVersion(version) {
			if (this.pendingId) return
			this.pendingId = version.id
			try {
				await api.restoreFileVersion(this.file.id, version.id)
				await this.fetchVersions()
			} catch (e) {
				console.error('OpenCase: failed to restore version', e)
			} finally {
				this.pendingId = null
			}
		},
		versionDate(version) {
			const date = version.is_current
				? (version.created_at ? new Date(version.created_at) : null)
				: (version.timestamp ? new Date(version.timestamp * 1000) : null)
			if (!date) return ''
			return date.toLocaleString('da-DK', {
				year: 'numeric', month: 'short', day: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},
		formatSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / 1048576).toFixed(1) + ' MB'
		},
	},
}
</script>

<style scoped>
.file-versions-dialog {
	padding: 20px 24px 24px;
	min-width: 420px;
}

.file-versions-dialog__title {
	margin: 0 0 16px;
	font-size: 1rem;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-versions-dialog__loading {
	display: flex;
	align-items: center;
	gap: 10px;
	color: var(--color-text-lighter);
	padding: 16px 0;
}

.file-versions-dialog__error,
.file-versions-dialog__empty {
	color: var(--color-text-lighter);
	padding: 16px 0;
}

.file-versions-dialog__table {
	width: 100%;
	border-collapse: collapse;
}

.file-versions-dialog__table th {
	text-align: left;
	padding: 6px 12px 6px 0;
	font-weight: 600;
	font-size: 0.85em;
	color: var(--color-text-lighter);
	border-bottom: 1px solid var(--color-border);
}

.file-versions-dialog__table td {
	padding: 8px 12px 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
	font-size: 0.9em;
}

.file-versions-dialog__table tr:last-child td {
	border-bottom: none;
}

.file-versions-dialog__row--current td {
	font-weight: 500;
}

.file-versions-dialog__badge {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 6px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	vertical-align: middle;
}

.file-versions-dialog__actions-cell {
	width: 44px;
	text-align: right;
	padding-right: 0;
}
</style>

<style>
/* Reduce backdrop opacity for the versions dialog so the document page remains readable behind it */
.modal-mask.opencase-versions-modal {
	background-color: rgba(0, 0, 0, 0.25);
}
</style>
