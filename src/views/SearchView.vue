<template>
	<div class="opencase-search">
		<h2>{{ t('opencase', 'Søg i sager og dokumenter') }}</h2>

		<!-- Search input -->
		<div class="opencase-search__input-row">
			<NcTextField v-model="query"
				:placeholder="t('opencase', 'Søg i indhold, titler, sagsnumre…')"
				trailing-button-icon="arrowRight"
				:show-trailing-button="query.length > 0"
				@trailing-button-click="doSearch"
				@keyup.enter="doSearch" />
		</div>

		<!-- Active filters -->
		<div v-if="hasActiveFilters" class="opencase-search__active-filters">
			<span v-for="(val, key) in activeFilters"
				:key="key"
				class="opencase-search__filter-chip"
				@click="removeFilter(key)">
				{{ filterLabels[key] || val }} ✕
			</span>
		</div>

		<!-- Results area -->
		<div class="opencase-search__results-area">
			<!-- Facets sidebar -->
			<aside v-if="hasAnyResults" class="opencase-search__facets">
				<div v-if="aggregations.organisations" class="opencase-search__facet-group">
					<h4>{{ t('opencase', 'Organisation') }}</h4>
					<ul>
						<li v-for="bucket in aggregations.organisations.buckets"
							:key="bucket.key"
							@click="setFilter('organisation', bucket.key)">
							<span>{{ bucket.key }}</span>
							<span class="opencase-search__facet-count">{{ bucket.doc_count }}</span>
						</li>
					</ul>
				</div>

				<div v-if="aggregations.case_types" class="opencase-search__facet-group">
					<h4>{{ t('opencase', 'Sagstype') }}</h4>
					<ul>
						<li v-for="bucket in aggregations.case_types.buckets"
							:key="bucket.key"
							@click="setFilter('case_type', bucket.key, bucket.label)">
							<span>{{ bucket.label || bucket.key }}</span>
							<span class="opencase-search__facet-count">{{ bucket.doc_count }}</span>
						</li>
					</ul>
				</div>

				<div v-if="aggregations.document_types" class="opencase-search__facet-group">
					<h4>{{ t('opencase', 'Dokumenttype') }}</h4>
					<ul>
						<li v-for="bucket in aggregations.document_types.buckets"
							:key="bucket.key"
							@click="setFilter('document_type', bucket.key)">
							<span>{{ bucket.key }}</span>
							<span class="opencase-search__facet-count">{{ bucket.doc_count }}</span>
						</li>
					</ul>
				</div>
			</aside>

			<!-- Results list -->
			<div class="opencase-search__results">
				<NcLoadingIcon v-if="loading" :size="44" class="opencase-search__loading" />

				<NcEmptyContent v-else-if="searched && !hasAnyResults"
					:title="t('opencase', 'Ingen resultater')"
					:description="t('opencase', 'Prøv at ændre din søgning')">
					<template #icon>
						<MagnifyIcon :size="48" />
					</template>
				</NcEmptyContent>

				<template v-else-if="hasAnyResults">
					<OverflowTabs :tabs="resultTabs" :value="activeTab" @input="activeTab = $event" />

					<!-- Cases section -->
					<section v-if="activeTab === 'cases'" class="opencase-search__section">
						<p v-if="cases.length === 0" class="opencase-search__section-empty">
							{{ t('opencase', 'Ingen sager matcher søgningen') }}
						</p>
						<div v-for="c in cases"
							:key="'case-' + c.id"
							class="opencase-search__hit opencase-search__hit--case"
							@click="openCase(c)">
							<div class="opencase-search__hit-header">
								<FolderIcon :size="16" class="opencase-search__hit-icon opencase-search__hit-icon--case" />
								<strong>{{ c.title }}</strong>
								<span class="opencase-search__hit-badge opencase-search__hit-badge--case">
									{{ t('opencase', 'Sag') }}
								</span>
							</div>
							<div class="opencase-search__hit-meta">
								<span>{{ c.case_number }}</span>
								<span v-if="c.casetype_name">{{ c.casetype_name }}</span>
								<span v-if="c.year">{{ c.year }}</span>
							</div>
						</div>
					</section>

					<!-- Documents section -->
					<section v-if="activeTab === 'documents'" class="opencase-search__section">
						<p v-if="documents.length === 0" class="opencase-search__section-empty">
							{{ t('opencase', 'Ingen dokumenter matcher søgningen') }}
						</p>
						<div v-for="doc in documents"
							:key="'doc-' + doc.id"
							class="opencase-search__hit opencase-search__hit--document"
							@click="openDocument(doc)">
							<div class="opencase-search__hit-header">
								<FileDocumentOutlineIcon :size="16" class="opencase-search__hit-icon opencase-search__hit-icon--document" />
								<strong>{{ doc.title }}</strong>
								<span class="opencase-search__hit-badge opencase-search__hit-badge--document">
									{{ t('opencase', 'Dokument') }}
								</span>
							</div>
							<div class="opencase-search__hit-meta">
								<span>{{ doc.case_number }}</span>
								<span v-if="doc.case_title">{{ doc.case_title }}</span>
								<span v-if="doc.document_type">{{ doc.document_type }}</span>
							</div>
						</div>
					</section>

					<!-- Files section -->
					<section v-if="activeTab === 'files'" class="opencase-search__section">
						<p v-if="files.length === 0" class="opencase-search__section-empty">
							{{ t('opencase', 'Ingen filer matcher søgningen') }}
						</p>
						<div v-for="hit in files"
							:key="'file-' + hit.file_id"
							class="opencase-search__hit opencase-search__hit--file"
							@click="openFile(hit)">
							<div class="opencase-search__hit-header">
								<FileDocumentIcon :size="16" class="opencase-search__hit-icon opencase-search__hit-icon--file" />
								<strong>{{ hit.filename }}</strong>
								<span class="opencase-search__hit-badge opencase-search__hit-badge--file">
									{{ t('opencase', 'Fil') }}
								</span>
								<span class="opencase-search__hit-case">
									{{ hit.case_number }} — {{ hit.case_title }}
								</span>
							</div>
							<div v-if="hit._highlights && hit._highlights['attachment.content']"
								class="opencase-search__hit-snippet"
								v-html="hit._highlights['attachment.content'].join(' … ')" />
							<div class="opencase-search__hit-meta">
								<span>{{ hit.organisation }}</span>
								<span>{{ hit.year }}</span>
								<span v-if="hit.document_type">{{ hit.document_type }}</span>
							</div>
						</div>

						<!-- Pagination -->
						<div v-if="effectiveTotal > limit" class="opencase-search__pagination">
							<NcButton :disabled="offset === 0" @click="prevPage">
								{{ t('opencase', 'Forrige') }}
							</NcButton>
							<NcButton :disabled="offset + limit >= effectiveTotal" @click="nextPage">
								{{ t('opencase', 'Næste') }}
							</NcButton>
						</div>
					</section>
				</template>
			</div>
		</div>
	</div>
