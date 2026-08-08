<template>
	<div class="oc-ar-list">
		<div class="oc-ar-list__header">
			<h2>{{ t('opencase', 'Aktindsigter') }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" class="oc-ar-list__loading" />

		<NcEmptyContent v-else-if="requests.length === 0"
			:title="t('opencase', 'Ingen aktindsigtsanmodninger')">
			<template #icon>
				<FileEyeOutlineIcon :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="oc-ar-list__table">
			<thead>
				<tr>
					<th>{{ t('opencase', 'Emne') }}</th>
					<th>{{ t('opencase', 'Type') }}</th>
					<th>{{ t('opencase', 'Anmoder') }}</th>
					<th>{{ t('opencase', 'Status') }}</th>
					<th>{{ t('opencase', 'Frist') }}</th>
					<th>{{ t('opencase', 'Sagsbehandler') }}</th>
					<th>{{ t('opencase', 'Modtaget') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="req in requests" :key="req.id"
					class="oc-ar-list__row"
					@click="openRequest(req)">
					<td class="oc-ar-list__subject">{{ req.subject }}</td>
					<td>{{ typeLabel(req.type) }}</td>
					<td>{{ req.requester_name ?? '—' }}</td>
					<td><AccessRequestStatusBadge :status="req.status" /></td>
					<td><AccessDeadlineBadge :deadline-at="req.effective_deadline_at" :colour="req.deadline_colour" /></td>
					<td>{{ req.assigned_user ?? '—' }}</td>
					<td>{{ formatDate(req.received_at) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import FileEyeOutlineIcon from 'vue-material-design-icons/FileEyeOutline.vue'
import AccessRequestStatusBadge from '../components/AccessRequestStatusBadge.vue'
import AccessDeadlineBadge from '../components/AccessDeadlineBadge.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

const TYPE_LABELS = {
	public_access: 'Offentlighedsloven',
	party_access:  'Forvaltningsloven',
	gdpr_access:   'GDPR',
}

export default {
	name: 'AccessRequestListView',
	components: { NcLoadingIcon, NcEmptyContent, FileEyeOutlineIcon, AccessRequestStatusBadge, AccessDeadlineBadge },
	props: {
		caseId: { type: [Number, String], required: true },
	},
	data() {
		return {
			loading: true,
			requests: [],
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			this.loading = true
			try {
				const result = await api.getAccessRequests(this.caseId)
				this.requests = result.access_requests ?? []
			} catch {
				showError(t('opencase', 'Kunne ikke indlæse aktindsigter'))
			} finally {
				this.loading = false
			}
		},
		typeLabel(type) { return t('opencase', TYPE_LABELS[type] ?? type) },
		formatDate(iso) {
			if (!iso) return '—'
			return new Date(iso).toLocaleDateString('da-DK', { day: '2-digit', month: '2-digit', year: 'numeric' })
		},
		openRequest(req) {
			this.$router.push({ name: 'access-request-detail', params: { id: req.id } })
		},
	},
}
</script>

<style scoped>
.oc-ar-list__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.oc-ar-list__loading { display: block; margin: 40px auto; }
.oc-ar-list__table { width: 100%; border-collapse: collapse; }
.oc-ar-list__table th,
.oc-ar-list__table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid var(--color-border); }
.oc-ar-list__table th { font-weight: 600; background: var(--color-background-hover); }
.oc-ar-list__row { cursor: pointer; }
.oc-ar-list__row:hover { background: var(--color-background-hover); }
.oc-ar-list__subject { font-weight: 500; max-width: 260px; }
</style>
