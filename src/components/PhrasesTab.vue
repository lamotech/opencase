<template>
	<div class="phrases">
		<div class="phrases__toolbar">
			<div class="phrases__filters">
				<NcTextField v-model="textFilter"
					class="phrases__filter-text"
					:label="t('opencase', 'Søg i titel, beskrivelse og tekst')"
					trailing-button-icon="close"
					:show-trailing-button="textFilter !== ''"
					@trailing-button-click="textFilter = ''" />
				<NcSelect v-model="categoryFilterOption"
					class="phrases__filter-select"
					:options="categoryFilterOptions"
					:placeholder="t('opencase', 'Alle kategorier')" />
				<NcSelect v-model="statusFilterOption"
					class="phrases__filter-select"
					:options="statusFilterOptions"
					:placeholder="t('opencase', 'Alle statusser')" />
			</div>
			<NcButton type="primary" @click="editing = {}">
				<template #icon>
					<PlusIcon :size="16" />
				</template>
				{{ t('opencase', 'Opret frase') }}
			</NcButton>
		</div>

		<input ref="fileInput"
			type="file"
			:accept="acceptedFileTypes"
			class="phrases__file-input"
			@change="onFileSelected">

		<NcLoadingIcon v-if="loading" :size="32" class="phrases__loading" />

		<NcEmptyContent v-else-if="phrases.length === 0"
			:name="t('opencase', 'Ingen fraser')">
			<template #icon>
				<TextBoxOutlineIcon :size="64" />
			</template>
			<template #description>
				{{ t('opencase', 'Opret en frase for at komme i gang') }}
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="filteredPhrases.length === 0"
			:name="t('opencase', 'Ingen fraser matcher filteret')">
			<template #icon>
				<TextBoxOutlineIcon :size="64" />
			</template>
		</NcEmptyContent>

		<div v-else class="phrases__table-wrap">
			<table class="phrases__table">
				<colgroup>
					<col class="phrases__col-title">
					<col class="phrases__col-category">
					<col class="phrases__col-status">
					<col class="phrases__col-scope">
				</colgroup>
				<thead>
					<tr>
						<th>{{ t('opencase', 'Titel') }}</th>
						<th>{{ t('opencase', 'Kategori') }}</th>
						<th>{{ t('opencase', 'Status') }}</th>
						<th>{{ t('opencase', 'Afgrænsning') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="phrase in pagedPhrases" :key="phrase.id">
						<td class="phrases__title-cell">
							<div class="phrases__title">{{ phrase.title }}</div>
							<div v-if="phrase.description"
								class="phrases__muted phrases__desc"
								:title="phrase.description">
								{{ phrase.description }}
							</div>
							<div class="phrases__actions">
							<NcButton v-if="phrase.has_file"
								type="tertiary"
								:title="t('opencase', 'Rediger i Nextcloud Office')"
								:disabled="busyId === phrase.id"
								@click="editInOffice(phrase)">
								<template #icon>
									<NcLoadingIcon v-if="busyId === phrase.id" :size="16" />
									<FileDocumentEditOutlineIcon v-else :size="16" />
								</template>
							</NcButton>
							<NcButton v-else
								type="tertiary"
								:title="t('opencase', 'Opret tomt dokument og rediger i Nextcloud Office')"
								:disabled="busyId === phrase.id"
								@click="createBlank(phrase)">
								<template #icon>
									<NcLoadingIcon v-if="busyId === phrase.id" :size="16" />
									<FilePlusOutlineIcon v-else :size="16" />
								</template>
							</NcButton>
							<NcButton type="tertiary"
								:title="phrase.has_file ? t('opencase', 'Erstat fil') : t('opencase', 'Upload fil')"
								:disabled="busyId === phrase.id"
								@click="chooseFile(phrase)">
								<template #icon>
									<UploadIcon :size="16" />
								</template>
							</NcButton>
							<NcButton v-if="phrase.has_file"
								type="tertiary"
								:title="t('opencase', 'Download')"
								@click="download(phrase)">
								<template #icon>
									<DownloadIcon :size="16" />
								</template>
							</NcButton>
							<NcButton type="tertiary"
								:title="t('opencase', 'Rediger')"
								@click="editing = phrase">
								<template #icon>
									<PencilIcon :size="16" />
								</template>
							</NcButton>
							<NcButton type="tertiary-no-background"
								:title="t('opencase', 'Slet')"
								@click="remove(phrase)">
								<template #icon>
									<DeleteIcon :size="16" />
								</template>
							</NcButton>
						</div>
						</td>
						<td>{{ categoryPath(categories, phrase.category_id) || '–' }}</td>
						<td>
							<span class="phrases__status" :class="'phrases__status--' + phrase.status">
								{{ statusLabel(phrase.status) }}
							</span>
						</td>
						<td class="phrases__scopes">{{ scopeSummary(phrase) }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div v-if="filteredPhrases.length > pageSize" class="phrases__pagination">
			<NcButton :disabled="currentPage === 1" @click="currentPage--">
				{{ t('opencase', 'Forrige') }}
			</NcButton>
			<span class="phrases__muted">
				{{ t('opencase', '{start}–{end} af {total}', {
					start: (currentPage - 1) * pageSize + 1,
					end: Math.min(currentPage * pageSize, filteredPhrases.length),
					total: filteredPhrases.length,
				}) }}
			</span>
			<NcButton :disabled="currentPage >= totalPages" @click="currentPage++">
				{{ t('opencase', 'Næste') }}
			</NcButton>
		</div>

		<PhraseDialog v-if="editing"
			:phrase="editing.id ? editing : null"
			:categories="categories"
			:case-types="caseTypes"
			:org-units="orgUnits"
			@close="editing = null"
			@saved="onSaved" />

		<!-- Deleting a phrase that templates use -->
		<NcDialog v-if="deleting"
			:name="t('opencase', 'Slet frase')"
			@closing="deleting = null">
			<p>
				{{ t('opencase', 'Frasen "{title}" bruges i disse skabeloner og fjernes fra dem, hvis den slettes:', { title: deleting.phrase.title }) }}
			</p>
			<ul class="phrases__delete-list">
				<li v-for="usage in deleting.usages" :key="usage.id">
					{{ usage.name }}
					<strong v-if="usage.is_required">({{ t('opencase', 'obligatorisk') }})</strong>
				</li>
			</ul>
			<p class="phrases__muted">
				{{ t('opencase', 'Arkivér frasen i stedet, hvis den ikke længere skal bruges. Så bliver den ikke indsat i nye breve, men kan genaktiveres.') }}
			</p>
			<template #actions>
				<NcButton @click="deleting = null">
					{{ t('opencase', 'Annuller') }}
				</NcButton>
				<NcButton v-if="deleting.phrase.status !== 'archived'" type="primary" @click="archiveDeleting">
					{{ t('opencase', 'Arkivér') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('opencase', 'Slet alligevel') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import FileDocumentEditOutlineIcon from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FilePlusOutlineIcon from 'vue-material-design-icons/FilePlusOutline.vue'
import TextBoxOutlineIcon from 'vue-material-design-icons/TextBoxOutline.vue'

import PhraseDialog from './PhraseDialog.vue'
import api from '../services/api.js'
import { classPlanScopeLabel, ensureClassificationPlanItems } from '../utils/caseScopes.js'
import { categoryTree, categoryPath, PHRASE_FILE_ACCEPT, PHRASE_STATUSES, phraseStatusLabel } from '../utils/phrases.js'

/**
 * Brevdesigner tab: create and edit phrases, upload phrase files and edit
 * them in Nextcloud Office.
 */
export default {
	name: 'PhrasesTab',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		PlusIcon,
		PencilIcon,
		DeleteIcon,
		UploadIcon,
		DownloadIcon,
		FileDocumentEditOutlineIcon,
		FilePlusOutlineIcon,
		TextBoxOutlineIcon,
		PhraseDialog,
	},

	data() {
		return {
			loading: false,
			phrases: [],
			categories: [],
			caseTypes: [],
			orgUnits: [],
			editing: null,
			// { phrase, usages } while confirming deletion of a phrase templates use
			deleting: null,
			busyId: null,
			uploadTarget: null,
			acceptedFileTypes: PHRASE_FILE_ACCEPT,
			// Filters & pagination
			textFilter: '',
			categoryFilter: null,
			statusFilter: null,
			currentPage: 1,
			pageSize: 20,
		}
	},

	computed: {
		categoryFilterOptions() {
			return categoryTree(this.categories).map(({ category, depth }) => ({
				id: category.id,
				label: ' '.repeat(depth) + category.name,
			}))
		},
		categoryFilterOption: {
			get() {
				return this.categoryFilterOptions.find(o => o.id === this.categoryFilter) || null
			},
			set(v) {
				this.categoryFilter = v?.id ?? null
			},
		},
		statusFilterOptions() {
			return PHRASE_STATUSES.map(id => ({ id, label: phraseStatusLabel(id) }))
		},
		statusFilterOption: {
			get() {
				return this.statusFilterOptions.find(o => o.id === this.statusFilter) || null
			},
			set(v) {
				this.statusFilter = v?.id ?? null
			},
		},
		/** The filtered category and all its sub-categories. */
		categoryFilterIds() {
			if (this.categoryFilter === null) return null
			const ids = new Set([this.categoryFilter])
			let added = true
			while (added) {
				added = false
				for (const c of this.categories) {
					if (c.parent_id !== null && ids.has(c.parent_id) && !ids.has(c.id)) {
						ids.add(c.id)
						added = true
					}
				}
			}
			return ids
		},
		filteredPhrases() {
			const query = this.textFilter.trim().toLowerCase()
			return this.phrases.filter(p =>
				(!query || [p.title, p.description, (p.content || '').replace(/<[^>]*>/g, ' ')].some(text => (text || '').toLowerCase().includes(query)))
				&& (this.categoryFilterIds === null || this.categoryFilterIds.has(p.category_id))
				&& (this.statusFilter === null || p.status === this.statusFilter),
			)
		},
		totalPages() {
			return Math.max(1, Math.ceil(this.filteredPhrases.length / this.pageSize))
		},
		pagedPhrases() {
			const start = (this.currentPage - 1) * this.pageSize
			return this.filteredPhrases.slice(start, start + this.pageSize)
		},
	},

	watch: {
		textFilter() { this.currentPage = 1 },
		categoryFilter() { this.currentPage = 1 },
		statusFilter() { this.currentPage = 1 },
		totalPages(newTotal) {
			if (this.currentPage > newTotal) {
				this.currentPage = newTotal
			}
		},
	},

	async mounted() {
		ensureClassificationPlanItems(this.$store)
		this.loading = true
		try {
			const [phrases, categories] = await Promise.all([
				api.getPhrases(),
				api.getPhraseCategories(),
			])
			this.phrases = phrases
			this.categories = categories
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente fraser'))
		} finally {
			this.loading = false
		}
		this.loadScopeReferenceData()
	},

	methods: {
		categoryPath,

		statusLabel: phraseStatusLabel,

		async loadScopeReferenceData() {
			try {
				const [caseTypes, orgs] = await Promise.all([
					api.getCaseTypes(),
					api.getOrgTree(),
				])
				this.caseTypes = caseTypes
				this.orgUnits = orgs
			} catch (e) {
				// scope picker options just stay empty
			}
		},

		replacePhrase(phrase) {
			const index = this.phrases.findIndex(p => p.id === phrase.id)
			if (index === -1) {
				this.phrases.push(phrase)
			} else {
				this.phrases.splice(index, 1, phrase)
			}
			this.phrases.sort((a, b) => a.title.localeCompare(b.title, 'da'))
		},

		onSaved(phrase) {
			this.editing = null
			this.replacePhrase(phrase)
		},

		chooseFile(phrase) {
			this.uploadTarget = phrase
			this.$refs.fileInput.value = ''
			this.$refs.fileInput.click()
		},

		async onFileSelected(event) {
			const file = event.target.files[0]
			const phrase = this.uploadTarget
			this.uploadTarget = null
			if (!file || !phrase) return
			if (phrase.has_file && !window.confirm(t('opencase', 'Erstat den nuværende fil på "{title}"?', { title: phrase.title }))) {
				return
			}
			this.busyId = phrase.id
			try {
				this.replacePhrase(await api.uploadPhraseFile(phrase.id, file))
				showSuccess(t('opencase', 'Filen er uploadet'))
			} catch (e) {
				showError(e.response?.data?.ocs?.data?.error || t('opencase', 'Upload mislykkedes'))
			} finally {
				this.busyId = null
			}
		},

		async createBlank(phrase) {
			this.busyId = phrase.id
			try {
				const updated = await api.createBlankPhraseFile(phrase.id)
				this.replacePhrase(updated)
				if (updated.nc_file_id) {
					window.open(generateUrl('/f/' + updated.nc_file_id), '_blank')
				}
			} catch (e) {
				showError(t('opencase', 'Kunne ikke oprette dokument'))
			} finally {
				this.busyId = null
			}
		},

		async editInOffice(phrase) {
			this.busyId = phrase.id
			try {
				const ncFileId = await api.openPhraseForEdit(phrase.id)
				window.open(generateUrl('/f/' + ncFileId), '_blank')
			} catch (e) {
				showError(t('opencase', 'Kunne ikke åbne frasen til redigering'))
			} finally {
				this.busyId = null
			}
		},

		async download(phrase) {
			try {
				await api.downloadPhrase(phrase.id, phrase.original_filename)
			} catch (e) {
				showError(t('opencase', 'Download mislykkedes'))
			}
		},

		async remove(phrase) {
			let usages = []
			try {
				usages = await api.getPhraseTemplates(phrase.id)
			} catch (e) {
				// unknown usage — fall back to the plain confirmation
			}
			if (usages.length > 0) {
				// Templates use it: offer archiving instead (see the dialog)
				this.deleting = { phrase, usages }
				return
			}
			if (window.confirm(t('opencase', 'Slet frasen "{title}"?', { title: phrase.title }))) {
				await this.deletePhrase(phrase)
			}
		},

		async confirmDelete() {
			const phrase = this.deleting.phrase
			this.deleting = null
			await this.deletePhrase(phrase)
		},

		async archiveDeleting() {
			const phrase = this.deleting.phrase
			this.deleting = null
			try {
				this.replacePhrase(await api.updatePhrase(phrase.id, { status: 'archived' }))
				showSuccess(t('opencase', 'Frasen er arkiveret'))
			} catch (e) {
				showError(t('opencase', 'Frasen kunne ikke arkiveres'))
			}
		},

		async deletePhrase(phrase) {
			try {
				await api.deletePhrase(phrase.id)
				this.phrases = this.phrases.filter(p => p.id !== phrase.id)
			} catch (e) {
				showError(t('opencase', 'Frasen kunne ikke slettes'))
			}
		},

		scopeSummary(phrase) {
			if (!phrase.scopes?.length) {
				return t('opencase', 'Alle sager')
			}
			return phrase.scopes.map(s => {
				if (s.scope_type === 'casetype') {
					return this.caseTypes.find(c => String(c.id) === String(s.scope_value))?.name || s.scope_value
				}
				if (s.scope_type === 'organisation') {
					return this.orgUnits.find(o => o.org_uuid === s.scope_value)?.org_name || s.scope_value
				}
				if (s.scope_type === 'class_plan') {
					return classPlanScopeLabel(this.$store.state, s.scope_value)
				}
				return 'KLE ' + s.scope_value
			}).join(', ')
		},
	},
}
</script>

<style scoped>
.phrases__toolbar {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.phrases__filters {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	flex: 1;
}

.phrases__filter-text {
	width: 260px;
}

.phrases__filter-select {
	min-width: 200px;
}

.phrases__file-input {
	display: none;
}

.phrases__loading {
	display: block;
	margin: 24px auto;
}

.phrases__table {
	width: 100%;
	border-collapse: collapse;
}

/* Fixed layout: columns share the available width, so the list never
   needs horizontal scrolling — long text wraps instead. */
.phrases__table {
	table-layout: fixed;
}

.phrases__col-title { width: 50%; }
.phrases__col-category { width: 18%; }
.phrases__col-status { width: 12%; }
.phrases__col-scope { width: 20%; }

.phrases__table th,
.phrases__table td {
	overflow-wrap: anywhere;
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.phrases__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
}

.phrases__title {
	font-weight: 500;
}

.phrases__title,
.phrases__filename {
	overflow-wrap: anywhere;
}

/* Multi-line descriptions: show the first two lines, full text in the tooltip */
.phrases__desc {
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	line-clamp: 2;
	overflow: hidden;
	overflow-wrap: anywhere;
	white-space: normal;
}

.phrases__muted {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

/* Same badge style as CaseStatusBadge / AccessRequestStatusBadge */
.phrases__status {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	white-space: nowrap;
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	vertical-align: top;
}

/* like access request "Modtaget" */
.phrases__status--draft { background: #e3f2fd; color: #1565c0; }
/* like case "Åben" */
.phrases__status--active { background: #e8f5e9; color: #2e7d32; }
/* like case "Arkiveret" */
.phrases__status--archived { background: #e0e0e0; color: #616161; }

/* Row actions sit below the title and description */
.phrases__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
	margin: 4px 0 0 -8px;
}

.phrases__delete-list {
	margin: 8px 0 12px;
	padding-left: 20px;
	list-style: disc;
}

.phrases__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 16px;
}
</style>
