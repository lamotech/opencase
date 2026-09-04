<template>
	<div class="opencase-estate-list">
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent v-else-if="estates.length === 0"
			:title="t('opencase', 'Ingen ejendomme')">
			<template #icon>
				<HomeCityIcon :size="48" />
			</template>
		</NcEmptyContent>
		<table v-else class="opencase-estate-list__table">
			<thead>
				<tr>
					<th />
					<th>{{ t('opencase', 'Rolle') }}</th>
					<th>{{ t('opencase', 'Type') }}</th>
					<th>{{ t('opencase', 'BFE Nummer') }}</th>
					<th>{{ t('opencase', 'Beliggenhedsadresse') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="estate in estates"
					:key="estate.estate_id"
					class="opencase-estate-list__row"
					@click="openEstate(estate)">
					<td>
						<HomeGroupIcon v-if="estate.type === 'Ejerlejlighed'" :size="20" />
						<HomeVariantIcon v-else-if="estate.type === 'Bygning på fremmed grund'" :size="20" />
						<HomeCityIcon v-else :size="20" />
					</td>
					<td>{{ estate.role_name || '–' }}</td>
					<td>{{ estate.type }}</td>
					<td class="opencase-estate-list__mono">{{ estate.bfenummer }}</td>
					<td>{{ estate.location_address || '–' }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import HomeCityIcon from 'vue-material-design-icons/HomeCity.vue'
import HomeGroupIcon from 'vue-material-design-icons/HomeGroup.vue'
import HomeVariantIcon from 'vue-material-design-icons/HomeVariant.vue'

import api from '../services/api.js'

export default {
	name: 'CaseEstateList',

	components: {
		NcLoadingIcon,
		NcEmptyContent,
		HomeCityIcon,
		HomeGroupIcon,
		HomeVariantIcon,
	},

	props: {
		caseId: { type: [String, Number], default: null },
	},

	data() {
		return {
			estates: [],
			loading: false,
		}
	},

	watch: {
		caseId: {
			immediate: true,
			handler() { if (this.caseId) this.load() },
		},
	},

	methods: {
		async load() {
			this.loading = true
			try {
				this.estates = await api.getCaseEstates(this.caseId)
				this.$emit('count-changed', this.estates.length)
			} finally {
				this.loading = false
			}
		},

		openEstate(estate) {
			this.$router.push({
				name: 'estate-detail',
				params: { estateId: estate.estate_id },
				query: { caseId: this.caseId },
			})
		},
	},
}
</script>

<style scoped>
.opencase-estate-list__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.95em;
}

.opencase-estate-list__table th,
.opencase-estate-list__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.opencase-estate-list__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.opencase-estate-list__row {
	cursor: pointer;
}

.opencase-estate-list__row:hover {
	background: var(--color-background-hover);
}

.opencase-estate-list__mono {
	font-family: var(--font-monospace, monospace);
}
</style>
