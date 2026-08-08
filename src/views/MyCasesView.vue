<template>
	<div class="opencase-my-cases">
		<div class="opencase-my-cases__header">
			<h2>{{ t('opencase', 'Mine sager') }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" class="opencase-my-cases__loading" />

		<NcEmptyContent v-else-if="cases.length === 0"
			:title="t('opencase', 'Ingen aktive sager')"
			:description="t('opencase', 'Du har ingen åbne sager, hvor du er ansvarlig.')">
			<template #icon>
				<FolderIcon :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="opencase-my-cases__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Sagsnummer') }}</th>
					<th>{{ t('opencase', 'Sagstype') }}</th>
					<th>{{ t('opencase', 'Titel') }}</th>
					<th>{{ t('opencase', 'Organisation') }}</th>
					<th>{{ t('opencase', 'Status') }}</th>
					<th>{{ t('opencase', 'Opdateret') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="c in cases"
					:key="c.id"
					class="opencase-my-cases__row"
					@click="openCase(c.id)">
					<td class="opencase-my-cases__number">{{ c.case_number }}</td>
					<td>{{ c.casetype_name }}</td>
					<td class="opencase-my-cases__title">{{ c.title }}</td>
					<td>{{ c.organisation }}</td>
					<td>
						<CaseStatusBadge :status="c.status_class" :label="c.status_name" />
					</td>
					<td class="opencase-my-cases__date">{{ formatDate(c.updated_at) }}</td>
				</tr>
			</tbody>
		</table>

		<div v-if="total > limit" class="opencase-my-cases__pagination">
			<NcButton :disabled="offset === 0" @click="prevPage">
				{{ t('opencase', 'Forrige') }}
			</NcButton>
			<span class="opencase-my-cases__page-info">
				{{ t('opencase', '{start}–{end} af {total}', {
					start: offset + 1,
					end: Math.min(offset + limit, total),
					total,
				}) }}
			</span>
			<NcButton :disabled="offset + limit >= total" @click="nextPage">
				{{ t('opencase', 'Næste') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import CaseStatusBadge from '../components/CaseStatusBadge.vue'
import api from '../services/api.js'

export default {
	name: 'MyCasesView',

	components: { NcButton, NcEmptyContent, NcLoadingIcon, FolderIcon, CaseStatusBadge },

	data() {
		return {
			cases: [],
			total: 0,
			loading: true,
			limit: 50,
			offset: 0,
		}
	},

	mounted() {
		this.fetchCases()
	},

	methods: {
		async fetchCases() {
			this.loading = true
			try {
				const data = await api.getMyCases({ limit: this.limit, offset: this.offset })
				this.cases = data.cases ?? []
				this.total = data.total ?? 0
			} catch (e) {
				console.error('Error loading my cases', e)
			} finally {
				this.loading = false
			}
		},

		openCase(id) {
			this.$router.push({ name: 'case-detail', params: { id } })
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleDateString('da-DK')
		},

		prevPage() {
			this.offset = Math.max(0, this.offset - this.limit)
			this.fetchCases()
		},

		nextPage() {
			this.offset += this.limit
			this.fetchCases()
		},
	},
}
</script>

<style scoped>
.opencase-my-cases__header {
	display: flex;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.opencase-my-cases__loading {
	margin: 40px auto;
	display: flex;
	justify-content: center;
}

.opencase-my-cases__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-my-cases__table th,
.opencase-my-cases__table td {
	padding: 10px 16px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.opencase-my-cases__row {
	cursor: pointer;
}

.opencase-my-cases__row:hover {
	background: var(--color-background-hover);
}

.opencase-my-cases__number {
	font-weight: 600;
	white-space: nowrap;
}

.opencase-my-cases__date {
	white-space: nowrap;
	color: var(--color-text-lighter);
}

.opencase-my-cases__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 16px;
}
</style>
