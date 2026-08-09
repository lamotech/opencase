<template>
	<NcModal v-if="show"
		class="opencase-file-log-modal"
		:name="t('opencase', 'Log for {name}', { name: file.original_filename || '' })"
		size="normal"
		@close="$emit('close')">
		<div class="file-audit-log-dialog">
			<h3 class="file-audit-log-dialog__title">
				{{ t('opencase', 'Log for {name}', { name: file.original_filename || '' }) }}
			</h3>
			<FileAuditLog v-if="file.id" :file-id="file.id" />
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import FileAuditLog from './FileAuditLog.vue'

export default {
	name: 'FileAuditLogDialog',
	components: { NcModal, FileAuditLog },
	props: {
		show: { type: Boolean, required: true },
		file: { type: Object, required: true },
	},
	emits: ['close'],
}
</script>

<style scoped>
.file-audit-log-dialog {
	padding: 20px 24px 24px;
	min-width: 420px;
	max-height: 70vh;
	overflow-y: auto;
}

.file-audit-log-dialog__title {
	margin: 0 0 16px;
	font-size: 1rem;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>

<style>
.modal-mask.opencase-file-log-modal {
	background-color: rgba(0, 0, 0, 0.25);
}
</style>
