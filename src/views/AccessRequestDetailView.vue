<template>
	<div class="oc-ar-detail">
		<NcLoadingIcon v-if="loading" :size="44" class="oc-ar-detail__loading" />

		<template v-else-if="request">
			<!-- Breadcrumb -->
			<NcBreadcrumbs class="oc-ar-detail__breadcrumbs">
				<NcBreadcrumb :title="t('opencase', 'Sager')" :to="{ name: 'cases' }" />
				<NcBreadcrumb v-if="caseData" :title="caseData.case_number"
					:to="{ name: 'case-detail', params: { id: request.case_id } }" />
				<NcBreadcrumb :title="t('opencase', 'Aktindsigt')" :disableDrop="true" />
			</NcBreadcrumbs>

			<!-- Header -->
			<div class="oc-ar-detail__header">
				<div>
					<h2>{{ request.subject }}</h2>
					<div class="oc-ar-detail__meta">
						<AccessRequestStatusBadge :status="request.status" />
						<AccessDeadlineBadge :deadline-at="request.effective_deadline_at" :colour="request.deadline_colour" />
						<span class="oc-ar-detail__type">{{ typeLabel(request.type) }}</span>
					</div>
				</div>
				<NcActions>
					<NcActionButton v-for="s in availableStatusTransitions" :key="s.status" @click="changeStatus(s.status)">
						{{ s.label }}
					</NcActionButton>
					<NcActionButton @click="exportZip">
						<template #icon><DownloadIcon :size="20" /></template>
						{{ t('opencase', 'Eksporter som ZIP') }}
					</NcActionButton>
					<NcActionButton @click="markSent">
						{{ t('opencase', 'Markér afsendt') }}
					</NcActionButton>
				</NcActions>
			</div>

			<!-- Tabs -->
			<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />

			<!-- Oversigt -->
			<div v-if="activeTab === 'info'" class="oc-ar-detail__content">
				<dl class="oc-ar-detail__dl">
					<dt>{{ t('opencase', 'Sag') }}</dt>
					<dd>
						<router-link v-if="caseData" :to="{ name: 'case-detail', params: { id: request.case_id } }"
							class="oc-ar-detail__case-link">
							{{ caseData.case_number }} – {{ caseData.title }}
						</router-link>
						<span v-else-if="request.case_id">{{ request.case_id }}</span>
						<span v-else>—</span>
					</dd>
					<dt>{{ t('opencase', 'Anmoder') }}</dt>
					<dd>{{ request.requester_name ?? '—' }}</dd>
					<dt>{{ t('opencase', 'E-mail') }}</dt>
					<dd>{{ request.requester_email ?? '—' }}</dd>
					<dt>{{ t('opencase', 'Telefon') }}</dt>
					<dd>{{ request.requester_phone ?? '—' }}</dd>
					<dt>{{ t('opencase', 'CPR/CVR') }}</dt>
					<dd>{{ request.requester_identifier ?? '—' }}</dd>
					<dt>{{ t('opencase', 'Beskrivelse') }}</dt>
					<dd>{{ request.description ?? '—' }}</dd>
					<dt>{{ t('opencase', 'Sagsbehandler') }}</dt>
					<dd>{{ request.assigned_user ?? '—' }}</dd>
					<dt>{{ t('opencase', 'Modtaget') }}</dt>
					<dd>{{ formatDate(request.received_at) }}</dd>
					<dt>{{ t('opencase', 'Ordinær frist') }}</dt>
					<dd>{{ formatDate(request.deadline_at) }}</dd>
					<dt v-if="request.extended_deadline_at">{{ t('opencase', 'Forlænget frist') }}</dt>
					<dd v-if="request.extended_deadline_at">{{ formatDate(request.extended_deadline_at) }}</dd>
				</dl>
			</div>

			<!-- Dokumenter -->
			<div v-if="activeTab === 'documents'" class="oc-ar-detail__content">
				<div class="oc-ar-detail__content-header">
					<p class="oc-ar-detail__hint">{{ t('opencase', 'Søg og vælg de dokumenter der indgår i aktindsigten') }}</p>
				</div>
				<AccessDocumentTable ref="docTable" :request-id="request.id" @item-added="onItemAdded" @open-item="openItem" />

				<!-- Items list with decisions -->
				<div v-if="items.length > 0" class="oc-ar-detail__items">
					<div class="oc-ar-detail__items-header">
						<h4>{{ t('opencase', 'Valgte dokumenter') }}</h4>
						<NcSelect :modelValue="bulkDecisionSelection"
							:placeholder="t('opencase', 'Sæt beslutning for valgte')"
							:options="decisionOptions"
							:disabled="selectedItemIds.length === 0"
							label="label"
							class="oc-ar-detail__bulk-select"
							@update:modelValue="applyBulkDecision" />
					</div>
					<table class="oc-ar-detail__items-table">
						<thead>
							<tr>
								<th class="oc-ar-detail__cb-col">
									<input type="checkbox"
										:checked="allSelected"
										:indeterminate.prop="someSelected"
										@change="toggleAll" />
								</th>
								<th>{{ t('opencase', 'Dokument') }}</th>
								<th>{{ t('opencase', 'Beslutning') }}</th>
								<th>{{ t('opencase', 'Begrundelse') }}</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="item in items" :key="item.id"
								:class="{ 'oc-ar-detail__row--selected': selectedItemIds.includes(item.id) }">
								<td class="oc-ar-detail__cb-col">
									<input type="checkbox"
										:checked="selectedItemIds.includes(item.id)"
										@change="toggleItem(item.id)" />
								</td>
								<td>{{ item.title }}</td>
								<td>
									<NcSelect :modelValue="{ id: item.decision, label: decisionLabel(item.decision) }"
										:options="decisionOptions"
										size="small"
										@update:modelValue="v => updateItemDecision(item, v)" />
								</td>
								<td>
									<NcSelect :modelValue="exclusionReasonValue(item.exclusion_reason)"
										:options="exclusionReasonOptions"
										:placeholder="t('opencase', 'Begrundelse')"
										:taggable="true"
										:createOption="v => ({ id: v, label: v })"
										label="label"
										size="small"
										@update:modelValue="v => updateItemReason(item, v?.label ?? null)" />
								</td>
								<td>
									<NcButton size="small" type="tertiary-no-background" @click="removeItem(item)">
										<template #icon><DeleteIcon :size="16" /></template>
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Maskering -->
			<div v-if="activeTab === 'redactions'" class="oc-ar-detail__content">
				<NcEmptyContent v-if="items.length === 0" :title="t('opencase', 'Tilføj dokumenter først under Dokumenter-fanen')" />
				<NcLoadingIcon v-else-if="maskingFilesLoading" :size="32" class="oc-ar-detail__loading" />
				<NcEmptyContent v-else-if="maskingFiles.length === 0"
					:title="t('opencase', 'Ingen filer fundet på de valgte dokumenter')" />
				<table v-else class="oc-ar-detail__masking-table">
					<thead>
						<tr>
							<th class="oc-ar-detail__masking-status-col"></th>
							<th>{{ t('opencase', 'Dok.nr.') }}</th>
							<th>{{ t('opencase', 'Dokument') }}</th>
							<th>{{ t('opencase', 'Fil') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in maskingFiles" :key="row.item_id + ':' + row.file_id">
							<td class="oc-ar-detail__masking-status-col">
								<EraserVariantIcon v-if="row.has_masked" :size="18" class="oc-ar-detail__masked-icon"
									:title="t('opencase', 'Maskeret version uploadet')" />
							</td>
							<td class="oc-ar-detail__doc-number">{{ row.document_number ?? '—' }}</td>
							<td>{{ row.document_title }}</td>
							<td class="oc-ar-detail__file-name">{{ row.file_name }}</td>
							<td class="oc-ar-detail__masking-actions">
								<NcButton size="small" type="tertiary"
									@click="downloadOriginal(row)">
									<template #icon><DownloadIcon :size="16" /></template>
									{{ t('opencase', 'Download') }}
								</NcButton>
								<NcButton size="small" type="tertiary"
									@click="triggerMaskedUpload(row)">
									<template #icon><UploadIcon :size="16" /></template>
									{{ t('opencase', 'Upload maskeret') }}
								</NcButton>
								<NcButton v-if="row.has_masked" size="small" type="tertiary"
									@click="downloadMasked(row)">
									<template #icon><EraserVariantIcon :size="16" /></template>
									{{ t('opencase', 'Download maskeret') }}
								</NcButton>
								<input :ref="'upload-' + row.item_id + '-' + row.file_id"
									type="file"
									class="oc-ar-detail__hidden-upload"
									@change="e => onMaskedFileSelected(e, row)" />
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Afgørelse -->
			<div v-if="activeTab === 'decision'" class="oc-ar-detail__content">
				<AccessDecisionEditor :request-id="request.id" :decision="decision" @saved="onDecisionSaved" />
			</div>

			<!-- Auditlog -->
			<div v-if="activeTab === 'log'" class="oc-ar-detail__content">
				<CaseAuditLog :case-id="request.case_id" />
			</div>
		</template>
	</div>
</template>

<script>
import NcBreadcrumbs from '@nextcloud/vue/components/NcBreadcrumbs'
import NcBreadcrumb from '@nextcloud/vue/components/NcBreadcrumb'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import EraserVariantIcon from 'vue-material-design-icons/EraserVariant.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'

import AccessRequestStatusBadge from '../components/AccessRequestStatusBadge.vue'
import AccessDeadlineBadge from '../components/AccessDeadlineBadge.vue'
import AccessDocumentTable from '../components/AccessDocumentTable.vue'
import AccessDecisionEditor from '../components/AccessDecisionEditor.vue'
import CaseAuditLog from '../components/CaseAuditLog.vue'
import OverflowTabs from '../components/OverflowTabs.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import EraserIcon from 'vue-material-design-icons/Eraser.vue'
import GavelIcon from 'vue-material-design-icons/Gavel.vue'
import HistoryIcon from 'vue-material-design-icons/History.vue'

import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../services/api.js'

const TYPE_LABELS = {
	public_access: 'Offentlighedsloven',
	party_access:  'Forvaltningsloven',
	gdpr_access:   'GDPR',
}

export default {
	name: 'AccessRequestDetailView',
	components: {
		NcBreadcrumbs, NcBreadcrumb, NcLoadingIcon, NcActions, NcActionButton, NcButton,
		NcSelect,
		DownloadIcon, DeleteIcon, EraserVariantIcon, UploadIcon,
		AccessRequestStatusBadge, AccessDeadlineBadge, AccessDocumentTable,
		AccessDecisionEditor, CaseAuditLog, OverflowTabs,
	},
	props: {
		id: { type: [String, Number], required: true },
	},
	data() {
		return {
			loading: true,
			request: null,
			caseData: null,
			items: [],
			decision: null,
			activeTab: 'info',
			decisionOptions: [
				{ id: 'pending',        label: t('opencase', 'Afventer') },
				{ id: 'include',        label: t('opencase', 'Medtages') },
				{ id: 'exclude',        label: t('opencase', 'Udelades') },
				{ id: 'partly_include', label: t('opencase', 'Delvist medtages') },
			],
			exclusionReasonOptions: [],
			selectedItemIds: [],
			bulkDecisionSelection: null,
			maskingFiles: [],
			maskingFilesLoading: false,
		}
	},
	computed: {
		allSelected() {
			return this.items.length > 0 && this.selectedItemIds.length === this.items.length
		},
		someSelected() {
			return this.selectedItemIds.length > 0 && this.selectedItemIds.length < this.items.length
		},
		tabs() {
			return [
				{ id: 'info',      label: t('opencase', 'Oversigt'),    icon: InformationOutlineIcon },
				{ id: 'documents', label: t('opencase', 'Dokumenter'),  count: this.items.length || null, icon: FileDocumentOutlineIcon },
				{ id: 'redactions', label: t('opencase', 'Maskering'),  icon: EraserIcon },
				{ id: 'decision',  label: t('opencase', 'Afgørelse'),   icon: GavelIcon },
				{ id: 'log',       label: t('opencase', 'Log'),         icon: HistoryIcon },
			]
		},
		availableStatusTransitions() {
			const map = {
				received:       [{ status: 'collecting', label: t('opencase', 'Start indsamling') }],
				collecting:     [{ status: 'reviewing',  label: t('opencase', 'Start vurdering') }],
				reviewing:      [{ status: 'redacting',  label: t('opencase', 'Start maskering') }],
				redacting:      [{ status: 'approval',   label: t('opencase', 'Send til godkendelse') }],
				approval:       [{ status: 'sent',       label: t('opencase', 'Markér afsendt') }, { status: 'redacting', label: t('opencase', 'Retur til maskering') }],
			}
			return map[this.request?.status] ?? []
		},
	},
	watch: {
		id: { immediate: true, handler() { this.reload() } },
		activeTab(tab) {
			if (tab === 'redactions' && this.items.length > 0 && this.maskingFiles.length === 0) {
				this.loadMaskingFiles()
			}
		},
	},
	async mounted() {
		try {
			const result = await api.getAccessExclusionReasons()
			this.exclusionReasonOptions = result.reasons ?? []
		} catch { /* non-fatal */ }
	},
	methods: {
		async reload() {
			this.loading = true
			this.maskingFiles = []
			try {
				const result = await api.getAccessRequest(this.id)
				this.request  = result.access_request
				this.items    = result.items ?? []
				this.decision = result.decision ?? null
				if (this.request.case_id) {
					api.getCase(this.request.case_id, { audit: false }).then(c => { this.caseData = c }).catch(() => {})
				}
				if (this.activeTab === 'redactions' && this.items.length > 0) {
					this.loadMaskingFiles()
				}
			} catch {
				showError(t('opencase', 'Kunne ikke indlæse aktindsigtssagen'))
			} finally {
				this.loading = false
			}
		},

		async loadMaskingFiles() {
			this.maskingFilesLoading = true
			try {
				this.maskingFiles = await api.getAccessMaskingFiles(this.request.id)
			} catch {
				showError(t('opencase', 'Kunne ikke hente filer'))
			} finally {
				this.maskingFilesLoading = false
			}
		},
		exclusionReasonValue(reason) {
			if (!reason) return null
			return { id: reason, label: reason }
		},
		typeLabel(type) { return t('opencase', TYPE_LABELS[type] ?? type) },
		decisionLabel(d) {
			const m = { pending: 'Afventer', include: 'Medtages', exclude: 'Udelades', partly_include: 'Delvist medtages' }
			return t('opencase', m[d] ?? d)
		},
		formatDate(iso) {
			if (!iso) return '—'
			return new Date(iso).toLocaleDateString('da-DK', { day: '2-digit', month: '2-digit', year: 'numeric' })
		},
		async changeStatus(status) {
			try {
				const result = await api.changeAccessRequestStatus(this.request.id, status)
				this.request = result
				showSuccess(t('opencase', 'Status opdateret'))
			} catch {
				showError(t('opencase', 'Status kunne ikke ændres'))
			}
		},
		async updateItemDecision(item, val) {
			try {
				const updated = await api.updateAccessItem(item.id, { decision: val?.id })
				const idx = this.items.findIndex(i => i.id === item.id)
				if (idx >= 0) this.items.splice(idx, 1, { ...item, ...updated })
			} catch {
				showError(t('opencase', 'Opdatering fejlede'))
			}
		},
		async updateItemReason(item, reason) {
			try {
				await api.updateAccessItem(item.id, { exclusion_reason: reason })
			} catch {
				showError(t('opencase', 'Opdatering fejlede'))
			}
		},
		toggleAll() {
			this.selectedItemIds = this.allSelected ? [] : this.items.map(i => i.id)
		},
		toggleItem(id) {
			if (this.selectedItemIds.includes(id)) {
				this.selectedItemIds = this.selectedItemIds.filter(i => i !== id)
			} else {
				this.selectedItemIds = [...this.selectedItemIds, id]
			}
		},
		async applyBulkDecision(val) {
			if (!val || this.selectedItemIds.length === 0) return
			for (const id of [...this.selectedItemIds]) {
				const item = this.items.find(i => i.id === id)
				if (item) await this.updateItemDecision(item, val)
			}
			this.$nextTick(() => { this.bulkDecisionSelection = null })
		},
		async removeItem(item) {
			try {
				await api.removeAccessItem(item.id)
				this.items = this.items.filter(i => i.id !== item.id)
				this.selectedItemIds = this.selectedItemIds.filter(id => id !== item.id)
				this.maskingFiles = this.maskingFiles.filter(f => f.item_id !== item.id)
				this.$refs.docTable?.markRemoved(item.id)
			} catch {
				showError(t('opencase', 'Sletning fejlede'))
			}
		},
		onItemAdded(item) {
			if (!this.items.find(i => i.id === item.id)) {
				this.items.push(item)
			}
		},
		openItem(itemId) {
			this.activeTab = 'redactions'
		},
		downloadOriginal(row) {
			api.downloadFile(row.file_id, row.file_name)
		},
		triggerMaskedUpload(row) {
			const ref = this.$refs['upload-' + row.item_id + '-' + row.file_id]
			const el = Array.isArray(ref) ? ref[0] : ref
			el?.click()
		},
		async onMaskedFileSelected(event, row) {
			const file = event.target.files[0]
			if (!file) return
			try {
				await api.uploadAccessMasked(row.item_id, row.file_id, file)
				row.has_masked = true
				showSuccess(t('opencase', 'Maskeret fil uploadet'))
			} catch {
				showError(t('opencase', 'Upload fejlede'))
			}
			event.target.value = ''
		},
		downloadMasked(row) {
			api.downloadAccessMasked(row.item_id, row.file_id, row.file_name)
		},
		onDecisionSaved(dec) {
			this.decision = dec
		},
		async exportZip() {
			try {
				window.location = api.getAccessExportUrl(this.request.id)
			} catch {
				showError(t('opencase', 'Eksport fejlede'))
			}
		},
		async markSent() {
			try {
				const result = await api.markAccessRequestSent(this.request.id)
				this.request = result
				showSuccess(t('opencase', 'Markeret som afsendt'))
			} catch {
				showError(t('opencase', 'Fejl'))
			}
		},
	},
}
</script>

<style scoped>
.oc-ar-detail { padding: 20px; }
.oc-ar-detail__loading { display: block; margin: 40px auto; }
.oc-ar-detail__breadcrumbs { margin-bottom: 8px; }
.oc-ar-detail__header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.oc-ar-detail__meta { display: flex; align-items: center; gap: 10px; margin-top: 6px; flex-wrap: wrap; }
.oc-ar-detail__type { font-size: 0.85em; color: var(--color-text-maxcontrast); }
.oc-ar-detail__content { padding: 16px 0; }
.oc-ar-detail__content-header { margin-bottom: 16px; }
.oc-ar-detail__hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin-bottom: 8px; }
.oc-ar-detail__dl { display: grid; grid-template-columns: 180px 1fr; gap: 4px 16px; max-width: 700px; }
.oc-ar-detail__dl dt { font-weight: 600; color: var(--color-text-maxcontrast); align-self: start; padding: 4px 0; }
.oc-ar-detail__dl dd { padding: 4px 0; }
.oc-ar-detail__case-link { font-weight: 500; color: var(--color-primary-element); text-decoration: none; }
.oc-ar-detail__case-link:hover { text-decoration: underline; }
.oc-ar-detail__items { margin-top: 24px; }
.oc-ar-detail__items-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; gap: 16px; }
.oc-ar-detail__items-header h4 { margin: 0; }
.oc-ar-detail__bulk-select { min-width: 240px; }
.oc-ar-detail__items-table { width: 100%; border-collapse: collapse; }
.oc-ar-detail__items-table th,
.oc-ar-detail__items-table td { padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
.oc-ar-detail__items-table th { font-weight: 600; background: var(--color-background-hover); }
.oc-ar-detail__cb-col { width: 36px; padding-inline: 12px 4px; }
.oc-ar-detail__row--selected { background: var(--color-background-hover); }
.oc-ar-detail__item-selector { margin-bottom: 16px; display: flex; align-items: center; gap: 12px; }
.oc-ar-detail__masking-table { width: 100%; border-collapse: collapse; }
.oc-ar-detail__masking-table th,
.oc-ar-detail__masking-table td { padding: 8px 12px; border-bottom: 1px solid var(--color-border); text-align: left; vertical-align: middle; }
.oc-ar-detail__masking-table th { font-weight: 600; background: var(--color-background-hover); }
.oc-ar-detail__masking-status-col { width: 32px; padding-inline: 12px 4px; text-align: center; }
.oc-ar-detail__masked-icon { color: var(--color-primary-element); }
.oc-ar-detail__doc-number { white-space: nowrap; color: var(--color-text-maxcontrast); font-size: 0.88em; }
.oc-ar-detail__file-name { font-family: monospace; font-size: 0.88em; color: var(--color-text-maxcontrast); }
.oc-ar-detail__masking-actions { text-align: right; white-space: nowrap; }
.oc-ar-detail__hidden-upload { display: none; }
</style>
