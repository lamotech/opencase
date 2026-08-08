<template>
	<div class="template-fields">
		<div class="template-fields__header">
			<h2>{{ t('opencase', 'Skabelonfelter') }}</h2>
			<p class="template-fields__subtitle">
				{{ t('opencase', 'Indsæt disse felter i en skabelon for automatisk at udfylde data fra sagen og dokumentet.') }}
			</p>
			<p class="template-fields__syntax">
				{{ t('opencase', 'Syntax:') }} <code v-text="exampleSyntax" />
				&nbsp;—&nbsp;
				{{ t('opencase', 'store og små bogstaver ignoreres') }}
			</p>
		</div>

		<div v-for="group in groups" :key="group.key" class="template-fields__group">
			<h3 class="template-fields__group-title">{{ group.label }}</h3>
			<table class="fields-table">
				<thead>
					<tr>
						<th>{{ t('opencase', 'Felt') }}</th>
						<th>{{ t('opencase', 'Beskrivelse') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="field in group.fields" :key="field.da">
						<td class="fields-table__field">
							<code v-text="wrap(danish ? field.da : field.en)" />
							<NcButton
								type="tertiary"
								:title="t('opencase', 'Kopiér')"
								class="fields-table__copy"
								@click="copy(wrap(danish ? field.da : field.en), danish ? field.da : field.en)">
								<template #icon>
									<CheckIcon v-if="copied === (danish ? field.da : field.en)" :size="16" />
									<ContentCopyIcon v-else :size="16" />
								</template>
							</NcButton>
						</td>
						<td class="fields-table__desc">{{ field.description }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import { getLanguage } from '@nextcloud/l10n'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

export default {
	name: 'TemplateFieldsView',

	components: {
		NcButton,
		ContentCopyIcon,
		CheckIcon,
	},

	data() {
		const danish = getLanguage().startsWith('da')
		return {
			copied: null,
			danish,
			exampleSyntax: danish ? '{{feltnavn}}' : '{{fieldname}}',
			groups: [
				{
					key: 'case',
					label: t('opencase', 'Sag'),
					fields: [
						{
							da: 'sag.nummer',
							en: 'case.number',
							description: t('opencase', 'Sagsnummer'),
						},
						{
							da: 'sag.titel',
							en: 'case.title',
							description: t('opencase', 'Sagsoverskrift'),
						},
						{
							da: 'sag.organisation',
							en: 'case.organisation',
							description: t('opencase', 'Organisationsnavn'),
						},
						{
							da: 'sag.kle',
							en: 'case.kle',
							description: t('opencase', 'KLE-klassifikationskode'),
						},
						{
							da: 'sag.følsomhed',
							en: 'case.sensitivity',
							description: t('opencase', 'Følsomhedsniveau'),
						},
						{
							da: 'sag.status',
							en: 'case.status',
							description: t('opencase', 'Sagsstatus'),
						},
						{
							da: 'sag.oprettet',
							en: 'case.created',
							description: t('opencase', 'Oprettelsesdato (dd.mm.åååå)'),
						},
					],
				},
				{
					key: 'document',
					label: t('opencase', 'Dokument'),
					fields: [
						{
							da: 'dokument.nummer',
							en: 'document.number',
							description: t('opencase', 'Dokumentnummer'),
						},
						{
							da: 'dokument.type',
							en: 'document.type',
							description: t('opencase', 'Dokumenttype'),
						},
						{
							da: 'dokument.titel',
							en: 'document.title',
							description: t('opencase', 'Dokumenttitel'),
						},
						{
							da: 'dokument.oprettet',
							en: 'document.created',
							description: t('opencase', 'Oprettelsesdato (dd.mm.åååå)'),
						},
						{
							da: 'dokument.status',
							en: 'document.status',
							description: t('opencase', 'Dokumentstatus'),
						},
					],
				},
				{
					key: 'address',
					label: t('opencase', 'Adresse'),
					fields: [
						{
							da: 'adresse.cpr',
							en: 'address.cpr',
							description: t('opencase', 'CPR-nummer'),
						},
						{
							da: 'adresse.navn',
							en: 'address.name',
							description: t('opencase', 'Navn'),
						},
						{
							da: 'adresse.vejnavn',
							en: 'address.streetname',
							description: t('opencase', 'Vejnavn'),
						},
						{
							da: 'adresse.husnummer',
							en: 'address.housenumber',
							description: t('opencase', 'Husnummer'),
						},
						{
							da: 'adresse.etage',
							en: 'address.floor',
							description: t('opencase', 'Etage'),
						},
						{
							da: 'adresse.dør',
							en: 'address.door',
							description: t('opencase', 'Dør'),
						},
						{
							da: 'adresse.postnummer',
							en: 'address.zipcode',
							description: t('opencase', 'Postnummer'),
						},
						{
							da: 'adresse.postdistrikt',
							en: 'address.zipdistrict',
							description: t('opencase', 'Postdistrikt'),
						},
					],
				},
			],
		}
	},

	methods: {
		wrap(key) {
			return '{{' + key + '}}'
		},

		async copy(text, key) {
			try {
				await navigator.clipboard.writeText(text)
				this.copied = key
				setTimeout(() => {
					if (this.copied === key) {
						this.copied = null
					}
				}, 2000)
			} catch (e) {
				// Fallback for browsers without clipboard API
				const el = document.createElement('textarea')
				el.value = text
				el.style.position = 'fixed'
				el.style.opacity = '0'
				document.body.appendChild(el)
				el.select()
				document.execCommand('copy')
				document.body.removeChild(el)
				this.copied = key
				setTimeout(() => {
					if (this.copied === key) {
						this.copied = null
					}
				}, 2000)
			}
		},
	},
}
</script>

<style scoped>
.template-fields {
	padding: 20px;
	max-width: 900px;
}

.template-fields__header {
	margin-bottom: 28px;
}

.template-fields__header h2 {
	font-size: 1.4em;
	font-weight: 600;
	margin: 0 0 6px;
}

.template-fields__subtitle {
	color: var(--color-text-lighter);
	margin: 0 0 8px;
}

.template-fields__syntax {
	margin: 0;
	font-size: 0.9em;
}

.template-fields__syntax code {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 1px 6px;
	font-family: monospace;
}

.template-fields__group {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.template-fields__group-title {
	font-size: 1em;
	font-weight: 600;
	margin: 0 0 16px;
}

.fields-table {
	width: 100%;
	border-collapse: collapse;
}

.fields-table th,
.fields-table td {
	text-align: left;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);
}

.fields-table tr:last-child td {
	border-bottom: none;
}

.fields-table th {
	font-weight: 600;
	color: var(--color-text-lighter);
	font-size: 0.85em;
	text-transform: uppercase;
	padding-bottom: 8px;
}

.fields-table__field {
	white-space: nowrap;
}

.fields-table__field code {
	font-family: monospace;
	font-size: 0.9em;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 2px 6px;
	user-select: all;
}

.fields-table__copy {
	display: inline-flex !important;
	vertical-align: middle;
	margin-left: 4px;
}

.fields-table__desc {
	color: var(--color-text-lighter);
	font-size: 0.92em;
}
</style>
