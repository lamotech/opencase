<template>
	<div class="opencase-favorites">
		<h2>{{ pageTitle }}</h2>

		<NcLoadingIcon v-if="loading" :size="44" class="opencase-favorites__loading" />

		<NcEmptyContent v-else-if="!hasFavorites"
			:title="t('opencase', 'Ingen favoritter endnu')"
			:description="t('opencase', 'Tilføj sager og dokumenter til dine favoritter ved at klikke på stjerneikonet.')">
			<template #icon>
				<StarOutlineIcon :size="64" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Cases -->
			<section v-if="cases.length > 0 && entityFilter !== 'document'" class="opencase-favorites__section">
				<h3 class="opencase-favorites__section-title">
					<FolderIcon :size="18" />
					{{ t('opencase', 'Sager') }}
				</h3>
				<table class="opencase-favorites__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Sagsnummer') }}</th>
							<th>{{ t('opencase', 'Sagstype') }}</th>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Organisation') }}</th>
							<th>{{ t('opencase', 'Status') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="c in cases"
							:key="c.id"
							class="opencase-favorites__row"
							@click="$router.push({ name: 'case-detail', params: { id: c.id } })">
							<td class="opencase-favorites__mono">{{ c.case_number }}</td>
							<td class="opencase-favorites__lighter">{{ c.casetype_name }}</td>
							<td class="opencase-favorites__title">{{ c.title }}</td>
							<td class="opencase-favorites__lighter">{{ c.organisation }}</td>
							<td>
								<CaseStatusBadge :status="c.status_class" :label="c.status_name" />
							</td>
							<td class="opencase-favorites__fav-cell" @click.stop>
								<FavoriteButton entity="case" :entity-key="c.id" />
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- Documents -->
			<section v-if="documents.length > 0 && entityFilter !== 'case'" class="opencase-favorites__section">
				<h3 class="opencase-favorites__section-title">
					<FileDocumentOutlineIcon :size="18" />
					{{ t('opencase', 'Dokumenter') }}
				</h3>
				<table class="opencase-favorites__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Dokumentnummer') }}</th>
							<th>{{ t('opencase', 'Titel') }}</th>
							<th>{{ t('opencase', 'Type') }}</th>
							<th>{{ t('opencase', 'Oprettet') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="doc in documents"
							:key="doc.id"
							class="opencase-favorites__row"
							@click="$router.push({ name: 'document-detail', params: { id: doc.id } })">
							<td class="opencase-favorites__mono">{{ doc.document_number }}</td>
							<td class="opencase-favorites__title">{{ doc.title }}</td>
							<td class="opencase-favorites__lighter">{{ doc.document_type }}</td>
							<td class="opencase-favorites__lighter">{{ formatDate(doc.created_at) }}</td>
							<td class="opencase-favorites__fav-cell" @click.stop>
								<FavoriteButton entity="document" :entity-key="doc.id" />
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'

import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import FavoriteButton from '../components/FavoriteButton.vue'

import api from '../services/api.js'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'FavoritesView',

	props: {
		entity: {
			type: String,
			default: null,
		},
	},

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		StarOutlineIcon,
		FolderIcon,
		FileDocumentOutlineIcon,
		CaseStatusBadge,
		FavoriteButton,
	},

	data() {
		return {
			loading: true,
			cases: [],
			documents: [],
		}
	},

	computed: {
		favorites() {
			return this.$store.state.favorites
		},
		entityFilter() {
			return this.entity
		},
		hasFavorites() {
			if (this.entityFilter === 'case') return this.cases.length > 0
			if (this.entityFilter === 'document') return this.documents.length > 0
			return this.cases.length > 0 || this.documents.length > 0
		},
		pageTitle() {
			if (this.entityFilter === 'case') return t('opencase', 'Favorittsager')
			if (this.entityFilter === 'document') return t('opencase', 'Favoritdokumenter')
			return t('opencase', 'Favoritter')
		},
	},

	watch: {
		// Reload when favorites change (e.g. user removes one from this page)
		favorites: {
			deep: true,
			handler() {
				this.syncRemovals()
			},
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		async load() {
			this.loading = true
			try {
				await this.$store.dispatch('fetchFavorites')
				const favs = this.$store.state.favorites
				const caseFavs = favs.filter(f => f.entity === 'case')
				const docFavs  = favs.filter(f => f.entity === 'document')

				const [caseResults, docResults] = await Promise.all([
					Promise.allSettled(caseFavs.map(f => api.getCase(f.key, { audit: false }))),
					Promise.allSettled(docFavs.map(f => api.getDocument(f.key, { audit: false }))),
				])

				const caseAddedAt = Object.fromEntries(caseFavs.map(f => [f.key, f.added_at]))
				const docAddedAt  = Object.fromEntries(docFavs.map(f => [f.key, f.added_at]))

				this.cases = caseResults
					.filter(r => r.status === 'fulfilled')
					.map(r => r.value)
					.sort((a, b) => (caseAddedAt[b.id] ?? '') > (caseAddedAt[a.id] ?? '') ? 1 : -1)

				this.documents = docResults
					.filter(r => r.status === 'fulfilled')
					.map(r => r.value)
					.sort((a, b) => (docAddedAt[b.id] ?? '') > (docAddedAt[a.id] ?? '') ? 1 : -1)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke indlæse favoritter'))
			} finally {
				this.loading = false
			}
		},

		// Remove items from local lists when unfavorited while on this page
		syncRemovals() {
			const favKeys = new Set(
				this.$store.state.favorites.map(f => `${f.entity}:${f.key}`)
			)
			this.cases     = this.cases.filter(c => favKeys.has(`case:${c.id}`))
			this.documents = this.documents.filter(d => favKeys.has(`document:${d.id}`))
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.opencase-favorites {
	padding: 20px;
	max-width: 1000px;
}

.opencase-favorites h2 {
	margin: 0 0 20px;
	font-size: 1.4em;
}

.opencase-favorites__loading {
	margin: 60px auto;
}

.opencase-favorites__section {
	margin-bottom: 36px;
}

.opencase-favorites__section-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin: 0 0 10px;
}

.opencase-favorites__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-favorites__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 2px solid var(--color-border);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	text-transform: uppercase;
}

.opencase-favorites__row {
	cursor: pointer;
}

.opencase-favorites__row:hover {
	background: var(--color-background-hover);
}

.opencase-favorites__row td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: middle;
}

.opencase-favorites__mono {
	font-family: var(--font-monospace, monospace);
	font-size: 0.85em;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.opencase-favorites__title {
	font-weight: 500;
}

.opencase-favorites__lighter {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.opencase-favorites__fav-cell {
	width: 44px;
	text-align: center;
	padding: 4px !important;
}
</style>
