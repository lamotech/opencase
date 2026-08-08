<template>
	<li class="org-tree__node">
		<div class="org-tree__label"
			:class="{
				'org-tree__label--highlight': isUserOrg,
				'org-tree__label--has-children': hasChildren,
			}"
			@click="toggle">
			<span class="org-tree__toggle-icon">
				<ChevronRightIcon v-if="hasChildren" :size="16" :class="{ 'org-tree__chevron--open': isOpen }" />
				<span v-else class="org-tree__leaf-indent" />
			</span>
			<OfficeBuildingIcon :size="16" class="org-tree__org-icon" />
			<span class="org-tree__name">{{ node.org_name }}</span>
			<span v-for="role in userRoles" :key="role" class="org-tree__role-badge">{{ role }}</span>
		</div>
		<ul v-show="isOpen && hasChildren" class="org-tree__children">
			<OrgTreeNode v-for="child in children"
				:key="child.org_uuid"
				:node="child"
				:children-map="childrenMap"
				:user-org-uuids="userOrgUuids"
				:user-org-roles="userOrgRoles"
				:auto-open-uuids="autoOpenUuids" />
		</ul>
	</li>
</template>

<script>
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import OfficeBuildingIcon from 'vue-material-design-icons/OfficeBuilding.vue'

export default {
	name: 'OrgTreeNode',

	components: {
		ChevronRightIcon,
		OfficeBuildingIcon,
	},

	props: {
		node: { type: Object, required: true },
		childrenMap: { type: Object, required: true },
		userOrgUuids: { type: Object, required: true },
		userOrgRoles: { type: Object, required: true },
		autoOpenUuids: { type: Object, required: true },
	},

	data() {
		return {
			isOpen: !!this.autoOpenUuids[this.node.org_uuid],
		}
	},

	computed: {
		children() {
			return this.childrenMap[this.node.org_uuid] || []
		},
		hasChildren() {
			return this.children.length > 0
		},
		isUserOrg() {
			return !!this.userOrgUuids[this.node.org_uuid]
		},
		userRoles() {
			return this.userOrgRoles[this.node.org_uuid] || []
		},
	},

	methods: {
		toggle() {
			if (this.hasChildren) {
				this.isOpen = !this.isOpen
			}
		},
	},
}
</script>

<style scoped>
.org-tree__node {
	list-style: none;
}

.org-tree__label {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 5px 8px;
	border-radius: 6px;
	cursor: default;
	user-select: none;
	transition: background 0.12s;
}

.org-tree__label--has-children {
	cursor: pointer;
}

.org-tree__label:hover {
	background: var(--color-background-hover);
}

.org-tree__label--highlight {
	background: var(--color-primary-element-light, #e8f0fe);
	font-weight: 600;
}

.org-tree__label--highlight:hover {
	background: var(--color-primary-element-light-hover, #d0e2fc);
}

.org-tree__toggle-icon {
	display: flex;
	align-items: center;
	width: 18px;
	flex-shrink: 0;
}

.org-tree__chevron--open {
	transform: rotate(90deg);
}

.org-tree__leaf-indent {
	display: inline-block;
	width: 16px;
}

.org-tree__org-icon {
	flex-shrink: 0;
	opacity: 0.6;
}

.org-tree__name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.org-tree__role-badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.75em;
	font-weight: 600;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	white-space: nowrap;
}

.org-tree__children {
	margin: 0;
	padding: 0 0 0 22px;
}
</style>
