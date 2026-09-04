<template>
	<NcDialog :name="t('opencase', 'Vælg ejendom')"
		size="normal"
		@update:open="v => !v && $emit('close')">
		<div class="select-estate">
			<div class="select-estate__field">
				<label>{{ t('opencase', 'BFE Nummer') }}</label>
				<NcTextField v-model="bfenumber"
					:placeholder="t('opencase', 'Søg på BFE-nummer')"
					@keydown.enter="search" />
			</div>

			<NcButton type="primary"
				:disabled="searching || bfenumber.trim() === ''"
				@click="search">
				<template #icon>
					<NcLoadingIcon v-if="searching" :size="20" />
					<MagnifyIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Søg') }}
			</NcButton>

			<p v-if="searched && !result" class="select-estate__no-results">
				{{ t('opencase', 'Ingen ejendom fundet') }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">{{ t('opencase', 'Luk') }}</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'SelectEstateDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcLoadingIcon, MagnifyIcon,
	},

	emits: ['close', 'selected'],

	data() {
		return {
			bfenumber: '',
			searching: false,
			searched: false,
			result: null,
		}
	},

	methods: {
		async search() {
			const bfenumber = this.bfenumber.trim()
			if (bfenumber === '') return

			this.searching = true
			this.searched  = false
			this.result    = null
			try {
				this.result   = await api.searchEstate(bfenumber)
				this.searched = true

				if (this.result) {
					this.$emit('selected', this.result)
					this.$emit('close')
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},
	},
}
</script>

<style scoped>
.select-estate {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
	width: 100%;
}

.select-estate__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.select-estate__field label {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-lighter);
}

.select-estate__no-results {
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 8px 0 0;
}
</style>
