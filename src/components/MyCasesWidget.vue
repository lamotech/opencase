<template>
	<div class="opencase-widget">
		<NcLoadingIcon v-if="loading" :size="30" class="opencase-widget__loading" />

		<ul v-else-if="cases.length > 0" class="opencase-widget__list">
			<li v-for="c in cases" :key="c.id" class="opencase-widget__item">
				<a :href="caseUrl(c.id)" class="opencase-widget__link">
					<span class="opencase-widget__number">{{ c.case_number }}</span>
					<span class="opencase-widget__title">{{ c.title }}</span>
					<span class="opencase-widget__date">{{ formatDate(c.updated_at) }}</span>
				</a>
			</li>
		</ul>

		<p v-else class="opencase-widget__empty">
			{{ t('opencase', 'Ingen aktive sager') }}
		</p>

		<div class="opencase-widget__footer">
			<a :href="allCasesUrl" class="opencase-widget__view-all">
				{{ t('opencase', 'Vis alle') }}
			</a>
		</div>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'MyCasesWidget',

	components: { NcLoadingIcon },

	data() {
		return {
			loading: true,
			cases: [],
		}
	},

	computed: {
		allCasesUrl() {
			return generateUrl('/apps/opencase/my-cases')
		},
	},

	mounted() {
		this.fetchCases()
	},

	methods: {
		async fetchCases() {
			const url = generateOcsUrl('/apps/opencase/api/v1') + '/widget/my-cases'
			console.log('[OpenCase widget] fetching', url)
			try {
				const response = await axios.get(url, {
					params: { limit: 8, format: 'json' },
				})
				const data = response.data?.ocs?.data ?? response.data
				this.cases = data?.cases ?? []
				console.log('[OpenCase widget] ok, cases=' + this.cases.length, 'data=', data)
			} catch (e) {
				console.error('[OpenCase widget] failed', e?.response?.status, e?.message, url)
			} finally {
				this.loading = false
			}
		},

		caseUrl(id) {
			return generateUrl(`/apps/opencase/case/${id}`)
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK')
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
	gap: 8px;
}

.opencase-widget__link:hover {
	background: var(--color-background-hover);
}

.opencase-widget__number {
	font-weight: 600;
	white-space: nowrap;
	min-width: 80px;
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.opencase-widget__title {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.opencase-widget__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
	font-size: 0.85em;
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