</template>

<script>
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import OverflowTabs from '../components/OverflowTabs.vue'

export default {
	name: 'SearchView',
	components: { NcTextField, NcButton, NcEmptyContent, NcLoadingIcon, MagnifyIcon, FileDocumentIcon, FileDocumentOutlineIcon, FolderIcon, OverflowTabs },

	data() {
		return {
			query: '',
			filters: {},
			// Display text per active filter, for facets whose filter value is
			// an id rather than something readable (e.g. case type).
			filterLabels: {},
			offset: 0,
			searched: false,
			activeTab: 'cases',
		}
	},

	computed: {
		limit() { return this.$store.state.searchPageSize },
		effectiveTotal() { return Math.min(this.$store.state.searchTotal, this.$store.state.searchMaxResultCount) },
		cases() { return this.$store.state.searchCases },
		documents() { return this.$store.state.searchDocuments },
		files() { return this.$store.state.searchFiles },
		total() { return this.$store.state.searchTotal },
		aggregations() { return this.$store.state.searchAggregations },
		loading() { return this.$store.state.searchLoading },
		hasAnyResults() { return this.cases.length > 0 || this.documents.length > 0 || this.files.length > 0 },
		activeFilters() { return Object.fromEntries(Object.entries(this.filters).filter(([, v]) => v)) },
		hasActiveFilters() { return Object.keys(this.activeFilters).length > 0 },
		resultTabs() {
			return [
				{ id: 'cases', label: t('opencase', 'Sager'), count: this.cases.length, icon: FolderIcon },
				{ id: 'documents', label: t('opencase', 'Dokumenter'), count: this.documents.length, icon: FileDocumentOutlineIcon },
				{ id: 'files', label: t('opencase', 'Filer'), count: this.effectiveTotal, icon: FileDocumentIcon },
			]
		},
	},

	methods: {
		async doSearch() {
			if (!this.query.trim()) return
			this.offset = 0
			this.searched = true
			await this.$store.dispatch('search', {
				query: this.query, filters: this.filters,
				limit: this.limit, offset: this.offset,
			})
			if (this[this.activeTab].length === 0) {
				if (this.cases.length > 0) this.activeTab = 'cases'
				else if (this.documents.length > 0) this.activeTab = 'documents'
				else if (this.files.length > 0) this.activeTab = 'files'
			}
		},

		setFilter(key, value, label = null) {
			this.filters[key] = value
			if (label) {
				this.filterLabels[key] = label
			} else {
				delete this.filterLabels[key]
			}
			this.doSearch()
		},

		removeFilter(key) {
			delete this.filters[key]
			delete this.filterLabels[key]
			this.doSearch()
		},

		prevPage() { this.offset = Math.max(0, this.offset - this.limit); this.doSearch() },
		nextPage() { this.offset += this.limit; this.doSearch() },

		openCase(c) {
			this.$router.push({ name: 'case-detail', params: { id: c.id } })
		},

		openDocument(doc) {
			this.$router.push({ name: 'document-detail', params: { id: String(doc.id) } })
		},

		openFile(hit) {
			this.$router.push({ name: 'document-detail', params: { id: String(hit.document_id) }, hash: '#tab-files' })
		},
	},
}
</script>

