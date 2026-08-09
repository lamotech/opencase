<template>
	<NcDialog :name="t('opencase', 'Giv adgang')"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-grant-access">
			<div class="opencase-grant-access__field">
				<label>{{ t('opencase', 'Bruger') }} *</label>
				<NcSelect v-model="selectedUser"
					:options="userOptions"
					:loading="userSearchLoading"
					:filterable="false"
					:placeholder="t('opencase', 'Søg efter bruger')"
					@search="onUserSearch" />
				<span v-if="errors.user_id" class="opencase-grant-access__error">
					{{ errors.user_id }}
				</span>
			</div>

			<div class="opencase-grant-access__field">
				<label>{{ t('opencase', 'Adgangsniveau') }}</label>
				<div class="opencase-grant-access__radio-group">
					<label class="opencase-grant-access__radio">
						<input v-model="canWrite" type="radio" :value="false" />
						{{ t('opencase', 'Læseadgang') }}
					</label>
					<label class="opencase-grant-access__radio">
						<input v-model="canWrite" type="radio" :value="true" />
						{{ t('opencase', 'Skriveadgang') }}
					</label>
				</div>
			</div>

			<div class="opencase-grant-access__field">
				<label class="opencase-grant-access__checkbox-label">
					<input v-model="hasExpiry" type="checkbox" />
					{{ t('opencase', 'Tidsbegrænset adgang') }}
				</label>
			</div>

			<div v-if="hasExpiry" class="opencase-grant-access__field">
				<label>{{ t('opencase', 'Udløber') }}</label>
				<input v-model="expiresAt"
					type="date"
					:min="today"
					class="opencase-grant-access__date-input" />
				<span v-if="errors.expires_at" class="opencase-grant-access__error">
					{{ errors.expires_at }}
				</span>
			</div>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Annuller') }}</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencase', 'Giv adgang') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'

import api from '../services/api.js'

export default {
	name: 'GrantAccessDialog',
	components: { NcDialog, NcButton, NcSelect, NcLoadingIcon },

	props: {
		caseId: { type: Number, required: true },
	},

	data() {
		return {
			selectedUser: null,
			userOptions: [],
			userSearchLoading: false,
			userSearchTimeout: null,
			canWrite: false,
			hasExpiry: false,
			expiresAt: '',
			saving: false,
			errors: {},
		}
	},

	computed: {
		today() {
			return new Date().toISOString().slice(0, 10)
		},
	},

	methods: {
		onUserSearch(query) {
			clearTimeout(this.userSearchTimeout)
			if (!query || query.length < 2) return
			this.userSearchLoading = true
			this.userSearchTimeout = setTimeout(async () => {
				try {
					this.userOptions = await api.searchUsers(query)
				} catch (e) {
					// silently ignore
				} finally {
					this.userSearchLoading = false
				}
			}, 300)
		},

		validate() {
			this.errors = {}
			if (!this.selectedUser) {
				this.errors.user_id = t('opencase', 'Vælg en bruger')
			}
			if (this.hasExpiry && !this.expiresAt) {
				this.errors.expires_at = t('opencase', 'Angiv en udløbsdato')
			}
			if (this.hasExpiry && this.expiresAt && this.expiresAt < this.today) {
				this.errors.expires_at = t('opencase', 'Udløbsdatoen skal være i fremtiden')
			}
			return Object.keys(this.errors).length === 0
		},

		async save() {
			if (!this.validate()) return
			this.saving = true
			try {
				const expiresAt = this.hasExpiry ? this.expiresAt + 'T23:59:59' : null
				const grant = await api.grantCaseAccess(
					this.caseId,
					this.selectedUser.id,
					this.canWrite,
					expiresAt,
				)
				this.$emit('granted', grant)
			} catch (e) {
				showError(t('opencase', 'Kunne ikke give adgang'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-grant-access {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.opencase-grant-access__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.opencase-grant-access__field > label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.opencase-grant-access__radio-group {
	display: flex;
	gap: 20px;
}

.opencase-grant-access__radio {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
	font-weight: normal;
	color: var(--color-main-text);
}

.opencase-grant-access__checkbox-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-weight: normal !important;
	color: var(--color-main-text) !important;
}

.opencase-grant-access__date-input {
	height: 34px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
	padding: 0 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95em;
}

.opencase-grant-access__error {
	color: var(--color-error);
	font-size: 0.85em;
}
</style>
