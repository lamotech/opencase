<template>
	<NcDialog :name="title"
		:open="true"
		size="normal"
		@update:open="v => !v && $emit('close')">
		<div class="opencase-codelist">
			<NcLoadingIcon v-if="loading" :size="32" class="opencase-codelist__spinner" />

			<table v-else class="opencase-codelist__table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Dansk') }}</th>
						<th>{{ t('opencase', 'Engelsk') }}</th>
						<th v-if="hasPrimaryParticipant">{{ t('opencase', 'Primær part') }}</th>
						<th>{{ t('opencase', 'Udløbet') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.id">
						<td>
							<NcTextField v-model="row.da" :placeholder="t('opencase', 'Dansk')" />
						</td>
						<td>
							<NcTextField v-model="row.en" :placeholder="t('opencase', 'Engelsk')" />
						</td>
						<td v-if="hasPrimaryParticipant">
							<NcSelect :model-value="primaryParticipantOption(row.primary_participant)"
								:options="primaryParticipantOptions"
								:clearable="false"
								@update:model-value="opt => { row.primary_participant = opt.id }" />
						</td>
						<td class="opencase-codelist__expired-cell">
							<NcCheckboxRadioSwitch v-model="row.expired" type="switch" />
						</td>
					</tr>
					<tr v-for="(row, i) in newRows" :key="'new' + i" class="opencase-codelist__new-row">
						<td>
							<NcTextField v-model="row.da" :placeholder="t('opencase', 'Dansk')" />
						</td>
						<td>
							<NcTextField v-model="row.en" :placeholder="t('opencase', 'Engelsk')" />
						</td>
						<td v-if="hasPrimaryParticipant">
							<NcSelect :model-value="primaryParticipantOption(row.primary_participant)"
								:options="primaryParticipantOptions"
								:clearable="false"
								@update:model-value="opt => { row.primary_participant = opt.id }" />
						</td>
						<td class="opencase-codelist__expired-cell">
							<NcCheckboxRadioSwitch v-model="row.expired" type="switch" />
						</td>
					</tr>
				</tbody>
			</table>

			<NcButton class="opencase-codelist__add-row-btn" @click="addNewRow">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('opencase', 'Tilføj række') }}
			</NcButton>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('opencase', 'Annuller') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="saving || loading"
				@click="save">
				{{ t('opencase', 'Gem') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'

import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import api from '../services/api.js'

const PRIMARY_PARTICIPANT_VALUES = ['None', 'Citizen', 'Company', 'Employee']

function blankRow(hasPP) {
	return { da: '', en: '', expired: false, ...(hasPP ? { primary_participant: 'None' } : {}) }
}

export default {
	name: 'CodeListDialog',

	components: { NcDialog, NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcLoadingIcon, PlusIcon },

	props: {
		list: { type: String, required: true },
		title: { type: String, required: true },
	},

	data() {
		const danish = getLanguage().startsWith('da')
		return {
			loading: true,
			saving: false,
			rows: [],
			original: [],
			newRows: [blankRow(this.list === 'casetype')],
			danish,
		}
	},

	computed: {
		hasPrimaryParticipant() {
			return this.list === 'casetype'
		},
		primaryParticipantOptions() {
			const labels = this.danish
				? { None: 'Ingen', Citizen: 'Borger', Company: 'Virksomhed', Employee: 'Medarbejder' }
				: { None: 'None', Citizen: 'Citizen', Company: 'Company', Employee: 'Employee' }
			return PRIMARY_PARTICIPANT_VALUES.map(id => ({ id, label: labels[id] }))
		},
	},

	async mounted() {
		try {
			const entries = await api.getCodeList(this.list)
			this.rows = entries.map(e => ({ ...e }))
			this.original = entries.map(e => ({ ...e }))
		} catch (e) {
			showError(t('opencase', 'Kunne ikke hente værdiliste'))
			this.$emit('close')
		} finally {
			this.loading = false
		}
	},

	methods: {
		addNewRow() {
			this.newRows.push(blankRow(this.hasPrimaryParticipant))
		},

		primaryParticipantOption(value) {
			return this.primaryParticipantOptions.find(o => o.id === value) || this.primaryParticipantOptions[0]
		},

		async save() {
			this.saving = true
			try {
				const changed = this.rows.filter((row, i) => {
					const orig = this.original[i]
					return row.da !== orig.da || row.en !== orig.en || row.expired !== orig.expired
						|| (this.hasPrimaryParticipant && row.primary_participant !== orig.primary_participant)
				})
				for (const row of changed) {
					await api.updateCodeListEntry(this.list, row.id, row.da, row.en, row.expired, row.primary_participant)
				}

				const newRowsToCreate = this.newRows.filter(r => r.da.trim() !== '' || r.en.trim() !== '')
				if (newRowsToCreate.length > 0) {
					await api.createCodeListEntries(this.list, newRowsToCreate)
				}

				showSuccess(t('opencase', 'Værdiliste gemt'))
				this.$emit('close')
			} catch (e) {
				showError(t('opencase', 'Kunne ikke gemme værdiliste'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.opencase-codelist {
	padding: 8px 0;
	min-height: 80px;
}

.opencase-codelist__spinner {
	display: block;
	margin: 24px auto;
}

.opencase-codelist__table {
	width: 100%;
	border-collapse: collapse;
}

.opencase-codelist__table th {
	text-align: left;
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	padding: 0 8px 6px;
}

.opencase-codelist__table td {
	padding: 4px 8px;
	vertical-align: top;
}

.opencase-codelist__expired-cell {
	width: 70px;
	text-align: center;
}

.opencase-codelist__new-row td {
	padding-top: 14px;
}

.opencase-codelist__add-row-btn {
	margin-top: 8px;
}
</style>
