<template>
	<div class="hierarchy-node">
		<div class="hierarchy-node__row"
			:class="{ 'hierarchy-node__row--current': node.is_current }"
			@click="navigate">
			<span class="hierarchy-node__icon">
				<FolderIcon :size="18" />
			</span>
			<span class="hierarchy-node__number">{{ node.case_number }}</span>
			<span class="hierarchy-node__title">{{ node.title }}</span>
			<span class="hierarchy-node__badge" :class="statusClass">{{ statusLabel }}</span>
		</div>
		<div v-if="node.children && node.children.length" class="hierarchy-node__children">
			<CaseHierarchyNode v-for="child in node.children" :key="child.id" :node="child" />
		</div>
	</div>
</template>

<script>
import FolderIcon from 'vue-material-design-icons/Folder.vue'

export default {
	name: 'CaseHierarchyNode',

	components: {
		FolderIcon,
	},

	props: {
		node: {
			type: Object,
			required: true,
		},
	},

	computed: {
		statusClass() {
			const map = { 1: 'open', 2: 'closed', 3: 'archived' }
			return `hierarchy-node__badge--${map[this.node.status_id] || 'unknown'}`
		},
		statusLabel() {
			const map = {
				1: t('opencase', 'Åben'),
				2: t('opencase', 'Lukket'),
				3: t('opencase', 'Arkiveret'),
			}
			return map[this.node.status_id] || ''
		},
	},

	methods: {
		navigate() {
			if (!this.node.is_current) {
				this.$router.push({ name: 'case-detail', params: { id: String(this.node.id) } })
			}
		},
	},
}
</script>

<style scoped>
.hierarchy-node {
	display: flex;
	flex-direction: column;
}

.hierarchy-node__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 7px 10px;
	border-radius: 6px;
	cursor: pointer;
	transition: background 0.15s;
	user-select: none;
}

.hierarchy-node__row:hover {
	background: var(--color-background-hover);
}

.hierarchy-node__row--current {
	background: var(--color-primary-element-light);
	font-weight: 600;
	cursor: default;
}

.hierarchy-node__row--current:hover {
	background: var(--color-primary-element-light);
}

.hierarchy-node__icon {
	color: var(--color-text-lighter);
	flex-shrink: 0;
	display: flex;
}

.hierarchy-node__number {
	font-family: var(--font-monospace, monospace);
	font-size: 0.85em;
	color: var(--color-text-lighter);
	flex-shrink: 0;
}

.hierarchy-node__title {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.hierarchy-node__badge {
	font-size: 0.85em;
	font-weight: 600;
	padding: 3px 10px;
	border-radius: 10px;
	flex-shrink: 0;
}

.hierarchy-node__badge--open {
	background: #e8f5e9;
	color: #2e7d32;
}

.hierarchy-node__badge--closed {
	background: #fce4ec;
	color: #c62828;
}

.hierarchy-node__badge--archived {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.hierarchy-node__children {
	padding-left: 28px;
	border-left: 2px solid var(--color-border);
	margin-left: 18px;
	margin-top: 2px;
	margin-bottom: 2px;
}
</style>
