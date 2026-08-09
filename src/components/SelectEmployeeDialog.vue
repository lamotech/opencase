<template>
	<NcDialog :name="t('opencase', 'Vælg medarbejder')"
		size="large"
		@update:open="v => !v && $emit('close')">
		<div class="add-employee">
			<div class="add-employee__field">
				<label>{{ t('opencase', 'Navn / brugernavn / e-mail') }}</label>
				<NcTextField v-model="q"
					:placeholder="t('opencase', 'Søg på navn, brugernavn, e-mail...')"
					@keydown.enter="search" />
			</div>

			<!-- Search button -->
			<NcButton type="primary"
				:disabled="searching || !hasSearchCriteria"
				@click="search">
				<template #icon>
					<NcLoadingIcon v-if="searching" :size="20" />
					<MagnifyIcon v-else :size="20" />
				</template>
				{{ t('opencase', 'Søg') }}
			</NcButton>

			<!-- Results -->
			<div v-if="searched" class="add-employee__results">
				<p v-if="results.length === 0" class="add-employee__no-results">
					{{ t('opencase', 'Ingen medarbejdere fundet') }}
				</p>
				<table v-else class="add-employee__table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Brugernavn') }}</th>
							<th>{{ t('opencase', 'E-mail') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="employee in results" :key="employee.uuid">
							<td>{{ employee.personname || '–' }}</td>
							<td class="add-employee__mono">{{ employee.username || '–' }}</td>
							<td>{{ employee.email || '–' }}</td>
							<td>
								<NcButton @click="selectEmployee(employee)">
									<template #icon>
										<CheckIcon :size="18" />
									</template>
									{{ t('opencase', 'Vælg') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
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
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { showError } from '@nextcloud/dialogs'
import api from '../services/api.js'

export default {
	name: 'SelectEmployeeDialog',

	components: {
		NcDialog, NcButton, NcTextField, NcLoadingIcon,
		MagnifyIcon, CheckIcon,
	},

	emits: ['close', 'selected'],

	data() {
		return {
			q: '',
			searching: false,
			searched: false,
			results: [],
		}
	},

	computed: {
		hasSearchCriteria() {
			return this.q.trim() !== ''
		},
	},

	methods: {
		async search() {
			if (!this.hasSearchCriteria) return

			this.searching = true
			this.searched  = false
			this.results   = []
			try {
				this.results = await api.searchEmployees({ q: this.q.trim() })
				this.searched = true

				if (this.results.length === 1) {
					this.selectEmployee(this.results[0])
				}
			} catch (e) {
				showError(t('opencase', 'Søgning fejlede'))
			} finally {
				this.searching = false
			}
		},

		selectEmployee(employee) {
			this.$emit('selected', employee)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.add-employee {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
	width: 100%;
}

.add-employee__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.add-employee__field label {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-lighter);
}

.add-employee__no-results {
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 8px 0 0;
}

.add-employee__results {
	margin-top: 8px;
	overflow-x: auto;
}

.add-employee__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.add-employee__table th,
.add-employee__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.add-employee__table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
}

.add-employee__mono {
	font-family: monospace;
	white-space: nowrap;
}
</style>
