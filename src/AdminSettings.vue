<template>
	<div class="opencase-admin-settings">
		<NcSettingsSection :name="t('opencase', 'OpenCase')">
			<h4>{{ t('opencase', 'Version') }}</h4>
			<strong>{{ editionLabel }}</strong>
		</NcSettingsSection>
		<NcSettingsSection :name="t('opencase', 'Prerequisites')">
			<h4>{{ t('opencase', 'Påkrævede') }}</h4>
			<div class="opencase-prerequisites">
				<div v-for="app in requiredApps" :key="app.key" class="opencase-prerequisite">
					<div v-if="app.enabled" class="opencase-cert-info-box">
						<div><span class="opencase-check">&#10004;</span><strong>{{ app.label }}</strong></div>
					</div>
					<div v-else class="prerequisites-error-box">
						<div><strong>{{ app.label }} — {{ t('opencase', 'not installed (required)') }}</strong></div>
					</div>
				</div>
			</div>
			<template v-if="enterpriseVersion">
				<h4>{{ t('opencase', 'Anbefalede') }}</h4>
				<div class="opencase-prerequisites">
					<div v-for="app in recommendedApps" :key="app.key" class="opencase-prerequisite">
						<div v-if="app.enabled" class="opencase-cert-info-box">
							<div><span class="opencase-check">&#10004;</span><strong>{{ app.label }}</strong></div>
						</div>
						<div v-else class="prerequisites-warning-box">
							<div><strong>{{ app.label }} — {{ t('opencase', 'not installed (recommended)') }}</strong></div>
						</div>
					</div>
				</div>
			</template>
		</NcSettingsSection>
		<NcSettingsSection :name="t('opencase', 'Systemopsætning')">
			<OverflowTabs :tabs="tabs" :value="activeTab" @input="activeTab = $event" />
		</NcSettingsSection>

		<div v-show="activeTab === 'serviceplatformen'">
			<NcSettingsSection :name="t('opencase', 'Serviceplatformen')">
				<p>{{ t('opencase', 'Settings for Serviceplatformen integration.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="org-cvr">{{ t('opencase', 'CVR number') }}</label>
						</td>
						<td>
							<input id="org-cvr"
								v-model="cvr"
								type="text"
								:style="{ width: '200px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('cvr', cvr)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="org-token-url">{{ t('opencase', 'Token service URL') }}</label>
						</td>
						<td>
							<input id="org-token-url"
								v-model="tokenIssuerBaseUrl"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('token_issuer_base_url', tokenIssuerBaseUrl)">
						</td>
					</tr>
				</table>

				<p>{{ t('opencase', 'Copy your system certificate to the server and run this command in the root of the Nextcloud installation to register it:') }}</p>
				<br/>
				<p><strong>php occ opencase:register-certificate</strong></p>
				<br/>

				<div v-if="certError === 'not_found'" class="certificate-error">
					{{ t('opencase', 'The certificate was not found at the specified path') }}
				</div>
				<div v-else-if="certError === 'cannot_read'" class="certificate-error">
					{{ t('opencase', 'The certificate cannot be read') }}
				</div>
				<div v-else-if="certSubject" class="opencase-cert-info-box">
					<div><strong>{{ t('opencase', 'Name:') }}</strong> {{ certSubject }}</div>
					<div><strong>{{ t('opencase', 'Serial number:') }}</strong> {{ certSerialNumber }}</div>
					<div><strong>{{ t('opencase', 'Expires:') }}</strong> {{ certExpiresFormatted }}</div>
				</div>				
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Organisation')">
				<p>{{ t('opencase', 'Settings for organisation integration.') }}</p>

				<NcCheckboxRadioSwitch id="org-enable"
					v-model="organisationEnable"
					type="switch">
					{{ t('opencase', 'Enable') }}
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch id="org-teamfolders-enable"
					v-model="teamfoldersEnable"
					type="switch">
					{{ t('opencase', 'Create team folder for each organisation') }}
				</NcCheckboxRadioSwitch>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="org-entity-id">{{ t('opencase', 'Entity ID for organisation') }}</label>
						</td>
						<td>
							<input id="org-entity-id"
								v-model="entityIdOrganisation"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('entity_id_organisation', entityIdOrganisation)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="org-endpoint">{{ t('opencase', 'Endpoint for organisation service') }}</label>
						</td>
						<td>
							<input id="org-endpoint"
								v-model="endpointOrganisation"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_organisation', endpointOrganisation)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="org-endpoint-enhed">{{ t('opencase', 'Endpoint for organisation enhed service') }}</label>
						</td>
						<td>
							<input id="org-endpoint-enhed"
								v-model="endpointOrganisationEnhed"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_organisation_enhed', endpointOrganisationEnhed)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="org-endpoint-function">{{ t('opencase', 'Endpoint for organisation function service') }}</label>
						</td>
						<td>
							<input id="org-endpoint-function"
								v-model="endpointOrganisationFunction"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_organisation_function', endpointOrganisationFunction)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="org-endpoint-user">{{ t('opencase', 'Endpoint for bruger service') }}</label>
						</td>
						<td>
							<input id="org-endpoint-user"
								v-model="endpointUser"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_user', endpointUser)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="org-endpoint-person">{{ t('opencase', 'Endpoint for person service') }}</label>
						</td>
						<td>
							<input id="org-endpoint-person"
								v-model="endpointPerson"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_person', endpointPerson)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="org-endpoint-address">{{ t('opencase', 'Endpoint for adresse service') }}</label>
						</td>
						<td>
							<input id="org-endpoint-address"
								v-model="endpointAddress"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_address', endpointAddress)">
						</td>
					</tr>
				</table>

				<div class="opencase-button-row">
					<button class="primary" :disabled="syncing" @click="syncOrganisations">
						{{ syncing ? t('opencase', 'Synchronizing...') : t('opencase', 'Synchronize organisations now') }}
					</button>
				</div>

				<div v-if="syncResult" class="opencase-cert-info-box">
					<div><strong>{{ t('opencase', 'Fetched:') }}</strong> {{ syncResult.fetched }}</div>
					<div><strong>{{ t('opencase', 'Created:') }}</strong> {{ syncResult.created }}</div>
					<div><strong>{{ t('opencase', 'Updated:') }}</strong> {{ syncResult.updated }}</div>
					<div><strong>{{ t('opencase', 'Deactivated:') }}</strong> {{ syncResult.deactivated }}</div>
				</div>
				<div v-if="syncError" class="certificate-error">
					{{ syncError }}
				</div>

				<table v-if="syncLog.length > 0" class="opencase-sync-log-table">
					<caption>{{ t('opencase', 'Synchronization log') }}</caption>
					<thead>
						<tr>
							<th>{{ t('opencase', 'Time') }}</th>
							<th>{{ t('opencase', 'Fetched') }}</th>
							<th>{{ t('opencase', 'Created') }}</th>
							<th>{{ t('opencase', 'Updated') }}</th>
							<th>{{ t('opencase', 'Deactivated') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(entry, index) in syncLog" :key="index">
							<td>{{ entry.sync_time }}</td>
							<td>{{ entry.count_received }}</td>
							<td>{{ entry.created }}</td>
							<td>{{ entry.updated }}</td>
							<td>{{ entry.deactivated }}</td>
						</tr>
					</tbody>
				</table>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Klassifikation')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>

				<p v-if="!enterpriseVersion" class="opencase-msoffice-hint">
					{{ t('opencase', 'CSV format (semicolon-separated, with header row): uuid;code;title;description;active') }}
				</p>
				<div v-if="!enterpriseVersion" class="opencase-button-row">
					<input ref="kleSubjectsFileInput"
						type="file"
						accept=".csv,text/csv"
						style="display:none"
						@change="onKleSubjectsFileSelected">
					<button class="primary" :disabled="uploadingKleSubjects" @click="$refs.kleSubjectsFileInput.click()">
						{{ uploadingKleSubjects ? t('opencase', 'Uploading...') : t('opencase', 'Upload KLE Emneplan') }}
					</button>

					<input ref="kleFacetsFileInput"
						type="file"
						accept=".csv,text/csv"
						style="display:none"
						@change="onKleFacetsFileSelected">
					<button class="primary" :disabled="uploadingKleFacets" @click="$refs.kleFacetsFileInput.click()">
						{{ uploadingKleFacets ? t('opencase', 'Uploading...') : t('opencase', 'Upload handlingsfacetter') }}
					</button>
				</div>

				<p>{{ t('opencase', 'Settings for klassifikation integration.') }}</p>

				<NcCheckboxRadioSwitch id="klass-enable"
					v-model="classificationEnable"
					:disabled="!enterpriseVersion"
					type="switch">
					{{ t('opencase', 'Enable') }}
				</NcCheckboxRadioSwitch>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="klass-entity-id">{{ t('opencase', 'Entity ID for klassifikation') }}</label>
						</td>
						<td>
							<input id="klass-entity-id"
								v-model="entityIdKlassifikation"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('entity_id_classification', entityIdKlassifikation)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="klass-endpoint">{{ t('opencase', 'Endpoint for klassifikation service') }}</label>
						</td>
						<td>
							<input id="klass-endpoint"
								v-model="endpointKlassifikation"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_classification', endpointKlassifikation)">
						</td>
					</tr>
				</table>

				<div class="opencase-button-row">
					<button class="primary" :disabled="!enterpriseVersion || syncingKlassifikation" @click="syncKlassifikation">
						{{ syncingKlassifikation ? t('opencase', 'Synchronizing...') : t('opencase', 'Synchronize klassifikation now') }}
					</button>
				</div>

				<div v-if="syncResultKlassifikation" class="opencase-cert-info-box">
					<div><strong>{{ t('opencase', 'Fetched:') }}</strong> {{ syncResultKlassifikation.fetched }}</div>
					<div><strong>{{ t('opencase', 'Created:') }}</strong> {{ syncResultKlassifikation.created }}</div>
					<div><strong>{{ t('opencase', 'Updated:') }}</strong> {{ syncResultKlassifikation.updated }}</div>
					<div><strong>{{ t('opencase', 'Deactivated:') }}</strong> {{ syncResultKlassifikation.deactivated }}</div>
				</div>
				<div v-if="syncErrorKlassifikation" class="certificate-error">
					{{ syncErrorKlassifikation }}
				</div>

				<table v-if="syncLogKlassifikation.length > 0" class="opencase-sync-log-table">
					<caption>{{ t('opencase', 'Synchronization log') }}</caption>
					<thead>
						<tr>
							<th>{{ t('opencase', 'Time') }}</th>
							<th>{{ t('opencase', 'Fetched') }}</th>
							<th>{{ t('opencase', 'Created') }}</th>
							<th>{{ t('opencase', 'Updated') }}</th>
							<th>{{ t('opencase', 'Deactivated') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(entry, index) in syncLogKlassifikation" :key="index">
							<td>{{ entry.sync_time }}</td>
							<td>{{ entry.count_received }}</td>
							<td>{{ entry.created }}</td>
							<td>{{ entry.updated }}</td>
							<td>{{ entry.deactivated }}</td>
						</tr>
					</tbody>
				</table>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Access control')">
				<p>{{ t('opencase', 'Settings for access control.') }}</p>

				<NcCheckboxRadioSwitch id="ac-enable"
					v-model="accessControlEnable"
					type="switch">
					{{ t('opencase', 'Enable') }}
				</NcCheckboxRadioSwitch>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="ac-idp-metadata">{{ t('opencase', 'Metadata for Context Handler') }}</label>
						</td>
						<td>
							<input id="ac-idp-metadata"
								v-model="idpMetadataUrl"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('idp_metadata_url', idpMetadataUrl)">
						</td>
					</tr>
				</table>

				<div class="opencase-button-row">
					<button class="primary" @click="downloadMetadata">
						{{ t('opencase', 'Download metadata file') }}
					</button>
				</div>

				<table class="opencase-roles-table">
					<caption>{{ t('opencase', 'Create these user system roles in the Joint Municipal Administration Module') }}</caption>
					<thead>
						<tr>
							<th>{{ t('opencase', 'Name') }}</th>
							<th>{{ t('opencase', 'EntityId') }}</th>
							<th>{{ t('opencase', 'Data delimitation types') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>{{ t('opencase', 'User') }}</td>
							<td>http://{{ domain }}/roles/usersystemrole/opencaseuser/1</td>
							<td>{{ t('opencase', 'Organisation, KLE, Følsomhed') }}</td>
						</tr>
						<tr>
							<td>{{ t('opencase', 'Læsebruger') }}</td>
							<td>http://{{ domain }}/roles/usersystemrole/opencasereaduser/1</td>
							<td>{{ t('opencase', 'Organisation, KLE, Følsomhed') }}</td>
						</tr>
						<tr>
							<td>{{ t('opencase', 'Skabelon administrator') }}</td>
							<td>http://{{ domain }}/roles/usersystemrole/opencasetemplateadministrator/1</td>
							<td>{{ t('opencase', 'None') }}</td>
						</tr>
						<tr>
							<td>{{ t('opencase', 'OpenCase Administrator') }}</td>
							<td>http://{{ domain }}/roles/usersystemrole/opencaseadministrator/1</td>
							<td>{{ t('opencase', 'None') }}</td>
						</tr>
						<tr>
							<td>{{ t('opencase', 'System Administrator') }}</td>
							<td>http://{{ domain }}/roles/usersystemrole/systemadministrator/1</td>
							<td>{{ t('opencase', 'None') }}</td>
						</tr>
					</tbody>
				</table>
			</NcSettingsSection>


			<NcSettingsSection :name="t('opencase', 'Digital post')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'Settings for Digital post integration.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="dp-cvr">{{ t('opencase', 'CVR ved digital post') }}</label>
						</td>
						<td>
							<input id="dp-cvr"
								v-model="digitalpostCvr"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '200px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('digitalpost_cvr', digitalpostCvr)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="dp-sender-label">{{ t('opencase', 'Kommunenavn ved digital post') }}</label>
						</td>
						<td>
							<input id="dp-sender-label"
								v-model="digitalpostSenderLabel"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('digitalpost_sender_label', digitalpostSenderLabel)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="dp-entity-id-kombi">{{ t('opencase', 'Entity ID for kombi post afsend') }}</label>
						</td>
						<td>
							<input id="dp-entity-id-kombi"
								v-model="entityIdKombipostafsend"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('entity_id_kombipostafsend', entityIdKombipostafsend)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="dp-endpoint-kombi">{{ t('opencase', 'Endpoint for kombi post afsend') }}</label>
						</td>
						<td>
							<input id="dp-endpoint-kombi"
								v-model="endpointKombipostafsend"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_kombipostafsend', endpointKombipostafsend)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="dp-entity-id-post">{{ t('opencase', 'Entity ID for post forespørg') }}</label>
						</td>
						<td>
							<input id="dp-entity-id-post"
								v-model="entityIdPostforespoerg"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('entity_id_postforespoerg', entityIdPostforespoerg)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="dp-endpoint-post">{{ t('opencase', 'Endpoint for post forespørg') }}</label>
						</td>
						<td>
							<input id="dp-endpoint-post"
								v-model="endpointPostforespoerg"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_postforespoerg', endpointPostforespoerg)">
						</td>
					</tr>
				</table>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Fordelingskomponent')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'Settings for fordelingskomponent integration.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="fordeling-entity-id">{{ t('opencase', 'Entity id for fordeling') }}</label>
						</td>
						<td>
							<input id="fordeling-entity-id"
								v-model="entityIdFordeling"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('entity_id_fordeling', entityIdFordeling)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="fordeling-endpoint">{{ t('opencase', 'Endpoint for fordelingskomponentservice') }}</label>
						</td>
						<td>
							<input id="fordeling-endpoint"
								v-model="endpointFordeling"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('endpoint_fordeling', endpointFordeling)">
						</td>
					</tr>
				</table>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'SFTP Server')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'Settings for SFTP server connection.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="sftp-host">{{ t('opencase', 'SFTP Host') }}</label>
						</td>
						<td>
							<input id="sftp-host"
								v-model="sftpHost"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '400px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('sftp_host', sftpHost)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="sftp-user-name">{{ t('opencase', 'SSH brugernavn') }}</label>
						</td>
						<td>
							<input id="sftp-user-name"
								v-model="sftpUserName"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '200px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('sftp_user_name', sftpUserName)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="sftp-key-file">{{ t('opencase', 'SSH nøgle filnavn') }}</label>
						</td>
						<td>
							<input id="sftp-key-file"
								v-model="sftpSshPrivateKeyFullFileName"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '400px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('sftp_ssh_private_key_full_fileName', sftpSshPrivateKeyFullFileName)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="sftp-passphrase">{{ t('opencase', 'SSH adgangskode') }}</label>
						</td>
						<td>
							<input id="sftp-passphrase"
								v-model="sftpSshKeyPassPhrase"
								type="password"
								autocomplete="new-password"
								:disabled="!enterpriseVersion"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('sftp_ssh_key_pass_phrase', sftpSshKeyPassPhrase)">
						</td>
					</tr>
				</table>
			</NcSettingsSection>
		</div>

		<div v-show="activeTab === 'datafordeler'">
			<NcSettingsSection :name="t('opencase', 'Virksomhed')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'Settings for CVR service integration.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="df-endpoint-cvr">{{ t('opencase', 'Endpoint for CVR service') }}</label>
						</td>
						<td>
							<input id="df-endpoint-cvr"
								v-model="datafordelerEndpointCvr"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('datafordeler_endpoint_cvr', datafordelerEndpointCvr)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="df-apikey-cvr">{{ t('opencase', 'API key for CVR service') }}</label>
						</td>
						<td>
							<input id="df-apikey-cvr"
								v-model="datafordelerApikeyCvr"
								type="password"
								autocomplete="new-password"
								:disabled="!enterpriseVersion"
								:style="{ width: '400px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('datafordeler_apikey_cvr', datafordelerApikeyCvr)">
						</td>
					</tr>
				</table>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Person')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'Indstillinger for CPR service integration.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="df-endpoint-cpr">{{ t('opencase', 'Endpoint for CPR service') }}</label>
						</td>
						<td>
							<input id="df-endpoint-cpr"
								v-model="datafordelerEndpointCpr"
								type="text"
								:disabled="!enterpriseVersion"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('datafordeler_endpoint_cpr', datafordelerEndpointCpr)">
						</td>
					</tr>
				</table>

				<div v-if="certDatafordeler.error === 'not_found'" class="certificate-error">
					{{ t('opencase', 'The certificate was not found at the specified path') }}
				</div>
				<div v-else-if="certDatafordeler.error === 'cannot_read'" class="certificate-error">
					{{ t('opencase', 'The certificate cannot be read') }}
				</div>
				<div v-else-if="certDatafordeler.valid" class="opencase-cert-info-box">
					<div><strong>{{ t('opencase', 'Name:') }}</strong> {{ certDatafordeler.subject }}</div>
					<div><strong>{{ t('opencase', 'Serial number:') }}</strong> {{ certDatafordeler.serialNumber }}</div>
					<div><strong>{{ t('opencase', 'Expires:') }}</strong> {{ certDatafordelerExpiresFormatted }}</div>
				</div>
			</NcSettingsSection>
		</div>

		<div v-show="activeTab === 'msoffice'">
			<NcSettingsSection :name="t('opencase', 'MS Office add-ins')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'OpenCase includes task pane add-ins for Outlook, Word, Excel and PowerPoint that let users save the item they are working on as a document on a case.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="msoffice-addin-name">{{ t('opencase', 'Add-in navn') }}</label>
						</td>
						<td>
							<input id="msoffice-addin-name"
								v-model="msofficeAddinName"
								type="text"
								autocomplete="off"
								spellcheck="false"
								:disabled="!enterpriseVersion"
								:style="{ width: '200px', boxSizing: 'border-box' }"
								@change="saveConfigValue('msoffice_addin_name', msofficeAddinName || 'OpenCase')">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="msoffice-addin-port">{{ t('opencase', 'Add-in port') }}</label>
						</td>
						<td>
							<input id="msoffice-addin-port"
								v-model="msofficeAddinPort"
								type="number"
								min="1"
								max="65535"
								:disabled="!enterpriseVersion"
								:style="{ width: '200px', boxSizing: 'border-box' }"
								@change="saveConfigValue('msoffice_addin_port', String(msofficeAddinPort))">
						</td>
					</tr>
				</table>
				<p class="opencase-msoffice-hint">{{ t('opencase', 'nginx port for the add-in backend (used by deploy.sh). Defaults to 1443 if left empty.') }}</p>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Microsoft Graph (browser support)')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'Excel, Word, and PowerPoint don\'t expose the real file bytes to add-ins when running in a web browser (only the desktop apps support that) — and Outlook\'s EWS-based email export can be unavailable in some configurations. To support those cases, the add-ins can fall back to downloading the file or email directly from Microsoft Graph, which requires an Entra ID (Azure AD) app registration:') }}</p>
				<br>
				<p>{{ t('opencase', '1. In the Azure Portal, go to Entra ID → App registrations → New registration.') }}</p>
				<p>{{ t('opencase', '2. Platform: Single-page application (SPA). Use this exact redirect URI:') }}</p>
				<div class="opencase-copy-row">
					<code class="opencase-copy-value">{{ msofficeRedirectUri }}</code>
					<button type="button"
						class="btn-icon"
						:disabled="!enterpriseVersion"
						:title="t('opencase', 'Copy to clipboard')"
						@click="copyMsofficeRedirectUri">
						<CheckIcon v-if="msofficeUriCopied" :size="18" />
						<ContentCopyIcon v-else :size="18" />
					</button>
				</div>
				<p v-if="!msofficeAddinPort" class="opencase-msoffice-hint">{{ t('opencase', 'Assuming the default port 1443 — set "Add-in port" above if you deployed with a different one.') }}</p>
				<br>
				<p>{{ t('opencase', '3. API permissions → Microsoft Graph → Delegated: Files.Read (add Mail.Read too if Outlook should use Graph as well).') }}</p>
				<p>{{ t('opencase', '4. Grant admin consent for those permissions.') }}</p>
				<p>{{ t('opencase', '5. Enter the resulting Application (client) ID and Directory (tenant) ID below, then redeploy (bash nginx/deploy.sh) so the add-ins pick them up.') }}</p>
				<br>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="msoffice-graph-client-id">{{ t('opencase', 'Graph app (client) ID') }}</label>
						</td>
						<td>
							<input id="msoffice-graph-client-id"
								v-model="msofficeGraphClientId"
								type="text"
								autocomplete="off"
								spellcheck="false"
								:disabled="!enterpriseVersion"
								:style="{ width: '400px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('msoffice_graph_client_id', msofficeGraphClientId)">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="msoffice-graph-tenant-id">{{ t('opencase', 'Graph tenant ID') }}</label>
						</td>
						<td>
							<input id="msoffice-graph-tenant-id"
								v-model="msofficeGraphTenantId"
								type="text"
								autocomplete="off"
								spellcheck="false"
								:disabled="!enterpriseVersion"
								:style="{ width: '400px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('msoffice_graph_tenant_id', msofficeGraphTenantId)">
						</td>
					</tr>
				</table>
				<p class="opencase-msoffice-hint">{{ t('opencase', 'Both values are public identifiers (not secrets) for a browser-based app registration — safe to ship in client-side JS. Leave empty to disable Graph fallback; the desktop apps are unaffected either way.') }}</p>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Install the add-in backend')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'On the server that hosts this Nextcloud instance, run the deployment script to install the static files and nginx site for all four add-ins:') }}</p>
				<br>
				<p><strong>cd /var/www/nextcloud/apps/opencase/msoffice &amp;&amp; bash nginx/deploy.sh</strong></p>
				<br>
				<p>{{ t('opencase', 'The hostname and port are auto-detected — the hostname from this Nextcloud instance\'s own configuration, and the port from the "Add-in port" field above (or 1443 if that\'s empty). Pass a hostname explicitly to override, for example:') }}</p>
				<br>
				<p><strong>bash nginx/deploy.sh addin.example.com</strong></p>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Manifest files')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'Download each add-in\'s manifest.xml below, for installing it in your Microsoft 365 tenant.') }}</p>

				<div class="opencase-button-row" style="display:flex; gap:8px; flex-wrap:wrap;">
					<a v-for="app in msofficeApps"
						:key="app.id"
						class="button primary"
						:class="{ 'opencase-link-disabled': !enterpriseVersion }"
						:href="enterpriseVersion ? msofficeManifestUrl(app.id) : undefined"
						:aria-disabled="!enterpriseVersion"
						target="_blank"
						rel="noopener"
						@click="!enterpriseVersion && $event.preventDefault()">
						{{ app.label }} manifest.xml
					</a>
				</div>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Install in the Microsoft 365 tenant')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', '1. Go to admin.microsoft.com → Settings → Integrated apps.') }}</p>
				<p>{{ t('opencase', '2. Click "Upload custom apps" and upload each manifest.xml downloaded above.') }}</p>
				<p>{{ t('opencase', '3. Assign the add-in to the relevant users or groups.') }}</p>
			</NcSettingsSection>
		</div>

		<div v-show="activeTab === 'api'">
			<NcSettingsSection :name="t('opencase', 'OpenCase API')">
				<p>{{ t('opencase', 'OpenCases API anvender Serviceplatformens integrationsmodel til autentifikation og adgangskontrol i overensstemmelse med SF1512.') }}</p>

				<p>{{ t('opencase', 'Den offentlige nøgle af det eksterne systems klient certifikat skal registreres her for validering. Kopier den offentlige nøgle til en mappe på serveren og registrer med:') }}</p>
				<p><strong>php occ opencase:register-api-client</strong></p>

				<p v-if="apiClientsApi.length === 0" class="opencase-api-clients-empty">
					{{ t('opencase', 'Ingen API-klienter er registreret endnu.') }}
				</p>
				<table v-else class="opencase-sync-log-table opencase-api-clients-table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Certifikatsubjekt') }}</th>
							<th>{{ t('opencase', 'Bruger') }}</th>
							<th>{{ t('opencase', 'Oprettet') }}</th>
							<th>{{ t('opencase', 'Udløber') }}</th>
							<th>{{ t('opencase', 'Aktiv') }}</th>
							<th>{{ t('opencase', 'Handling') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="client in apiClientsApi" :key="client.id"
							class="opencase-api-clients-row"
							@click="openCertificateDialog(client)">
							<td>{{ client.name }}</td>
							<td class="opencase-api-clients-subject">{{ client.subject_cn || '—' }}</td>
							<td>{{ client.mapped_to_user_name || '—' }}</td>
							<td>{{ formatApiClientDate(client.created_at) }}</td>
							<td @click.stop>
								<input type="date"
									:value="toDateInputValue(client.expires_at)"
									@change="setApiClientExpires(client, $event.target.value)">
							</td>
							<td @click.stop>
								<NcCheckboxRadioSwitch :model-value="isApiClientActive(client)"
									type="switch"
									@update:model-value="setApiClientActive(client, $event)" />
							</td>
							<td @click.stop>
								<button @click="deleteApiClient(client)">
									{{ t('opencase', 'Slet') }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="opencase-api-subsection">{{ t('opencase', 'Den offentlige nøgle af Serviceplatformens certifikat for Adgangsstyring skal ligeledes registreres med:') }}</p>
				<p><strong>php occ opencase:register-api-client</strong></p>

				<p v-if="apiClientsAdgangsstyring.length === 0" class="opencase-api-clients-empty">
					{{ t('opencase', 'Ingen certifikater er registreret endnu.') }}
				</p>
				<table v-else class="opencase-sync-log-table opencase-api-clients-table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Certifikatsubjekt') }}</th>
							<th>{{ t('opencase', 'Oprettet') }}</th>
							<th>{{ t('opencase', 'Udløber') }}</th>
							<th>{{ t('opencase', 'Aktiv') }}</th>
							<th>{{ t('opencase', 'Handling') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="client in apiClientsAdgangsstyring" :key="client.id"
							class="opencase-api-clients-row"
							@click="openCertificateDialog(client)">
							<td>{{ client.name }}</td>
							<td class="opencase-api-clients-subject">{{ client.subject_cn || '—' }}</td>
							<td>{{ formatApiClientDate(client.created_at) }}</td>
							<td @click.stop>
								<input type="date"
									:value="toDateInputValue(client.expires_at)"
									@change="setApiClientExpires(client, $event.target.value)">
							</td>
							<td @click.stop>
								<NcCheckboxRadioSwitch :model-value="isApiClientActive(client)"
									type="switch"
									@update:model-value="setApiClientActive(client, $event)" />
							</td>
							<td @click.stop>
								<button @click="deleteApiClient(client)">
									{{ t('opencase', 'Slet') }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>

				<div v-if="apiClientsError" class="certificate-error" style="margin-top:12px;">
					{{ apiClientsError }}
				</div>
			</NcSettingsSection>

			<NcSettingsSection :name="t('opencase', 'Serviceplatformen')">
				<p v-if="!enterpriseVersion" class="enterprise-version-warning">
					{{ t('opencase', 'Integrationen er kun tilgængelig i Enterprise versionen') }}
				</p>
				<p>{{ t('opencase', 'For at Serviceplatformens Beskedfordeler og Fordelingskomponent kan kalde OpenCase skal den offentlige nøgle af Serviceplatformens callback certifikat registreres med:') }}</p>
				<p><strong>php occ opencase:register-api-client</strong></p>

				<p v-if="apiClientsServiceplatformen.length === 0" class="opencase-api-clients-empty">
					{{ t('opencase', 'Ingen certifikater er registreret endnu.') }}
				</p>
				<table v-else class="opencase-sync-log-table opencase-api-clients-table">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th>{{ t('opencase', 'Certifikatsubjekt') }}</th>
							<th>{{ t('opencase', 'Oprettet') }}</th>
							<th>{{ t('opencase', 'Udløber') }}</th>
							<th>{{ t('opencase', 'Aktiv') }}</th>
							<th>{{ t('opencase', 'Handling') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="client in apiClientsServiceplatformen" :key="client.id"
							class="opencase-api-clients-row"
							@click="openCertificateDialog(client)">
							<td>{{ client.name }}</td>
							<td class="opencase-api-clients-subject">{{ client.subject_cn || '—' }}</td>
							<td>{{ formatApiClientDate(client.created_at) }}</td>
							<td @click.stop>
								<input type="date"
									:value="toDateInputValue(client.expires_at)"
									@change="setApiClientExpires(client, $event.target.value)">
							</td>
							<td @click.stop>
								<NcCheckboxRadioSwitch :model-value="isApiClientActive(client)"
									type="switch"
									@update:model-value="setApiClientActive(client, $event)" />
							</td>
							<td @click.stop>
								<button @click="deleteApiClient(client)">
									{{ t('opencase', 'Slet') }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</NcSettingsSection>

			<NcModal v-if="certificateDialogClient" size="normal" :name="certificateDialogClient.name" @close="certificateDialogClient = null">
				<div class="opencase-cert-dialog">
					<table class="opencase-cert-table">
						<tr>
							<td class="opencase-cert-label">{{ t('opencase', 'Emne (subject)') }}</td>
							<td>{{ certificateDialogInfo.subject || certificateDialogClient.subject_dn || '—' }}</td>
						</tr>
						<tr v-if="certificateDialogInfo.issuer">
							<td class="opencase-cert-label">{{ t('opencase', 'Udsteder (issuer)') }}</td>
							<td>{{ certificateDialogInfo.issuer }}</td>
						</tr>
						<tr v-if="certificateDialogInfo.serialNumber">
							<td class="opencase-cert-label">{{ t('opencase', 'Serienummer') }}</td>
							<td>{{ certificateDialogInfo.serialNumber }}</td>
						</tr>
						<tr v-if="certificateDialogInfo.signatureType">
							<td class="opencase-cert-label">{{ t('opencase', 'Signaturalgoritme') }}</td>
							<td>{{ certificateDialogInfo.signatureType }}</td>
						</tr>
						<tr v-if="certificateDialogInfo.validFrom">
							<td class="opencase-cert-label">{{ t('opencase', 'Gyldig fra') }}</td>
							<td>{{ formatUnixDate(certificateDialogInfo.validFrom) }}</td>
						</tr>
						<tr>
							<td class="opencase-cert-label">{{ t('opencase', 'Udløber') }}</td>
							<td>{{ certificateDialogInfo.validTo ? formatUnixDate(certificateDialogInfo.validTo) : formatApiClientDate(certificateDialogClient.expires_at) }}</td>
						</tr>
						<tr>
							<td class="opencase-cert-label">{{ t('opencase', 'Fingeraftryk') }}</td>
							<td class="opencase-api-clients-fingerprint">{{ certificateDialogClient.fingerprint }}</td>
						</tr>
						<tr v-if="certificateDialogClient.mapped_to_user_name">
							<td class="opencase-cert-label">{{ t('opencase', 'Bruger') }}</td>
							<td>{{ certificateDialogClient.mapped_to_user_name }}</td>
						</tr>
					</table>
				</div>
			</NcModal>
		</div>

		<div v-show="activeTab === 'local_users'">
			<NcSettingsSection :name="t('opencase', 'Lokale brugere')">
				<p>{{ t('opencase', 'Søg efter en lokal Nextcloud-bruger for at oprette eller opdatere brugeroplysninger.') }}</p>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="lu-search">{{ t('opencase', 'Søg bruger') }}</label>
						</td>
						<td>
							<input id="lu-search"
								v-model="luQuery"
								type="text"
								:placeholder="t('opencase', 'Brugernavn eller navn...')"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }"
								@keyup.enter="searchLocalUsers">
						</td>
					</tr>
				</table>

				<div class="opencase-button-row">
					<button class="primary" :disabled="luSearching" @click="searchLocalUsers">
						{{ luSearching ? t('opencase', 'Søger...') : t('opencase', 'Søg') }}
					</button>
				</div>

				<table v-if="luResults.length > 0" class="opencase-sync-log-table" style="max-width:600px;">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Brugernavn') }}</th>
							<th>{{ t('opencase', 'Navn') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="u in luResults" :key="u.uid">
							<td>{{ u.uid }}</td>
							<td>{{ u.displayname }}</td>
							<td>
								<button @click="selectLocalUser(u)">
									{{ t('opencase', 'Vælg') }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else-if="luSearched && !luSearching">
					{{ t('opencase', 'Ingen brugere fundet.') }}
				</p>
			</NcSettingsSection>

			<NcSettingsSection v-if="luSelected" :name="t('opencase', 'Brugeroplysninger') + ': ' + luSelected.uid">
				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="lu-personname">{{ t('opencase', 'Navn') }}</label>
						</td>
						<td>
							<input id="lu-personname"
								v-model="luPersonname"
								type="text"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="lu-email">{{ t('opencase', 'E-mail') }}</label>
						</td>
						<td>
							<input id="lu-email"
								v-model="luEmail"
								type="email"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="lu-phone">{{ t('opencase', 'Telefon') }}</label>
						</td>
						<td>
							<input id="lu-phone"
								v-model="luPhone"
								type="text"
								:style="{ width: '200px', maxWidth: '100%', boxSizing: 'border-box' }">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="lu-location">{{ t('opencase', 'Lokation') }}</label>
						</td>
						<td>
							<input id="lu-location"
								v-model="luLocation"
								type="text"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }">
						</td>
					</tr>
				</table>

				<div class="opencase-button-row">
					<button class="primary" :disabled="luSaving" @click="saveLocalUserInfo">
						{{ luSaving ? t('opencase', 'Gemmer...') : t('opencase', 'Gem') }}
					</button>
				</div>

				<div v-if="luSaved" class="opencase-cert-info-box">
					{{ t('opencase', 'Brugeroplysninger gemt.') }}
				</div>
				<div v-if="luError" class="certificate-error">
					{{ luError }}
				</div>
			</NcSettingsSection>

			<NcSettingsSection v-if="luSelected" :name="t('opencase', 'Rettigheder')">
				<table v-if="luPrivileges.length > 0" class="opencase-sync-log-table" style="max-width:100%;">
					<thead>
						<tr>
							<th>{{ t('opencase', 'Rettighedstype') }}</th>
							<th>{{ t('opencase', 'Følsomhed') }}</th>
							<th>{{ t('opencase', 'KLE') }}</th>
							<th>{{ t('opencase', 'Organisationsenhed') }}</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="priv in luPrivileges" :key="priv.id">
							<td>{{ privilegeTypeMap[priv.privilege_type] || priv.privilege_type }}</td>
							<td>
								<span v-if="priv.foelsomhed.length === 0">{{ t('opencase', 'Alle') }}</span>
								<span v-else>{{ priv.foelsomhed.map(f => f.title).join(', ') }}</span>
							</td>
							<td>
								<span v-if="priv.kle.length === 0">{{ t('opencase', 'Alle') }}</span>
								<span v-else>{{ priv.kle.join(', ') }}</span>
							</td>
							<td>
								<span v-if="priv.orgenhed.length === 0">{{ t('opencase', 'Alle') }}</span>
								<span v-else>{{ priv.orgenhed.map(o => o.title).join(', ') }}</span>
							</td>
							<td>
								<button @click="deleteLocalUserPrivilege(priv.id)">
									{{ t('opencase', 'Slet') }}
								</button>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else-if="!luPrivilegesLoading">
					{{ t('opencase', 'Ingen rettigheder tildelt.') }}
				</p>

				<h3 style="margin-top:24px;">{{ t('opencase', 'Tilføj rettighed') }}</h3>
				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="lu-priv-type">{{ t('opencase', 'Rettighedstype') }}</label>
						</td>
						<td>
							<select id="lu-priv-type"
								v-model="luNewPrivilegeType"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }">
								<option value="">{{ t('opencase', 'Vælg...') }}</option>
								<option v-for="(label, key) in privilegeTypeMap" :key="key" :value="key">{{ label }}</option>
							</select>
						</td>
					</tr>
					<tr v-if="luNewRequiresConstraints">
						<td class="opencase-cert-label">
							<label for="lu-priv-sens">{{ t('opencase', 'Følsomhed') }}</label>
						</td>
						<td>
							<select id="lu-priv-sens"
								v-model="luNewSensitivities"
								multiple
								:style="{ width: '300px', height: '100px', maxWidth: '100%', boxSizing: 'border-box' }">
								<option v-for="s in luSensitivities" :key="s.uuid" :value="s.uuid">{{ s.title }}</option>
							</select>
						</td>
					</tr>
					<tr v-if="luNewRequiresConstraints">
						<td class="opencase-cert-label">
							<label for="lu-priv-kle">{{ t('opencase', 'KLE') }}</label>
						</td>
						<td>
							<NcCheckboxRadioSwitch v-model="luNewKleAll" type="switch">
								{{ t('opencase', 'Alle') }}
							</NcCheckboxRadioSwitch>
							<template v-if="!luNewKleAll">
								<input id="lu-priv-kle-filter"
									v-model="luKleFilterText"
									type="text"
									:placeholder="t('opencase', 'Filtrer KLE...')"
									:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box', marginTop: '8px', display: 'block' }">
								<select id="lu-priv-kle"
									v-model="luNewKleCodes"
									multiple
									:style="{ width: '300px', height: '120px', maxWidth: '100%', boxSizing: 'border-box', marginTop: '4px' }">
									<option v-for="c in luFilteredClassificationSubjects" :key="c.uuid" :value="c.code">{{ c.code }} — {{ c.title }}</option>
								</select>
							</template>
						</td>
					</tr>
					<tr v-if="luNewRequiresConstraints">
						<td class="opencase-cert-label">
							<label for="lu-priv-org">{{ t('opencase', 'Organisationsenhed') }}</label>
						</td>
						<td>
							<input id="lu-priv-org-filter"
								v-model="luOrgUnitFilterText"
								type="text"
								:placeholder="t('opencase', 'Filtrer organisationsenhed...')"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box', display: 'block' }">
							<select id="lu-priv-org"
								v-model="luNewOrgUnits"
								multiple
								:style="{ width: '300px', height: '100px', maxWidth: '100%', boxSizing: 'border-box', marginTop: '4px' }">
								<option v-for="o in luFilteredOrgUnits" :key="o.org_uuid" :value="o.org_uuid">{{ o.org_name }}</option>
							</select>
							<p style="margin-top:4px;font-size:12px;">{{ t('opencase', 'Lad stå tom for alle organisationsenheder.') }}</p>
						</td>
					</tr>
				</table>

				<div class="opencase-button-row">
					<button class="primary" :disabled="!luNewPrivilegeType || luAddingPrivilege" @click="addLocalUserPrivilege">
						{{ luAddingPrivilege ? t('opencase', 'Tilføjer...') : t('opencase', 'Tilføj rettighed') }}
					</button>
				</div>

				<div v-if="luPrivilegeError" class="certificate-error" style="margin-top:12px;">
					{{ luPrivilegeError }}
				</div>
			</NcSettingsSection>
		</div>

		<div v-show="activeTab === 'transaction_log'">
			<NcSettingsSection :name="t('opencase', 'Transaktionslog')">
				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="tl-username">{{ t('opencase', 'Brugernavn') }}</label>
						</td>
						<td>
							<input id="tl-username"
								v-model="tlUsername"
								type="text"
								:placeholder="t('opencase', 'Del af brugernavn eller personnavn...')"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="tl-from">{{ t('opencase', 'Fra tidspunkt') }}</label>
						</td>
						<td>
							<input id="tl-from"
								v-model="tlFrom"
								type="datetime-local"
								:style="{ width: '240px', maxWidth: '100%', boxSizing: 'border-box' }">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="tl-to">{{ t('opencase', 'Til tidspunkt') }}</label>
						</td>
						<td>
							<input id="tl-to"
								v-model="tlTo"
								type="datetime-local"
								:style="{ width: '240px', maxWidth: '100%', boxSizing: 'border-box' }">
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="tl-source">{{ t('opencase', 'Log') }}</label>
						</td>
						<td>
							<select id="tl-source"
								v-model="tlSource"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="tlType = ''">
								<option value="">{{ t('opencase', 'Begge logge') }}</option>
								<option value="transaction">{{ t('opencase', 'Søgninger og opslag') }}</option>
								<option value="audit">{{ t('opencase', 'Revisionsspor (sager og dokumenter)') }}</option>
							</select>
						</td>
					</tr>
					<tr>
						<td class="opencase-cert-label">
							<label for="tl-type">{{ t('opencase', 'Transaktionstype') }}</label>
						</td>
						<td>
							<select id="tl-type"
								v-model="tlType"
								:style="{ width: '300px', maxWidth: '100%', boxSizing: 'border-box' }">
								<option value="">{{ t('opencase', 'Alle typer') }}</option>
								<optgroup v-if="tlSource !== 'audit' && tlTypes.transaction.length > 0"
									:label="t('opencase', 'Søgninger og opslag')">
									<option v-for="type in tlTypes.transaction" :key="'t-' + type" :value="type">
										{{ type }}
									</option>
								</optgroup>
								<optgroup v-if="tlSource !== 'transaction' && tlTypes.audit.length > 0"
									:label="t('opencase', 'Revisionsspor')">
									<option v-for="type in tlTypes.audit" :key="'a-' + type" :value="type">
										{{ type }}
									</option>
								</optgroup>
							</select>
						</td>
					</tr>
				</table>

				<div class="opencase-button-row" style="display:flex; gap:8px;">
					<button class="primary" :disabled="tlSearching" @click="searchTransactionLog">
						{{ tlSearching ? t('opencase', 'Søger...') : t('opencase', 'Søg') }}
					</button>
					<button v-if="tlResults.length > 0" @click="exportTransactionLog">
						{{ t('opencase', 'Eksporter til CSV') }}
					</button>
				</div>

				<div v-if="tlError" class="certificate-error" style="margin-top:12px;">
					{{ tlError }}
				</div>

				<table v-if="tlResults.length > 0" class="opencase-sync-log-table" style="max-width:100%;">
					<caption>{{ t('opencase', 'Resultater') }}: {{ tlResults.length }}</caption>
					<thead>
						<tr>
							<th>{{ t('opencase', 'Visningsnavn') }}</th>
							<th>{{ t('opencase', 'Brugernavn') }}</th>
							<th>{{ t('opencase', 'Tidspunkt') }}</th>
							<th>{{ t('opencase', 'Log') }}</th>
							<th>{{ t('opencase', 'Type') }}</th>
							<th>{{ t('opencase', 'Sag/dokument') }}</th>
							<th>{{ t('opencase', 'Detaljer') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in tlResults" :key="row.source + '-' + row.id">
							<td>{{ row.display_name }}</td>
							<td>{{ row.user_id }}</td>
							<td>{{ row.transaction_time }}</td>
							<td>{{ tlSourceLabel(row.source) }}</td>
							<td>{{ row.transaction_type }}</td>
							<td>{{ tlContext(row) }}</td>
							<td>{{ tlDetails(row) }}</td>
						</tr>
					</tbody>
				</table>
				<p v-else-if="tlSearched && !tlSearching" style="margin-top:12px;">
					{{ t('opencase', 'Ingen resultater fundet.') }}
				</p>
			</NcSettingsSection>
		</div>

		<div v-show="activeTab === 'export'">
			<NcSettingsSection :name="t('opencase', 'Eksporter lukkede sager')">
				<NcCheckboxRadioSwitch id="export-enable"
					v-model="exportEnabled"
					type="switch">
					{{ t('opencase', 'Aktiver') }}
				</NcCheckboxRadioSwitch>

				<table class="opencase-cert-table">
					<tr>
						<td class="opencase-cert-label">
							<label for="export-folder">{{ t('opencase', 'Eksport mappe') }}</label>
						</td>
						<td>
							<input id="export-folder"
								v-model="exportFolder"
								type="text"
								:style="{ width: '600px', maxWidth: '100%', boxSizing: 'border-box' }"
								@change="saveConfigValue('export_folder', exportFolder)">
						</td>
					</tr>
				</table>

				<div class="opencase-button-row" style="display:flex; gap:8px;">
					<button class="primary" :disabled="exportRunning" @click="runExportNow">
						{{ exportRunning ? t('opencase', 'Eksporterer...') : t('opencase', 'Eksporter nu') }}
					</button>
					<a class="button primary"
						:href="exportSchemaUrl()"
						target="_blank"
						rel="noopener">
						{{ t('opencase', 'Download XML skema') }}
					</a>
				</div>

				<div v-if="exportRunResult" class="opencase-cert-info-box">
					<div><strong>{{ t('opencase', 'Antal:') }}</strong> {{ exportRunResult.count }}</div>
					<div><strong>{{ t('opencase', 'Eksporteret:') }}</strong> {{ exportRunResult.exported }}</div>
					<div><strong>{{ t('opencase', 'Fejlet:') }}</strong> {{ exportRunResult.failed }}</div>
				</div>
				<div v-if="exportRunError" class="certificate-error">
					{{ exportRunError }}
				</div>

				<table v-if="exportLog.length > 0" class="opencase-sync-log-table">
					<caption>{{ t('opencase', 'Eksportlog') }}</caption>
					<thead>
						<tr>
							<th>{{ t('opencase', 'Tidspunkt') }}</th>
							<th>{{ t('opencase', 'Antal') }}</th>
							<th>{{ t('opencase', 'Eksporteret') }}</th>
							<th>{{ t('opencase', 'Fejlet') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(entry, index) in exportLog" :key="index">
							<td>{{ entry.sync_time }}</td>
							<td>{{ entry.count }}</td>
							<td>{{ entry.exported }}</td>
							<td>{{ entry.failed }}</td>
						</tr>
					</tbody>
				</table>
			</NcSettingsSection>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import OverflowTabs from './components/OverflowTabs.vue'
import { maskCpr } from './utils/cprMask.js'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

export default {
	name: 'AdminSettings',
	components: {
		NcCheckboxRadioSwitch,
		NcModal,
		NcSettingsSection,
		OverflowTabs,
		ContentCopyIcon,
		CheckIcon,
	},
	computed: {
		requiredApps() {
			return [
				{ key: 'groupfolders', label: t('opencase', 'Group folders'), enabled: this.groupfoldersEnabled },
				{ key: 'fulltextsearch', label: t('opencase', 'Full text search'), enabled: this.fulltextsearchEnabled },
				{ key: 'files_fulltextsearch', label: t('opencase', 'Full text search - Files'), enabled: this.filesFulltextsearchEnabled },
				{ key: 'fulltextsearch_elasticsearch', label: t('opencase', 'Full text search - Elasticsearch platform'), enabled: this.fulltextsearchElasticsearchEnabled },
			]
		},
		recommendedApps() {
			return [
				{ key: 'assistant', label: t('opencase', 'Nextcloud Assistant'), enabled: this.assistantEnabled },
				{ key: 'integration_openai', label: t('opencase', 'OpenAI and LocalAI integration'), enabled: this.integrationOpenaiEnabled },
			]
		},
		editionLabel() {
			return this.enterpriseVersion
				? t('opencase', 'OpenCase Enterprise ({version})', { version: this.appVersion })
				: t('opencase', 'OpenCase Basic ({version})', { version: this.appVersion })
		},
		domain() {
			return window.location.hostname
		},
		certExpiresFormatted() {
			if (!this.certExpiresAt) {
				return ''
			}
			const date = new Date(this.certExpiresAt * 1000)
			return date.toLocaleDateString('da-DK', { year: 'numeric', month: 'long', day: 'numeric' })
		},
		certDatafordelerExpiresFormatted() {
			if (!this.certDatafordeler.expiresAt) {
				return ''
			}
			const date = new Date(this.certDatafordeler.expiresAt * 1000)
			return date.toLocaleDateString('da-DK', { year: 'numeric', month: 'long', day: 'numeric' })
		},
		apiClientsApi() {
			return this.apiClients.filter((client) => client.valid_for === 'API')
		},
		apiClientsAdgangsstyring() {
			return this.apiClients.filter((client) => client.valid_for === 'Adgangsstyring')
		},
		apiClientsServiceplatformen() {
			return this.apiClients.filter((client) => client.valid_for === 'Serviceplatformen callback')
		},
		certificateDialogInfo() {
			return this.certificateDialogClient?.certificate_info || {}
		},
		tabs() {
			return [
				{ id: 'serviceplatformen', label: t('opencase', 'Serviceplatformen') },
				{ id: 'datafordeler', label: t('opencase', 'Datafordeler') },
				{ id: 'msoffice', label: t('opencase', 'MS Office') },
				{ id: 'api', label: t('opencase', 'API') },
				{ id: 'local_users', label: t('opencase', 'Lokale brugere') },
				{ id: 'transaction_log', label: t('opencase', 'Transaktionslog') },
				{ id: 'export', label: t('opencase', 'Eksport') },
			]
		},
		msofficeApps() {
			return [
				{ id: 'outlook', label: 'Outlook' },
				{ id: 'word', label: 'Word' },
				{ id: 'excel', label: 'Excel' },
				{ id: 'powerpoint', label: 'PowerPoint' },
			]
		},
		msofficeRedirectUri() {
			// Best guess for the common case: the add-in is hosted on this
			// same Nextcloud domain (matches nginx/deploy.sh's own hostname
			// auto-detection), on the configured "Add-in port" (or 1443).
			const port = String(this.msofficeAddinPort || '1443')
			const host = window.location.hostname
			const base = port === '443' ? host : `${host}:${port}`
			return `https://${base}/shared/graph-auth.html`
		},
		privilegeTypeMap() {
			return {
				systemadministrator: t('opencase', 'Systemadministrator'),
				opencaseadministrator: t('opencase', 'OpenCase administrator'),
				opencasetemplateadministrator: t('opencase', 'Skabelonadministrator'),
				opencaseuser: t('opencase', 'Bruger'),
				opencasereaduser: t('opencase', 'Læsebruger'),
			}
		},
		luNewRequiresConstraints() {
			return this.luNewPrivilegeType === 'opencaseuser' || this.luNewPrivilegeType === 'opencasereaduser'
		},
		luFilteredClassificationSubjects() {
			const filter = this.luKleFilterText.trim().toLowerCase()
			if (!filter) {
				return this.luClassificationSubjects
			}
			return this.luClassificationSubjects.filter(c => (c.code && c.code.toLowerCase().includes(filter))
				|| (c.title && c.title.toLowerCase().includes(filter)))
		},
		luFilteredOrgUnits() {
			const filter = this.luOrgUnitFilterText.trim().toLowerCase()
			if (!filter) {
				return this.luOrgUnits
			}
			return this.luOrgUnits.filter(o => o.org_name && o.org_name.toLowerCase().includes(filter))
		},
	},
	data() {
		const certificate = loadState('opencase', 'certificate', { filepath: '', password: '' })
		const config = loadState('opencase', 'config', {})
		const syncLog = loadState('opencase', 'syncLog', [])
		const syncLogKlassifikation = loadState('opencase', 'syncLogKlassifikation', [])
		const prerequisites = loadState('opencase', 'prerequisites', {})
		const apiClients = loadState('opencase', 'apiClients', [])
		const certificateDatafordeler = loadState('opencase', 'certificateDatafordeler', { valid: false, error: 'not_found' })
		const exportLog = loadState('opencase', 'exportLog', [])
		const appVersion = loadState('opencase', 'appVersion', '')
		return {
			activeTab: 'serviceplatformen',
			appVersion,
			enterpriseVersion: config.enterprise_version === '1',
			groupfoldersEnabled: prerequisites.groupfolders || false,
			fulltextsearchEnabled: prerequisites.fulltextsearch || false,
			filesFulltextsearchEnabled: prerequisites.files_fulltextsearch || false,
			fulltextsearchElasticsearchEnabled: prerequisites.fulltextsearch_elasticsearch || false,
			assistantEnabled: prerequisites.assistant || false,
			integrationOpenaiEnabled: prerequisites.integration_openai || false,
			syncLog,
			filepath: certificate.filepath,
			password: certificate.password,
			certSubject: '',
			certSerialNumber: '',
			certExpiresAt: 0,
			certError: '',
			accessControlEnable: config.access_control_enable === '1',
			idpMetadataUrl: config.idp_metadata_url || '',
			organisationEnable: config.organisation_enable === '1',
			teamfoldersEnable: config.teamfolders_enable === '1',
			cvr: config.cvr || '',
			tokenIssuerBaseUrl: config.token_issuer_base_url || '',
			entityIdOrganisation: config.entity_id_organisation || '',
			endpointOrganisation: config.endpoint_organisation || '',
			endpointOrganisationEnhed: config.endpoint_organisation_enhed || '',
			endpointOrganisationFunction: config.endpoint_organisation_function || '',
			endpointUser: config.endpoint_user || '',
			endpointPerson: config.endpoint_person || '',
			endpointAddress: config.endpoint_address || '',
			entityIdFordeling: config.entity_id_fordeling || '',
			endpointFordeling: config.endpoint_fordeling || '',
			syncing: false,
			syncResult: null,
			syncError: '',
			syncPollTimer: null,
			classificationEnable: config.classification_enable === '1',
			entityIdKlassifikation: config.entity_id_classification || '',
			endpointKlassifikation: config.endpoint_classification || '',
			syncLogKlassifikation,
			syncingKlassifikation: false,
			syncResultKlassifikation: null,
			syncErrorKlassifikation: '',
			uploadingKleSubjects: false,
			uploadingKleFacets: false,
			digitalpostCvr: config.digitalpost_cvr || '',
			digitalpostSenderLabel: config.digitalpost_sender_label || '',
			entityIdKombipostafsend: config.entity_id_kombipostafsend || '',
			endpointKombipostafsend: config.endpoint_kombipostafsend || '',
			entityIdPostforespoerg: config.entity_id_postforespoerg || '',
			endpointPostforespoerg: config.endpoint_postforespoerg || '',
			datafordelerEndpointCvr: config.datafordeler_endpoint_cvr || '',
			datafordelerApikeyCvr: config.datafordeler_apikey_cvr || '',
			datafordelerEndpointCpr: config.datafordeler_endpoint_cpr || '',
			certDatafordeler: certificateDatafordeler,
			msofficeAddinName: config.msoffice_addin_name || 'OpenCase',
			msofficeAddinPort: config.msoffice_addin_port || '',
			msofficeGraphClientId: config.msoffice_graph_client_id || '',
			msofficeGraphTenantId: config.msoffice_graph_tenant_id || '',
			msofficeUriCopied: false,
			sftpHost: config.sftp_host || '',
			sftpUserName: config.sftp_user_name || '',
			sftpSshPrivateKeyFullFileName: config.sftp_ssh_private_key_full_fileName || '',
			sftpSshKeyPassPhrase: config.sftp_ssh_key_pass_phrase || '',
			tlUsername: '',
			tlFrom: '',
			tlTo: '',
			tlType: '',
			tlSource: '',
			tlTypes: { transaction: [], audit: [] },
			tlTypesLoaded: false,
			tlSearching: false,
			tlSearched: false,
			tlResults: [],
			tlError: '',
			apiClients,
			apiClientsError: '',
			certificateDialogClient: null,
			luQuery: '',
			luResults: [],
			luSearching: false,
			luSearched: false,
			luSelected: null,
			luPersonname: '',
			luEmail: '',
			luPhone: '',
			luLocation: '',
			luSaving: false,
			luSaved: false,
			luError: '',
			luPrivileges: [],
			luPrivilegesLoading: false,
			luSensitivities: [],
			luClassificationSubjects: [],
			luOrgUnits: [],
			luReferenceDataLoaded: false,
			luNewPrivilegeType: '',
			luNewSensitivities: [],
			luNewKleAll: false,
			luNewKleCodes: [],
			luKleFilterText: '',
			luNewOrgUnits: [],
			luOrgUnitFilterText: '',
			luAddingPrivilege: false,
			luPrivilegeError: '',
			exportEnabled: config.export_enabled === '1',
			exportFolder: config.export_folder || '',
			exportLog,
			exportRunning: false,
			exportRunResult: null,
			exportRunError: '',
		}
	},
	watch: {
		accessControlEnable(value) {
			this.saveConfigValue('access_control_enable', value ? '1' : '0')
		},
		organisationEnable(value) {
			this.saveConfigValue('organisation_enable', value ? '1' : '0')
		},
		teamfoldersEnable(value) {
			this.saveConfigValue('teamfolders_enable', value ? '1' : '0')
		},
		classificationEnable(value) {
			this.saveConfigValue('classification_enable', value ? '1' : '0')
		},
		exportEnabled(value) {
			this.saveConfigValue('export_enabled', value ? '1' : '0')
		},
		activeTab(value) {
			// Fetch the filter's type list the first time the tab is opened,
			// rather than on every settings page load.
			if (value === 'transaction_log' && !this.tlTypesLoaded) {
				this.tlTypesLoaded = true
				this.loadTransactionLogTypes()
			}
		},
	},
	async mounted() {
		if (this.filepath && this.password) {
			this.validateCertificate()
		}
		// Resume polling if a sync was already running when the page was opened
		try {
			const res = await axios.get(generateUrl('/apps/opencase/settings/sync-organisations/status'))
			if (res.data.status === 'running') {
				this.syncing = true
				this.startSyncPolling()
			}
		} catch (e) {
			// ignore — status check is best-effort
		}
	},

	beforeUnmount() {
		if (this.syncPollTimer) {
			clearInterval(this.syncPollTimer)
		}
	},
	methods: {
		t,
		async saveConfigValue(key, value) {
			try {
				await axios.post(
					generateUrl('/apps/opencase/settings/config'),
					{ key, value },
				)
			} catch (e) {
				console.error('Failed to save config', e)
			}
		},
		async downloadMetadata() {
			try {
				const response = await axios.post(
					generateUrl('/apps/opencase/settings/metadata'),
					{},
					{ responseType: 'blob' },
				)
				const url = URL.createObjectURL(response.data)
				const a = document.createElement('a')
				a.href = url
				a.download = 'sp-metadata.xml'
				document.body.appendChild(a)
				a.click()
				document.body.removeChild(a)
				URL.revokeObjectURL(url)
			} catch (e) {
				console.error('Failed to download metadata', e)
			}
		},
		msofficeManifestUrl(appId) {
			return generateUrl(`/apps/opencase/settings/msoffice/${appId}/manifest.xml`)
		},
		exportSchemaUrl() {
			return generateUrl('/apps/opencase/settings/export/schema.xsd')
		},
		async runExportNow() {
			this.exportRunResult = null
			this.exportRunError = ''
			this.exportRunning = true
			try {
				const response = await axios.post(generateUrl('/apps/opencase/settings/export/run'))
				this.exportRunResult = response.data
			} catch (e) {
				this.exportRunError = t('opencase', 'Eksport fejlede')
				console.error('Export failed', e)
			} finally {
				this.exportRunning = false
			}
		},
		async copyMsofficeRedirectUri() {
			try {
				await navigator.clipboard.writeText(this.msofficeRedirectUri)
			} catch (e) {
				console.error('Failed to copy to clipboard', e)
				return
			}
			this.msofficeUriCopied = true
			setTimeout(() => { this.msofficeUriCopied = false }, 2000)
		},
		async syncOrganisations() {
			this.syncResult = null
			this.syncError = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/opencase/settings/sync-organisations'),
				)
				if (response.data.status === 'started' || response.data.status === 'running') {
					this.syncing = true
					this.startSyncPolling()
				}
			} catch (e) {
				this.syncError = t('opencase', 'Synchronization failed')
				console.error('Sync failed', e)
			}
		},

		startSyncPolling() {
			if (this.syncPollTimer) return
			this.syncPollTimer = setInterval(async () => {
				try {
					const res = await axios.get(
						generateUrl('/apps/opencase/settings/sync-organisations/status'),
					)
					if (res.data.status === 'idle') {
						clearInterval(this.syncPollTimer)
						this.syncPollTimer = null
						this.syncing = false
						if (res.data.error) {
							this.syncError = res.data.error
						} else {
							this.syncResult = res.data.result
						}
					}
				} catch (e) {
					clearInterval(this.syncPollTimer)
					this.syncPollTimer = null
					this.syncing = false
					this.syncError = t('opencase', 'Synchronization failed')
				}
			}, 3000)
		},
		async syncKlassifikation() {
			this.syncingKlassifikation = true
			this.syncResultKlassifikation = null
			this.syncErrorKlassifikation = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/opencase/settings/sync-klassifikation'),
				)
				this.syncResultKlassifikation = response.data
			} catch (e) {
				this.syncErrorKlassifikation = t('opencase', 'Synchronization failed')
				console.error('Sync failed', e)
			} finally {
				this.syncingKlassifikation = false
			}
		},
		onKleSubjectsFileSelected(event) {
			const file = event.target.files[0]
			event.target.value = ''
			if (file) {
				this.uploadClassificationCsv(file, 'subjects')
			}
		},
		onKleFacetsFileSelected(event) {
			const file = event.target.files[0]
			event.target.value = ''
			if (file) {
				this.uploadClassificationCsv(file, 'facets')
			}
		},
		async uploadClassificationCsv(file, kind) {
			const uploadingKey = kind === 'facets' ? 'uploadingKleFacets' : 'uploadingKleSubjects'
			this[uploadingKey] = true
			this.syncResultKlassifikation = null
			this.syncErrorKlassifikation = ''
			try {
				const formData = new FormData()
				formData.append('file', file)
				const response = await axios.post(
					generateUrl(`/apps/opencase/settings/classification/${kind}/upload`),
					formData,
					{ headers: { 'Content-Type': 'multipart/form-data' } },
				)
				this.syncResultKlassifikation = response.data
			} catch (e) {
				this.syncErrorKlassifikation = e.response?.data?.error || t('opencase', 'Upload failed')
				console.error('Classification CSV upload failed', e)
			} finally {
				this[uploadingKey] = false
			}
		},
		async validateCertificate() {
			this.certSubject = ''
			this.certSerialNumber = ''
			this.certExpiresAt = 0
			this.certError = ''

			try {
				const response = await axios.post(
					generateUrl('/apps/opencase/settings/certificate/validate'),
					{
						filepath: this.filepath,
						password: this.password,
					},
				)
				if (response.data.valid) {
					this.certSubject = response.data.subject
					this.certSerialNumber = response.data.serialNumber
					this.certExpiresAt = response.data.expiresAt
				} else {
					this.certError = response.data.error
				}
			} catch (e) {
				this.certError = 'cannot_read'
			}
		},
		async loadTransactionLogTypes() {
			try {
				const response = await axios.get(generateUrl('/apps/opencase/settings/transaction-log/types'))
				this.tlTypes = {
					transaction: response.data.transaction || [],
					audit: response.data.audit || [],
				}
			} catch (e) {
				console.error('Loading transaction log types failed', e)
			}
		},
		tlSourceLabel(source) {
			return source === 'audit'
				? t('opencase', 'Revisionsspor')
				: t('opencase', 'Søgning/opslag')
		},
		tlDetails(row) {
			// The raw details payload holds full CPR numbers; the last four
			// digits are never shown in the UI.
			if (!row.details) {
				return ''
			}
			let parsed
			try {
				parsed = JSON.parse(row.details)
			} catch (e) {
				return row.details
			}
			if (parsed === null || typeof parsed !== 'object') {
				return row.details
			}
			const masked = {}
			for (const [key, value] of Object.entries(parsed)) {
				masked[key] = ['cpr', 'cvr', 'cpr_cvr'].includes(key) ? maskCpr(value) : value
			}
			return JSON.stringify(masked)
		},
		tlContext(row) {
			const parts = []
			if (row.case_id) {
				parts.push(t('opencase', 'Sag') + ' ' + row.case_id)
			}
			if (row.document_id) {
				parts.push(t('opencase', 'Dokument') + ' ' + row.document_id)
			}
			if (row.file_id) {
				parts.push(t('opencase', 'Fil') + ' ' + row.file_id)
			}
			return parts.join(', ')
		},
		async searchTransactionLog() {
			this.tlSearching = true
			this.tlSearched = false
			this.tlError = ''
			this.tlResults = []
			try {
				const toValue = this.tlTo ? this.tlTo.replace('T', ' ') + ':00' : ''
				const fromValue = this.tlFrom ? this.tlFrom.replace('T', ' ') + ':00' : ''
				const response = await axios.post(
					generateUrl('/apps/opencase/settings/transaction-log'),
					{
						username: this.tlUsername,
						from: fromValue,
						to: toValue,
						type: this.tlType,
						source: this.tlSource,
					},
				)
				this.tlResults = response.data
			} catch (e) {
				this.tlError = t('opencase', 'Søgning fejlede')
				console.error('Transaction log search failed', e)
			} finally {
				this.tlSearching = false
				this.tlSearched = true
			}
		},
		exportTransactionLog() {
			const headers = ['Visningsnavn', 'Brugernavn', 'Tidspunkt', 'Log', 'Type', 'Sag/dokument', 'Detaljer']
			const rows = this.tlResults.map(r => [
				r.display_name || '',
				r.user_id,
				r.transaction_time,
				this.tlSourceLabel(r.source),
				r.transaction_type,
				this.tlContext(r),
				this.tlDetails(r),
			])
			const csv = [headers, ...rows]
				.map(row => row.map(cell => '"' + String(cell).replace(/"/g, '""') + '"').join(','))
				.join('\r\n')
			const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = 'transaktionslog.csv'
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
			URL.revokeObjectURL(url)
		},
		isApiClientActive(client) {
			return Number(client.active) === 1
		},
		formatApiClientDate(value) {
			if (!value) {
				return '—'
			}
			return new Date(value.replace(' ', 'T')).toLocaleDateString('da-DK', { year: 'numeric', month: 'long', day: 'numeric' })
		},
		toDateInputValue(value) {
			if (!value) {
				return ''
			}
			return value.slice(0, 10)
		},
		formatUnixDate(timestamp) {
			if (!timestamp) {
				return '—'
			}
			return new Date(timestamp * 1000).toLocaleDateString('da-DK', { year: 'numeric', month: 'long', day: 'numeric' })
		},
		openCertificateDialog(client) {
			this.certificateDialogClient = client
		},
		async setApiClientActive(client, active) {
			this.apiClientsError = ''
			try {
				const response = await axios.post(
					generateUrl(`/apps/opencase/settings/api-clients/${client.id}/active`),
					{ active },
				)
				this.apiClients = response.data.clients
			} catch (e) {
				this.apiClientsError = t('opencase', 'Kunne ikke opdatere API-klienten')
				console.error('Failed to update API client', e)
			}
		},
		async setApiClientExpires(client, expiresAt) {
			this.apiClientsError = ''
			try {
				const response = await axios.post(
					generateUrl(`/apps/opencase/settings/api-clients/${client.id}/expires`),
					{ expires_at: expiresAt },
				)
				this.apiClients = response.data.clients
			} catch (e) {
				this.apiClientsError = t('opencase', 'Kunne ikke opdatere udløbsdatoen')
				console.error('Failed to update API client expiry', e)
			}
		},
		async deleteApiClient(client) {
			if (!window.confirm(t('opencase', 'Er du sikker på, at du vil slette API-klienten "{name}"?', { name: client.name }))) {
				return
			}
			this.apiClientsError = ''
			try {
				const response = await axios.post(
					generateUrl(`/apps/opencase/settings/api-clients/${client.id}/delete`),
				)
				this.apiClients = response.data.clients
			} catch (e) {
				this.apiClientsError = t('opencase', 'Kunne ikke slette API-klienten')
				console.error('Failed to delete API client', e)
			}
		},
		async searchLocalUsers() {
			this.luSearching = true
			this.luSearched = false
			this.luError = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/opencase/settings/local-users/search'),
					{ params: { q: this.luQuery } },
				)
				this.luResults = response.data.users
			} catch (e) {
				this.luError = t('opencase', 'Søgning fejlede')
				console.error('Local user search failed', e)
				this.luResults = []
			} finally {
				this.luSearching = false
				this.luSearched = true
			}
		},
		async selectLocalUser(u) {
			this.luSelected = u
			this.luSaved = false
			this.luError = ''
			this.luPrivilegeError = ''
			this.resetNewPrivilegeForm()
			try {
				const response = await axios.get(
					generateUrl(`/apps/opencase/settings/local-users/${u.uid}`),
				)
				const info = response.data.userinfo
				this.luPersonname = info.personname
				this.luEmail = info.email
				this.luPhone = info.phone
				this.luLocation = info.location
			} catch (e) {
				this.luError = t('opencase', 'Kunne ikke hente brugeroplysninger')
				console.error('Failed to load user info', e)
			}
			this.loadPrivilegeReferenceData()
			this.loadLocalUserPrivileges()
		},
		async saveLocalUserInfo() {
			if (!this.luSelected) {
				return
			}
			this.luSaving = true
			this.luSaved = false
			this.luError = ''
			try {
				await axios.post(
					generateUrl(`/apps/opencase/settings/local-users/${this.luSelected.uid}`),
					{
						personname: this.luPersonname,
						email: this.luEmail,
						phone: this.luPhone,
						location: this.luLocation,
					},
				)
				this.luSaved = true
			} catch (e) {
				this.luError = t('opencase', 'Kunne ikke gemme brugeroplysninger')
				console.error('Failed to save user info', e)
			} finally {
				this.luSaving = false
			}
		},
		async loadPrivilegeReferenceData() {
			if (this.luReferenceDataLoaded) {
				return
			}
			try {
				const ocsBaseUrl = generateOcsUrl('/apps/opencase/api/v1')
				const [sensRes, kleRes, orgRes] = await Promise.all([
					axios.get(`${ocsBaseUrl}/sensitivities`),
					axios.get(`${ocsBaseUrl}/classification-subjects`),
					axios.get(`${ocsBaseUrl}/employees/org-tree`),
				])
				this.luSensitivities = (sensRes.data?.ocs?.data ?? sensRes.data).sensitivities
				this.luClassificationSubjects = (kleRes.data?.ocs?.data ?? kleRes.data).classification_subjects
				this.luOrgUnits = (orgRes.data?.ocs?.data ?? orgRes.data).orgs
				this.luReferenceDataLoaded = true
			} catch (e) {
				console.error('Failed to load privilege reference data', e)
			}
		},
		async loadLocalUserPrivileges() {
			if (!this.luSelected) {
				return
			}
			this.luPrivilegesLoading = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/opencase/settings/local-users/${this.luSelected.uid}/privileges`),
				)
				this.luPrivileges = response.data.privileges
			} catch (e) {
				console.error('Failed to load privileges', e)
				this.luPrivileges = []
			} finally {
				this.luPrivilegesLoading = false
			}
		},
		resetNewPrivilegeForm() {
			this.luNewPrivilegeType = ''
			this.luNewSensitivities = []
			this.luNewKleAll = false
			this.luNewKleCodes = []
			this.luKleFilterText = ''
			this.luNewOrgUnits = []
			this.luOrgUnitFilterText = ''
		},
		// A KLE code with fewer than 3 segments (main group "00" or group "27.03")
		// denotes a whole subtree, so it must be turned into a wildcard pattern
		// ("00.*", "27.03.*") to match every code beneath it. A full 3-segment
		// leaf code (e.g. "27.03.05") already identifies a single subject and is
		// stored as-is.
		kleCodeToPattern(code) {
			const segments = code.split('.').filter(s => s !== '')
			return segments.length < 3 ? code + '.*' : code
		},
		async addLocalUserPrivilege() {
			if (!this.luSelected || !this.luNewPrivilegeType) {
				return
			}
			this.luAddingPrivilege = true
			this.luPrivilegeError = ''
			try {
				const kle = this.luNewKleAll ? ['*'] : this.luNewKleCodes.map(this.kleCodeToPattern)
				const response = await axios.post(
					generateUrl(`/apps/opencase/settings/local-users/${this.luSelected.uid}/privileges`),
					{
						privilege_type: this.luNewPrivilegeType,
						foelsomhed: this.luNewSensitivities,
						kle,
						orgenhed: this.luNewOrgUnits,
					},
				)
				this.luPrivileges = response.data.privileges
				this.resetNewPrivilegeForm()
			} catch (e) {
				this.luPrivilegeError = e.response?.data?.error || t('opencase', 'Kunne ikke tilføje rettigheden')
				console.error('Failed to add privilege', e)
			} finally {
				this.luAddingPrivilege = false
			}
		},
		async deleteLocalUserPrivilege(id) {
			if (!this.luSelected) {
				return
			}
			if (!window.confirm(t('opencase', 'Er du sikker på, at du vil slette denne rettighed?'))) {
				return
			}
			this.luPrivilegeError = ''
			try {
				const response = await axios.delete(
					generateUrl(`/apps/opencase/settings/local-users/${this.luSelected.uid}/privileges/${id}`),
				)
				this.luPrivileges = response.data.privileges
			} catch (e) {
				this.luPrivilegeError = t('opencase', 'Kunne ikke slette rettigheden')
				console.error('Failed to delete privilege', e)
			}
		},
	},
}
</script>

<style>

.opencase-cert-table {
	margin-top: 12px;
	border-collapse: separate;
	border-spacing: 0 8px;
}

.opencase-cert-label {
	font-weight: bold;
	padding-right: 16px;
	white-space: nowrap;
	vertical-align: middle;
}

.certificate-error {
	margin-top: 12px;
	padding: 6px 8px;
	background-color: var(--color-error, #e9322d);
	background-color:  #e9322d;
	color: #fff;
	font-weight: bold;
	border-radius: 8px;
	display: inline-block;
}

.opencase-link-disabled {
	opacity: 0.5;
	pointer-events: none;
	cursor: not-allowed;
}

.enterprise-version-warning {
	color: #e9322d;
	font-weight: bold;
	padding: 12px 0px;
}

.opencase-cert-info-box {
	margin-top: 12px;
	padding: 6px 8px;
	background-color: var(--color-success, #D8F3DA);
	background-color: #D8F3DA;
	color: #000;
	border-radius: 8px;
	display: inline-block;
}

.opencase-cert-info-box div {
	margin: 4px 0;
}

.prerequisites-error-box {
	margin-top: 12px;
	padding: 6px 8px;
	background-color: var(--color-error, #e9322d);
	background-color: #e9322d;
	color: #fff;
	border-radius: 8px;
	display: inline-block;
}

.prerequisites-error-box div {
	margin: 4px 0;
}

.prerequisites-warning-box {
	margin-top: 12px;
	padding: 6px 8px;
	background-color: var(--color-warning, #ffc107);
	background-color: #ffc107;
	color: #000;
	border-radius: 8px;
	display: inline-block;
}

.prerequisites-warning-box div {
	margin: 4px 0;
}

.opencase-button-row {
	margin-top: 16px;
}

.opencase-msoffice-hint {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.opencase-copy-row {
	display: flex;
	align-items: center;
	gap: 8px;
	max-width: 600px;
	margin-top: 4px;
}

.opencase-copy-value {
	flex: 1;
	padding: 6px 10px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 0.9em;
	overflow-x: auto;
	white-space: nowrap;
}

.opencase-copy-row .btn-icon {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	cursor: pointer;
}

.opencase-copy-row .btn-icon:hover {
	background: var(--color-background-hover);
}

.opencase-sync-log-table {
	margin-top: 16px;
	border-collapse: collapse;
	width: 100%;
	max-width: 600px;
}

.opencase-sync-log-table caption {
	text-align: left;
	font-weight: bold;
	margin-bottom: 8px;
}

.opencase-sync-log-table th,
.opencase-sync-log-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.opencase-sync-log-table th {
	font-weight: bold;
	background-color: var(--color-background-dark, #f5f5f5);
}

.opencase-sync-log-table tbody tr:hover {
	background-color: var(--color-background-hover, #f0f0f0);
}

.opencase-roles-table {
	margin-top: 24px;
	border-collapse: collapse;
	width: 100%;
}

.opencase-roles-table caption {
	text-align: left;
	font-weight: bold;
	margin-bottom: 8px;
}

.opencase-roles-table th,
.opencase-roles-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.opencase-roles-table th {
	font-weight: bold;
	background-color: var(--color-background-dark, #f5f5f5);
}

.opencase-roles-table tbody tr:hover {
	background-color: var(--color-background-hover, #f0f0f0);
}

.opencase-prerequisites {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.opencase-prerequisite {
	font-size: 14px;
}

.opencase-check {
	color: #2ea043;
	font-weight: bold;
	margin-right: 8px;
}

.opencase-cross {
	color: #e9322d;
	font-weight: bold;
	margin-right: 8px;
}

.opencase-api-clients-table {
	max-width: 100%;
}

.opencase-api-clients-fingerprint {
	font-family: monospace;
	word-break: break-all;
}

.opencase-api-clients-subject {
	word-break: break-word;
}

.opencase-api-clients-row {
	cursor: pointer;
}

.opencase-api-clients-row:hover {
	background-color: var(--color-background-hover);
}

.opencase-api-clients-empty {
	opacity: 0.7;
}

.opencase-api-subsection {
	margin-top: 24px;
}

.opencase-cert-dialog {
	padding: 20px 30px 30px;
}

.opencase-cert-dialog .opencase-cert-table td {
	word-break: break-word;
}
</style>
