<template>
	<div class="opencase-shared">
		<h2>{{ t('opencase', 'Delt med mig') }}</h2>
		<NcLoadingIcon v-if="loading" :size="44" />

		<template v-else-if="documentShares.length === 0 && fileShares.length === 0">
			<NcEmptyContent :title="t('opencase', 'Ingen filer delt med dig')">
				<template #icon>
					<ShareVariantIcon :size="64" />
				</template>
			</NcEmptyContent>
		</template>

		<template v-else>
			<!-- Documents section -->
			<template v-if="documentShares.length > 0">
				<h3 class="opencase-shared__section-title">
					<TextBoxMultipleIcon :size="18" />
					{{ t('opencase', 'Dokumenter') }}
				</h3>
				<table class="opencase-shared__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Dokument') }}</th>
							<th>{{ t('opencase', 'Delt af') }}</th>
							<th>{{ t('opencase', 'Adgang') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="doc in documentShares"
							:key="doc.document_id"
							class="opencase-shared__row">
							<td>
							<div class="opencase-shared__doc-name">
								<FileDocumentOutlineIcon :size="18" class="opencase-shared__icon" />
								<span>
									<span v-if="doc.document_number" class="opencase-shared__docnumber">
										{{ doc.document_number }}
									</span>
									{{ doc.document_title }}
								</span>
							</div>
							</td>
							<td class="opencase-shared__owner">{{ doc.owner_display }}</td>
							<td class="opencase-shared__perms">
								<span v-if="doc.can_write" class="opencase-shared__badge opencase-shared__badge--write">
									{{ t('opencase', 'Skriv') }}
								</span>
								<span v-else class="opencase-shared__badge opencase-shared__badge--read">
									{{ t('opencase', 'Læs') }}
								</span>
							</td>
							<td class="opencase-shared__actions">
								<router-link
									:to="{ name: 'document-detail', params: { id: String(doc.document_id) } }"
									class="opencase-shared__doc-link">
									<NcButton type="secondary" :title="t('opencase', 'Åbn dokument')">
										<template #icon>
											<OpenInNewIcon :size="18" />
										</template>
									</NcButton>
								</router-link>
							</td>
						</tr>
					</tbody>
				</table>
			</template>

			<!-- Individual files section -->
			<template v-if="fileShares.length > 0">
				<h3 class="opencase-shared__section-title">
					<FolderMultipleIcon :size="18" />
					{{ t('opencase', 'Filer') }}
				</h3>
				<table class="opencase-shared__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Filnavn') }}</th>
							<th>{{ t('opencase', 'Delt af') }}</th>
							<th>{{ t('opencase', 'Størrelse') }}</th>
							<th>{{ t('opencase', 'Opdateret') }}</th>
							<th>{{ t('opencase', 'Adgang') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="file in fileShares"
							:key="file.share_id"
							class="opencase-shared__row">
							<td>
							<div class="opencase-shared__name">
								<component :is="fileIcon(file.mime_type)" :size="18" class="opencase-shared__icon" />
								{{ file.original_filename }}
							</div>
							</td>
							<td class="opencase-shared__owner">{{ file.owner_display }}</td>
							<td class="opencase-shared__size">{{ formatSize(file.size) }}</td>
							<td class="opencase-shared__date">{{ formatDate(file.updated_at) }}</td>
							<td class="opencase-shared__perms">
								<span v-if="file.can_write" class="opencase-shared__badge opencase-shared__badge--write">
									{{ t('opencase', 'Redigere') }}
								</span>
								<span v-else class="opencase-shared__badge">
									{{ t('opencase', 'Læse') }}
								</span>
							</td>
							<td class="opencase-shared__actions">
								<NcButton v-if="file.can_write"
									:title="t('opencase', 'Rediger')"
									type="secondary"
									@click="openFile(file, 'edit')">
									<template #icon>
										<PencilIcon :size="18" />
									</template>
								</NcButton>
								<NcButton v-else
									:title="t('opencase', 'Vis')"
									type="secondary"
									@click="openFile(file, 'view')">
									<template #icon>
										<EyeIcon :size="18" />
									</template>
								</NcButton>
								<NcButton :title="t('opencase', 'Download')"
									type="secondary"
									@click="downloadFile(file)">
									<template #icon>
										<DownloadIcon :size="18" />
									</template>
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import { generateUrl } from '@nextcloud/router'

import FileIcon from 'vue-material-design-icons/File.vue'
import FilePdfIcon from 'vue-material-design-icons/FilePdfBox.vue'
import FileWordIcon from 'vue-material-design-icons/FileWord.vue'
import FileExcelIcon from 'vue-material-design-icons/FileExcel.vue'
import FileImageIcon from 'vue-material-design-icons/FileImage.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import TextBoxMultipleIcon from 'vue-material-design-icons/TextBoxMultiple.vue'
import FolderMultipleIcon from 'vue-material-design-icons/FolderMultiple.vue'

import api from '../services/api.js'

const MIME_ICONS = {
	'application/pdf': FilePdfIcon,
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': FileWordIcon,
	'application/msword': FileWordIcon,
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': FileExcelIcon,
	'application/vnd.ms-excel': FileExcelIcon,
	'image/': FileImageIcon,
}

export default {
	name: 'SharedWithMeView',
	components: {
		NcLoadingIcon, NcEmptyContent, NcButton,
		FileDocumentOutlineIcon, PencilIcon, EyeIcon, DownloadIcon,
		ShareVariantIcon, OpenInNewIcon, TextBoxMultipleIcon, FolderMultipleIcon,
	},
	data() {
		return { allFiles: [], loading: true }
	},
	computed: {
		// Files belonging to a document-level share (document_id set on the share row).
		// De-duplicated per document: one row per document_id.
		documentShares() {
			const seen = {}
			for (const f of this.allFiles) {
				if (f.document_id == null) continue
				if (!seen[f.document_id]) {
					seen[f.document_id] = {
						document_id:    f.document_id,
						document_title:  f.document_title || String(f.document_id),
						document_number: f.document_number,
						owner_display:   f.owner_display,
						can_write:       f.can_write,
					}
				} else if (f.can_write) {
					// Promote to writable if any file in the document is writable.
					seen[f.document_id].can_write = true
				}
			}
			return Object.values(seen)
		},
		// Only individually shared files (document_id is null on the share row).
		fileShares() {
			return this.allFiles.filter(f => f.document_id == null)
		},
	},
	async mounted() {
		try {
			this.allFiles = await api.getMySharedFiles()
		} catch (e) {
			console.error('OpenCase: failed to load shared files', e)
		} finally {
			this.loading = false
		}
	},
	methods: {
		fileIcon(mimeType) {
			for (const [prefix, icon] of Object.entries(MIME_ICONS)) {
				if (mimeType && mimeType.startsWith(prefix)) return icon
			}
			return FileIcon
		},
		async openFile(file, action) {
			try {
				const ncFileId = await api.getEditUrl(file.file_id, action)
				window.open(generateUrl('/f/' + ncFileId), '_blank')
			} catch (e) {
				console.error('OpenCase: failed to open shared file', e)
			}
		},
		async downloadFile(file) {
			await api.downloadFile(file.file_id, file.original_filename)
		},
		formatSize(bytes) {
			if (!bytes) return ''
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / 1048576).toFixed(1) + ' MB'
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
.opencase-shared { padding: 20px; max-width: 1100px; }
.opencase-shared h2 { margin: 0 0 20px; font-size: 1.4em; }

.opencase-shared__section-title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 24px 0 10px;
	font-size: 1em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}
.opencase-shared__section-title:first-of-type { margin-top: 0; }

.opencase-shared__table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.opencase-shared__table th {
	text-align: left; padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600; color: var(--color-text-maxcontrast);
	font-size: 0.8em; text-transform: uppercase;
}
.opencase-shared__row:hover { background: var(--color-background-hover); }
.opencase-shared__row td { padding: 10px 12px; border-bottom: 1px solid var(--color-border-dark); vertical-align: middle; }

.opencase-shared__doc-name {
	display: flex; align-items: center; gap: 8px; font-weight: 500;
}
.opencase-shared__docnumber {
	font-family: monospace; font-size: 0.85em;
	color: var(--color-text-maxcontrast); margin-right: 4px;
}
/* flex on a div wrapper inside td — keeps td as table-cell so row heights stay in sync */
.opencase-shared__name { display: flex; align-items: center; gap: 8px; font-weight: 500; }
.opencase-shared__icon { flex-shrink: 0; color: var(--color-text-lighter); }
.opencase-shared__owner, .opencase-shared__date, .opencase-shared__size { color: var(--color-text-lighter); white-space: nowrap; }
.opencase-shared__actions { display: flex; gap: 4px; justify-content: flex-end; }
.opencase-shared__doc-link { text-decoration: none; }

.opencase-shared__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
}
.opencase-shared__badge--write {
	background: #d4edda;
	color: #155724;
}
.opencase-shared__badge--read {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}
</style>
