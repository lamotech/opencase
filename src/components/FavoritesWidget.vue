<template>
	<div class="opencase-widget">
		<NcLoadingIcon v-if="loading" :size="30" class="opencase-widget__loading" />

		<ul v-else-if="items.length > 0" class="opencase-widget__list">
			<li v-for="item in items" :key="item.entity + ':' + item.id" class="opencase-widget__item">
				<a :href="itemUrl(item)" class="opencase-widget__link">
					<span class="opencase-widget__icon-wrap">
						<FolderIcon v-if="item.entity === 'case'" :size="18" class="opencase-widget__icon opencase-widget__icon--case" />
						<FileDocumentOutlineIcon v-else :size="18" class="opencase-widget__icon opencase-widget__icon--doc" />
					</span>
					<span class="opencase-widget__text">
						<span class="opencase-widget__title">{{ item.title }}</span>
						<span class="opencase-widget__subtitle">{{ item.subtitle }}</span>
					</span>
				</a>
			</li>
		</ul>

		<p v-else class="opencase-widget__empty">
			{{ t('opencase', 'Ingen favoritter endnu') }}
		</p>

		<div class="opencase-widget__footer">
			<a :href="allFavoritesUrl" class="opencase-widget__view-all">
				{{ t('opencase', 'Vis alle') }}
			</a>
		</div>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'FavoritesWidget',

	components: { NcLoadingIcon, FolderIcon, FileDocumentOutlineIcon },

	data() {
		return {
			loading: true,
			items: [],
		}
	},

	computed: {
		allFavoritesUrl() {
			return generateUrl('/apps/opencase/favorites')
		},
	},

	mounted() {
		this.fetchFavorites()
	},

	methods: {
		async fetchFavorites() {
			const url = generateOcsUrl('/apps/opencase/api/v1') + '/widget/favorites'
			try {
				const response = await axios.get(url, {
					params: { limit: 8, format: 'json' },
				})
				const data = response.data?.ocs?.data ?? response.data
				this.items = data?.items ?? []
			} catch (e) {
				console.error('[OpenCase favorites widget] failed', e?.response?.status, e?.message)
			} finally {
				this.loading = false
			}
		},

		itemUrl(item) {
			if (item.entity === 'case') {
				return generateUrl(`/apps/opencase/case/${item.id}`)
			}
			return generateUrl(`/apps/opencase/document/${item.id}`)
		},
	},
}
</script>

<style scoped>
.opencase-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.opencase-widget__loading {
	margin: 20px auto;
}

.opencase-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	flex: 1;
	overflow-y: auto;
}

.opencase-widget__item {
	border-bottom: 1px solid var(--color-border);
}

.opencase-widget__link {
	display: flex;
	align-items: center;
	padding: 8px 12px;
	text-decoration: none;
	color: var(--color-main-text);
	gap: 10px;
}

.opencase-widget__link:hover {
	background: var(--color-background-hover);
}

.opencase-widget__icon-wrap {
	flex-shrink: 0;
	display: flex;
	align-items: center;
}

.opencase-widget__icon--case {
	color: var(--color-warning);
}

.opencase-widget__icon--doc {
	color: var(--color-primary-element);
}

.opencase-widget__text {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.opencase-widget__title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: 500;
}

.opencase-widget__subtitle {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.opencase-widget__empty {
	padding: 20px 12px;
	color: var(--color-text-lighter);
	text-align: center;
	flex: 1;
}

.opencase-widget__footer {
	padding: 8px 12px;
	text-align: center;
	border-top: 1px solid var(--color-border);
	margin-top: auto;
}

.opencase-widget__view-all {
	color: var(--color-primary-element);
	text-decoration: none;
	font-weight: 500;
}

.opencase-widget__view-all:hover {
	text-decoration: underline;
}
</style>