<style scoped>
.opencase-search { padding: 20px; max-width: 1200px; }
.opencase-search h2 { margin: 0 0 16px; font-size: 1.4em; }
.opencase-search__input-row { max-width: 600px; margin-bottom: 12px; }

.opencase-search__active-filters { display: flex; gap: 6px; margin-bottom: 16px; flex-wrap: wrap; }
.opencase-search__filter-chip {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 3px 10px; border-radius: 12px; font-size: 0.85em;
	cursor: pointer; transition: opacity 0.15s;
}
.opencase-search__filter-chip:hover { opacity: 0.7; }

.opencase-search__results-area { display: flex; gap: 24px; }
.opencase-search__facets { width: 220px; flex-shrink: 0; }
.opencase-search__results { flex: 1; min-width: 0; }

.opencase-search__facet-group { margin-bottom: 20px; }
.opencase-search__facet-group h4 {
	font-size: 0.8em; text-transform: uppercase; letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast); margin: 0 0 6px;
}
.opencase-search__facet-group ul { list-style: none; margin: 0; padding: 0; }
.opencase-search__facet-group li {
	display: flex; justify-content: space-between; padding: 4px 8px;
	cursor: pointer; border-radius: 4px; font-size: 0.9em;
}
.opencase-search__facet-group li:hover { background: var(--color-background-hover); }
.opencase-search__facet-count {
	color: var(--color-text-lighter); font-size: 0.85em;
}

.opencase-search__loading { margin: 40px auto; }

/* Section */
.opencase-search__section { margin-top: 16px; }
.opencase-search__section-empty {
	color: var(--color-text-lighter); font-size: 0.9em;
	padding: 16px 0;
}

/* Hit cards */
.opencase-search__hit {
	padding: 12px 14px; border: 1px solid var(--color-border);
	border-radius: 8px; margin-bottom: 8px; cursor: pointer;
	transition: border-color 0.15s, box-shadow 0.15s;
}
.opencase-search__hit:hover {
	box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.opencase-search__hit--case:hover { border-color: #4a9b6f; }
.opencase-search__hit--document:hover { border-color: #4a7abf; }
.opencase-search__hit--file:hover { border-color: var(--color-primary-element); }

.opencase-search__hit-header { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.opencase-search__hit-case { color: var(--color-text-lighter); font-size: 0.85em; margin-left: auto; }

/* Type badges */
.opencase-search__hit-badge {
	font-size: 0.72em; font-weight: 600; letter-spacing: 0.03em;
	padding: 1px 7px; border-radius: 10px; text-transform: uppercase;
	flex-shrink: 0;
}
.opencase-search__hit-badge--case { background: #d4edda; color: #2d6a4f; }
.opencase-search__hit-badge--document { background: #d0e4f7; color: #1a5276; }
.opencase-search__hit-badge--file { background: #fff3cd; color: #7d5200; }

/* Type-colored icons */
.opencase-search__hit-icon--case { color: #4a9b6f; }
.opencase-search__hit-icon--document { color: #4a7abf; }
.opencase-search__hit-icon--file { color: var(--color-primary-element); }

.opencase-search__hit-snippet { font-size: 0.9em; color: var(--color-text-lighter); line-height: 1.5; margin-bottom: 6px; }
.opencase-search__hit-snippet :deep(mark) {
	background: var(--color-warning-light, #fff3cd); padding: 0 2px; border-radius: 2px;
}
.opencase-search__hit-meta { display: flex; gap: 12px; font-size: 0.8em; color: var(--color-text-maxcontrast); }

.opencase-search__pagination { display: flex; justify-content: center; gap: 12px; margin-top: 20px; }
</style>
