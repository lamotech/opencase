<template>
	<NcActions v-if="recentPrompts.length > 0">
		<template #icon>
			<AutoFixIcon :size="20" />
		</template>
		<NcActionCaption :name="t('opencase', 'AI handlinger')" />
		<NcActionButton v-for="p in recentPrompts"
			:key="p.id"
			@click="open(p)">
			<template #icon>
				<AutoFixIcon :size="20" />
			</template>
			{{ p.title }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue'

import api from '../services/api.js'

export default {
	name: 'AiActionsMenu',

	components: { NcActions, NcActionButton, NcActionCaption, AutoFixIcon },

	props: {
		scope: { type: String, required: true },
	},

	data() {
		return {
			recentPrompts: [],
		}
	},

	watch: {
		scope: {
			immediate: true,
			async handler(scope) {
				try {
					this.recentPrompts = await api.getRecentAiPrompts(scope)
				} catch {
					this.recentPrompts = []
				}
			},
		},
	},

	methods: {
		open(prompt) {
			emit('ai:open-prompt', prompt.id)
		},
	},
}
</script>
