<template>
	<NcDialog :name="t('opencase', 'Adviseringer')"
		size="normal"
		@update:open="v => !v && $emit('close')">
		<div class="notifications-dialog">
			<div v-if="loading" class="notifications-dialog__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<ul v-else-if="notifications.length" class="notifications-dialog__list">
				<li v-for="n in notifications" :key="n.notification_id" class="notifications-dialog__item">
					<div class="notifications-dialog__item-header">
						<strong class="notifications-dialog__subject">{{ n.subject }}</strong>
						<span class="notifications-dialog__date">{{ formatDate(n.datetime) }}</span>
					</div>
					<p v-if="n.message" class="notifications-dialog__message">{{ n.message }}</p>
					<a v-if="n.link" :href="n.link" class="notifications-dialog__link">
						{{ t('opencase', 'Åbn') }} →
					</a>
				</li>
			</ul>
			<p v-else class="notifications-dialog__empty">
				{{ t('opencase', 'Ingen adviseringer') }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Luk') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import api from '../services/api.js'

export default {
	name: 'NotificationsDialog',

	components: { NcDialog, NcButton, NcLoadingIcon },

	emits: ['close'],

	data() {
		return {
			loading: true,
			notifications: [],
		}
	},

	async mounted() {
		try {
			this.notifications = await api.getMyNotifications()
		} catch {
			this.notifications = []
		} finally {
			this.loading = false
		}
	},

	methods: {
		formatDate(datetime) {
			if (!datetime) return ''
			return new Date(datetime).toLocaleString('da-DK', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.notifications-dialog {
	min-height: 120px;
}

.notifications-dialog__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.notifications-dialog__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.notifications-dialog__item {
	padding: 14px 0;
	border-bottom: 1px solid var(--color-border);
}

.notifications-dialog__item:last-child {
	border-bottom: none;
}

.notifications-dialog__item-header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 12px;
	margin-bottom: 4px;
}

.notifications-dialog__subject {
	font-weight: 600;
	color: var(--color-main-text);
	flex: 1;
}

.notifications-dialog__date {
	font-size: 0.82em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.notifications-dialog__message {
	margin: 0 0 6px;
	color: var(--color-text-lighter);
	font-size: 0.93em;
	line-height: 1.4;
}

.notifications-dialog__link {
	font-size: 0.88em;
	color: var(--color-primary-element);
	text-decoration: none;
}

.notifications-dialog__link:hover {
	text-decoration: underline;
}

.notifications-dialog__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 32px 0;
	margin: 0;
}
</style>
