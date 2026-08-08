import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const baseUrl = generateOcsUrl('/apps/opencase/api/v1')

/**
 * Unwrap the OCS envelope.
 *
 * @nextcloud/axios v2+ does NOT auto-unwrap OCS responses.
 * The raw response.data is {"ocs":{"meta":...,"data":{...}}}.
 * This helper returns the inner `ocs.data` payload.
 *
 * @param {object} response - The raw axios response
 * @returns {object} The unwrapped OCS data payload
 */
const ocsData = (response) => response.data?.ocs?.data ?? response.data

/**
 * OpenCase API client.
 *
 * All methods return the response data directly (unwrapped from OCS envelope).
 * Errors are thrown and should be caught by the caller.
 */
export default {

	// ---------------------------------------------------------------
	// Widget
	// ---------------------------------------------------------------

	async getMyCases(params = {}) {
		return ocsData(await axios.get(`${baseUrl}/widget/my-cases`, { params }))
	},

	// ---------------------------------------------------------------
	// Cases
	// ---------------------------------------------------------------

	async getCases(params = {}) {
		return ocsData(await axios.get(`${baseUrl}/cases`, { params }))
	},

	async getCase(id, { audit = true } = {}) {
		return ocsData(await axios.get(`${baseUrl}/cases/${id}`, { params: { audit } })).case
	},

	async createCase(payload) {
		return ocsData(await axios.post(`${baseUrl}/cases`, payload)).case
	},

	async updateCase(id, payload) {
		return ocsData(await axios.put(`${baseUrl}/cases/${id}`, payload)).case
	},

	async changeCaseStatus(id, statusId) {
		return ocsData(await axios.put(`${baseUrl}/cases/${id}/status`, { status_id: statusId })).case
	},

	async getCaseStats() {
		return ocsData(await axios.get(`${baseUrl}/cases/stats`)).stats
	},

	async getCaseHierarchy(id) {
		return ocsData(await axios.get(`${baseUrl}/cases/${id}/hierarchy`)).tree
	},

	// ---------------------------------------------------------------
	// Favorites
	// ---------------------------------------------------------------

	async getFavorites() {
		return ocsData(await axios.get(`${baseUrl}/favorites`)).favorites
	},

	async addFavorite(entity, key) {
		return ocsData(await axios.post(`${baseUrl}/favorites`, { entity, key })).favorite
	},

	async removeFavorite(entity, key) {
		return ocsData(await axios.delete(`${baseUrl}/favorites/${entity}/${key}`))
	},

	// ---------------------------------------------------------------
	// Recent
	// ---------------------------------------------------------------

	async getRecent() {
		return ocsData(await axios.get(`${baseUrl}/recent`)).recent
	},

	async getOrganisations() {
		return ocsData(await axios.get(`${baseUrl}/cases/organisations`)).organisations
	},

	async searchOrganisations(query) {
		return ocsData(await axios.get(`${baseUrl}/organisations`, { params: { q: query } })).organisations
	},

	async getClassificationSubjects() {
		return ocsData(await axios.get(`${baseUrl}/classification-subjects`)).classification_subjects
	},

	async getSensitivities() {
		return ocsData(await axios.get(`${baseUrl}/sensitivities`)).sensitivities
	},

	async getCaseStatuses() {
		return ocsData(await axios.get(`${baseUrl}/case-statuses`)).case_statuses
	},

	async getCaseTypes() {
		return ocsData(await axios.get(`${baseUrl}/case-types`)).case_types
	},

	async getInsightLevels() {
		return ocsData(await axios.get(`${baseUrl}/insight-levels`)).insight_levels
	},

	async getClassificationFacets() {
		return ocsData(await axios.get(`${baseUrl}/classification-facets`)).classification_facets
	},

	// ---------------------------------------------------------------
	// Documents
	// ---------------------------------------------------------------

	async searchDocuments(params = {}) {
		return ocsData(await axios.get(`${baseUrl}/documents`, { params }))
	},

	async getInboundDocuments(params = {}) {
		return ocsData(await axios.get(`${baseUrl}/inbound-documents`, { params }))
	},

	async getDocuments(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/documents`)).documents
	},

	async getDocument(id, { audit = true } = {}) {
		return ocsData(await axios.get(`${baseUrl}/documents/${id}`, { params: { audit } })).document
	},

	async createDocument(caseId, payload) {
		return ocsData(await axios.post(`${baseUrl}/cases/${caseId}/documents`, payload)).document
	},

	async updateDocument(id, payload) {
		return ocsData(await axios.put(`${baseUrl}/documents/${id}`, payload)).document
	},

	async deleteDocument(id) {
		await axios.delete(`${baseUrl}/documents/${id}`)
	},

	async moveDocumentToCase(id, caseNumber) {
		return ocsData(await axios.post(`${baseUrl}/documents/${id}/move-to-case`, { case_number: caseNumber })).document
	},

	async getDocumentStatuses() {
		return ocsData(await axios.get(`${baseUrl}/document-statuses`)).statuses
	},

	async getDocumentCategories() {
		return ocsData(await axios.get(`${baseUrl}/document-categories`)).categories
	},

	async getDocumentTypes() {
		return ocsData(await axios.get(`${baseUrl}/document-types`)).types
	},

	async setDocumentAccess(documentId, targetUser, accessLevel) {
		await axios.put(`${baseUrl}/documents/${documentId}/access/${targetUser}`, {
			access_level: accessLevel,
		})
	},

	// ---------------------------------------------------------------
	// Files
	// ---------------------------------------------------------------

	async copyFilesToDocument(sourceDocumentId, targetDocumentId) {
		return ocsData(await axios.post(`${baseUrl}/documents/${sourceDocumentId}/copy-files`, { target_document_id: targetDocumentId })).files
	},

	async getFilesByDocument(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/files`)).files
	},

	async createFileFromTemplate(documentId, templateId) {
		return ocsData(await axios.post(
			`${baseUrl}/documents/${documentId}/files/from-template`,
			{ template_id: templateId },
		)).file
	},

	async getFilesByCase(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/files`)).files
	},

	async getFile(id) {
		return ocsData(await axios.get(`${baseUrl}/files/${id}`)).file
	},

	async uploadFile(documentId, file, onProgress) {
		const formData = new FormData()
		formData.append('file', file)

		return ocsData(await axios.post(
			`${baseUrl}/documents/${documentId}/files`,
			formData,
			{
				headers: { 'Content-Type': 'multipart/form-data' },
				onUploadProgress: onProgress,
			},
		)).file
	},

	async uploadNewVersion(fileId, file, onProgress) {
		const formData = new FormData()
		formData.append('file', file)

		return ocsData(await axios.post(
			`${baseUrl}/files/${fileId}/version`,
			formData,
			{
				headers: { 'Content-Type': 'multipart/form-data' },
				onUploadProgress: onProgress,
			},
		)).file
	},

	async downloadFile(fileId, filename) {
		const response = await axios.get(`${baseUrl}/files/${fileId}/download`, {
			responseType: 'blob',
		})
		const url = URL.createObjectURL(response.data)
		const link = document.createElement('a')
		link.href = url
		link.download = filename
		document.body.appendChild(link)
		link.click()
		document.body.removeChild(link)
		URL.revokeObjectURL(url)
	},

	async getEditUrl(fileId, action = 'view') {
		return ocsData(await axios.get(`${baseUrl}/files/${fileId}/edit-url`, { params: { action } })).nc_file_id
	},

	async getFileVersions(fileId) {
		return ocsData(await axios.get(`${baseUrl}/files/${fileId}/versions`)).versions
	},

	async downloadFileVersion(fileId, versionId, filename) {
		const response = await axios.get(`${baseUrl}/files/${fileId}/versions/${versionId}/download`, {
			responseType: 'blob',
		})
		const url = URL.createObjectURL(response.data)
		const link = document.createElement('a')
		link.href = url
		link.download = filename
		document.body.appendChild(link)
		link.click()
		document.body.removeChild(link)
		URL.revokeObjectURL(url)
	},

	async getVersionEditUrl(fileId, versionId) {
		return ocsData(await axios.get(`${baseUrl}/files/${fileId}/versions/${versionId}/edit-url`)).nc_file_id
	},

	async restoreFileVersion(fileId, versionId) {
		return ocsData(await axios.post(`${baseUrl}/files/${fileId}/versions/${versionId}/restore`)).file
	},

	async deleteFile(id) {
		await axios.delete(`${baseUrl}/files/${id}`)
	},

	// ---------------------------------------------------------------
	// Document contacts
	// ---------------------------------------------------------------

	async getContactRoles() {
		return ocsData(await axios.get(`${baseUrl}/contact-roles`)).roles
	},

	async getDocumentContacts(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/contacts`)).contacts
	},

	async createDocumentContact(documentId, payload) {
		return ocsData(await axios.post(`${baseUrl}/documents/${documentId}/contacts`, payload)).contact
	},

	async deleteDocumentContact(documentId, contactId) {
		await axios.delete(`${baseUrl}/documents/${documentId}/contacts/${contactId}`)
	},

	// ---------------------------------------------------------------
	// Case participants
	// ---------------------------------------------------------------

	async getParticipantRoles() {
		return ocsData(await axios.get(`${baseUrl}/participant-roles`)).roles
	},

	async getCaseParticipants(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/participants`)).participants
	},

	async createCaseParticipant(caseId, payload) {
		return ocsData(await axios.post(`${baseUrl}/cases/${caseId}/participants`, payload)).participant
	},

	async deleteCaseParticipant(caseId, participantId) {
		await axios.delete(`${baseUrl}/cases/${caseId}/participants/${participantId}`)
	},

	// Company search (Datafordeler)
	// ---------------------------------------------------------------

	async searchCompany(params) {
		return ocsData(await axios.get(`${baseUrl}/company/search`, { params })).companies
	},

	async getCompanyCases(cvr, pnumber = null) {
		const params = pnumber ? { cvr, pnumber } : { cvr }
		return ocsData(await axios.get(`${baseUrl}/company/cases`, { params })).cases
	},

	async getCompanyDocuments(cvr, pnumber = null) {
		const params = pnumber ? { cvr, pnumber } : { cvr }
		return ocsData(await axios.get(`${baseUrl}/company/documents`, { params })).documents
	},

	// Citizen search (Datafordeler)
	// ---------------------------------------------------------------

	async searchCitizen(params) {
		return ocsData(await axios.get(`${baseUrl}/citizen/search`, { params })).citizens
	},

	async getCitizenCases(cpr) {
		return ocsData(await axios.get(`${baseUrl}/citizen/cases`, { params: { cpr } })).cases
	},

	async getCitizenDocuments(cpr) {
		return ocsData(await axios.get(`${baseUrl}/citizen/documents`, { params: { cpr } })).documents
	},

	// ---------------------------------------------------------------
	// Caseworkers
	// ---------------------------------------------------------------

	async searchCaseworkerUsers(query) {
		return ocsData(await axios.get(`${baseUrl}/users/search`, { params: { q: query } })).users
	},

	async searchOrganisationsFull(q) {
		return ocsData(await axios.get(`${baseUrl}/org/search`, { params: { q } })).organisations
	},

	async getOrgMembers(orgUuid) {
		return ocsData(await axios.get(`${baseUrl}/org/${encodeURIComponent(orgUuid)}/members`)).members
	},

	async searchEmployees(params) {
		return ocsData(await axios.get(`${baseUrl}/employees/search`, { params })).employees
	},

	async getOrgTree() {
		return ocsData(await axios.get(`${baseUrl}/employees/org-tree`)).orgs
	},

	async getEmployeeGrants(uuid) {
		return ocsData(await axios.get(`${baseUrl}/employees/${encodeURIComponent(uuid)}/grants`)).grants
	},

	async getEmployeeCases(username) {
		return ocsData(await axios.get(`${baseUrl}/employees/${encodeURIComponent(username)}/cases`)).cases
	},

	async getEmployeePrivileges(uuid) {
		return ocsData(await axios.get(`${baseUrl}/employees/${encodeURIComponent(uuid)}/privileges`)).privileges
	},

	async getCaseworkers(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/caseworkers`)).caseworkers
	},

	async addCaseworker(caseId, userId) {
		return ocsData(await axios.post(`${baseUrl}/cases/${caseId}/caseworkers`, { user_id: userId })).caseworker
	},

	async removeCaseworker(caseId, userId) {
		await axios.delete(`${baseUrl}/cases/${caseId}/caseworkers/${encodeURIComponent(userId)}`)
	},

	// ---------------------------------------------------------------
	// Journal notes
	// ---------------------------------------------------------------

	async getJournalNotes(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/journal-notes`)).journal_notes
	},

	async createJournalNote(caseId, payload) {
		return ocsData(await axios.post(`${baseUrl}/cases/${caseId}/journal-notes`, payload)).journal_note
	},

	async updateJournalNote(id, payload) {
		return ocsData(await axios.put(`${baseUrl}/journal-notes/${id}`, payload)).journal_note
	},

	async deleteJournalNote(id) {
		await axios.delete(`${baseUrl}/journal-notes/${id}`)
	},

	async lockJournalNote(id) {
		return ocsData(await axios.post(`${baseUrl}/journal-notes/${id}/lock`)).journal_note
	},

	// ---------------------------------------------------------------
	// Reminders
	// ---------------------------------------------------------------

	async getMyActiveReminders() {
		return ocsData(await axios.get(`${baseUrl}/me/reminders`)).reminders
	},

	async getCaseReminders(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/reminders`)).reminders
	},

	async createReminder(caseId, payload) {
		return ocsData(await axios.post(`${baseUrl}/cases/${caseId}/reminders`, payload)).reminder
	},

	async getDocumentReminders(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/reminders`)).reminders
	},

	async createDocumentReminder(documentId, payload) {
		return ocsData(await axios.post(`${baseUrl}/documents/${documentId}/reminders`, payload)).reminder
	},

	async updateReminder(id, payload) {
		return ocsData(await axios.put(`${baseUrl}/reminders/${id}`, payload)).reminder
	},

	async deleteReminder(id) {
		await axios.delete(`${baseUrl}/reminders/${id}`)
	},

	// ---------------------------------------------------------------
	// Document notes
	// ---------------------------------------------------------------

	async getDocumentNotes(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/notes`)).document_notes
	},

	async createDocumentNote(documentId, payload) {
		return ocsData(await axios.post(`${baseUrl}/documents/${documentId}/notes`, payload)).document_note
	},

	async updateDocumentNote(id, payload) {
		return ocsData(await axios.put(`${baseUrl}/document-notes/${id}`, payload)).document_note
	},

	async deleteDocumentNote(id) {
		await axios.delete(`${baseUrl}/document-notes/${id}`)
	},

	// ---------------------------------------------------------------
	// Digital Post
	// ---------------------------------------------------------------

	async checkDigitalPost(cprCvr) {
		return ocsData(await axios.get(`${baseUrl}/digital-post/check`, { params: { cpr_cvr: cprCvr } }))
	},

	async createDigitalPostShipment(documentId, payload) {
		return ocsData(await axios.post(`${baseUrl}/documents/${documentId}/digital-post/shipments`, payload))
	},

	async getLatestDigitalPostShipment(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/digital-post/shipments/latest`))
	},

	async downloadDpShipmentFile(id, filename) {
		const response = await axios.get(`${baseUrl}/digital-post/shipment-files/${id}/download`, { responseType: 'blob' })
		const url = URL.createObjectURL(response.data)
		const link = document.createElement('a')
		link.href = url
		link.download = filename
		document.body.appendChild(link)
		link.click()
		document.body.removeChild(link)
		URL.revokeObjectURL(url)
	},

	async downloadDpReceiverFile(id, filename) {
		const response = await axios.get(`${baseUrl}/digital-post/receiver-files/${id}/download`, { responseType: 'blob' })
		const url = URL.createObjectURL(response.data)
		const link = document.createElement('a')
		link.href = url
		link.download = filename
		document.body.appendChild(link)
		link.click()
		document.body.removeChild(link)
		URL.revokeObjectURL(url)
	},

	// Audit log
	// ---------------------------------------------------------------

	async getAuditLog(caseId, params = {}) {
		const data = ocsData(await axios.get(`${baseUrl}/cases/${caseId}/audit-log`, { params }))
		return { entries: data.entries, total: data.total }
	},

	async getDocumentAuditLog(documentId, params = {}) {
		const data = ocsData(await axios.get(`${baseUrl}/documents/${documentId}/audit-log`, { params }))
		return { entries: data.entries, total: data.total }
	},

	async getFileAuditLog(fileId, params = {}) {
		const data = ocsData(await axios.get(`${baseUrl}/files/${fileId}/audit-log`, { params }))
		return { entries: data.entries, total: data.total }
	},

	// ---------------------------------------------------------------
	// Search
	// ---------------------------------------------------------------

	async search(params) {
		return ocsData(await axios.get(`${baseUrl}/search`, { params }))
	},

	// ---------------------------------------------------------------
	// Case access grants
	// ---------------------------------------------------------------

	async getCaseGrants(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/grants`)).grants
	},

	async grantCaseAccess(caseId, userId, canWrite, expiresAt = null) {
		return ocsData(await axios.post(`${baseUrl}/cases/${caseId}/grants`, {
			user_id: userId,
			can_write: canWrite,
			expires_at: expiresAt,
		})).grant
	},

	async revokeCaseAccess(caseId, userId) {
		await axios.delete(`${baseUrl}/cases/${caseId}/grants/${encodeURIComponent(userId)}`)
	},

	async getCaseProfileUsers(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/profile-users`))
	},

	// ---------------------------------------------------------------
	// Current user
	// ---------------------------------------------------------------

	async getMyRoles() {
		return ocsData(await axios.get(`${baseUrl}/me/roles`)).roles
	},

	async getMyPrimaryOrg() {
		return ocsData(await axios.get(`${baseUrl}/me/primary-org`)).org_name
	},

	/**
	 * Search Nextcloud users by display name or username.
	 *
	 * Uses the NC sharees API which is available to all authenticated users
	 * and returns users the current user can share with.
	 *
	 * @param {string} query
	 * @returns {Promise<Array<{id: string, label: string}>>}
	 */
	async searchUsers(query) {
		const shareesUrl = generateOcsUrl('/apps/files_sharing/api/v1/sharees')
		const resp = await axios.get(shareesUrl, {
			params: {
				search: query,
				itemType: 'file',
				'shareType[]': 0, // 0 = users
				limit: 10,
			},
		})
		const data = resp.data?.ocs?.data ?? {}
		const exact = data.exact?.users ?? []
		const rest  = data.users ?? []

		// Exact matches first, deduplicated by uid
		const seen = new Set()
		return [...exact, ...rest]
			.filter(u => {
				const uid = u.value?.shareWith
				if (!uid || seen.has(uid)) return false
				seen.add(uid)
				return true
			})
			.map(u => ({ id: u.value.shareWith, label: u.label }))
	},

	// ---------------------------------------------------------------
	// Config (OpenCase Administrator only)
	// ---------------------------------------------------------------
	// File shares
	// ---------------------------------------------------------------

	async getMySharedFiles() {
		return ocsData(await axios.get(`${baseUrl}/me/shared-files`)).files
	},

	async getFileShares(fileId) {
		return ocsData(await axios.get(`${baseUrl}/files/${fileId}/shares`)).shares
	},

	async createFileShare(fileId, userId, canWrite, expiresAt = null, note = null) {
		return ocsData(await axios.post(`${baseUrl}/files/${fileId}/shares`, {
			user_id:    userId,
			can_write:  canWrite,
			expires_at: expiresAt,
			note,
		})).share
	},

	async revokeFileShare(fileId, userId) {
		await axios.delete(`${baseUrl}/files/${fileId}/shares/${encodeURIComponent(userId)}`)
	},

	async getDocumentShares(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/shares`)).shares
	},

	async createDocumentShare(documentId, userId, canWrite, expiresAt = null, note = null) {
		return ocsData(await axios.post(`${baseUrl}/documents/${documentId}/shares`, {
			user_id:    userId,
			can_write:  canWrite,
			expires_at: expiresAt,
			note,
		})).shares
	},

	async revokeDocumentShare(documentId, userId) {
		await axios.delete(`${baseUrl}/documents/${documentId}/shares/${encodeURIComponent(userId)}`)
	},

	// ---------------------------------------------------------------

	async getConfig() {
		return ocsData(await axios.get(`${baseUrl}/config`)).entries
	},

	async updateConfigValue(key, value) {
		return ocsData(await axios.put(`${baseUrl}/config/${encodeURIComponent(key)}`, { value })).entries
	},

	// ---------------------------------------------------------------
	// Code lists
	// ---------------------------------------------------------------

	async getCodeList(list) {
		return ocsData(await axios.get(`${baseUrl}/codelists/${encodeURIComponent(list)}`)).entries
	},

	async createCodeListEntries(list, rows) {
		return ocsData(await axios.post(`${baseUrl}/codelists/${encodeURIComponent(list)}`, { rows })).entries
	},

	async updateCodeListEntry(list, id, da, en, expired, primaryParticipant) {
		return ocsData(await axios.put(`${baseUrl}/codelists/${encodeURIComponent(list)}/${id}`, { da, en, expired, primary_participant: primaryParticipant })).entry
	},

	// ---------------------------------------------------------------
	// AI chat
	// ---------------------------------------------------------------

	async aiChat(message, history, context) {
		return ocsData(await axios.post(`${baseUrl}/ai/chat`, { message, history, context }))
	},

	// ---------------------------------------------------------------
	// Templates
	// ---------------------------------------------------------------

	async getTemplates() {
		return ocsData(await axios.get(`${baseUrl}/templates`)).templates
	},

	async uploadTemplate(file, name, onProgress) {
		const formData = new FormData()
		formData.append('file', file)
		if (name) {
			formData.append('name', name)
		}
		return ocsData(await axios.post(
			`${baseUrl}/templates`,
			formData,
			{
				headers: { 'Content-Type': 'multipart/form-data' },
				onUploadProgress: onProgress,
			},
		)).template
	},

	async createBlankTemplate(name) {
		return ocsData(await axios.post(`${baseUrl}/templates/new-blank`, { name })).template
	},

	async openTemplateForEdit(id) {
		return ocsData(await axios.post(`${baseUrl}/templates/${id}/open-for-edit`)).nc_file_id
	},

	async deleteTemplate(id) {
		await axios.delete(`${baseUrl}/templates/${id}`)
	},

	async downloadTemplate(id, filename) {
		const response = await axios.get(`${baseUrl}/templates/${id}/download`, {
			responseType: 'blob',
		})
		const url = URL.createObjectURL(response.data)
		const link = document.createElement('a')
		link.href = url
		link.download = filename
		document.body.appendChild(link)
		link.click()
		document.body.removeChild(link)
		URL.revokeObjectURL(url)
	},

	// AI Prompt library
	async getAiPrompts() {
		const { data } = await axios.get(`${baseUrl}/aiprompts`)
		return data.ocs?.data ?? data
	},

	async getRecentAiPrompts(scope) {
		const { data } = await axios.get(`${baseUrl}/aiprompts/recent`, { params: { scope } })
		return data.ocs?.data ?? data
	},

	async getFavoriteAiPrompts(scope) {
		const { data } = await axios.get(`${baseUrl}/aiprompts/favorites`, { params: { scope } })
		return data.ocs?.data ?? data
	},

	async createAiPrompt(payload) {
		const { data } = await axios.post(`${baseUrl}/aiprompts`, payload)
		return data.ocs?.data ?? data
	},

	async updateAiPrompt(id, payload) {
		const { data } = await axios.put(`${baseUrl}/aiprompts/${id}`, payload)
		return data.ocs?.data ?? data
	},

	async deleteAiPrompt(id) {
		await axios.delete(`${baseUrl}/aiprompts/${id}`)
	},

	async touchAiPrompt(id) {
		const { data } = await axios.post(`${baseUrl}/aiprompts/${id}/use`)
		return data.ocs?.data ?? data
	},

	async getAiPromptActions(id) {
		const { data } = await axios.get(`${baseUrl}/aiprompts/${id}/actions`)
		return data.ocs?.data ?? data
	},

	async logAddressProtection(cpr, address) {
		await axios.post(`${baseUrl}/transaction-log/address-protection`, { cpr, address })
	},

	// ---------------------------------------------------------------
	// Workflows
	// ---------------------------------------------------------------

	async createWorkflow(documentId, payload) {
		return ocsData(await axios.post(`${baseUrl}/documents/${documentId}/workflows`, payload)).workflow
	},

	async getMyWorkflowTasks() {
		return ocsData(await axios.get(`${baseUrl}/me/workflow-tasks`)).tasks
	},

	async getWorkflowHistory(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/workflows`)).workflows
	},

	async getActiveWorkflow(documentId) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/workflows/active`)).workflow
	},

	async submitWorkflowAction(workflowId, payload) {
		return ocsData(await axios.post(`${baseUrl}/workflows/${workflowId}/action`, payload)).step
	},

	async cancelWorkflow(workflowId) {
		return ocsData(await axios.delete(`${baseUrl}/workflows/${workflowId}`))
	},

	// ---------------------------------------------------------------
	// Email (send document via the user's Mail app account)
	// ---------------------------------------------------------------

	async getEmailAccountStatus() {
		return ocsData(await axios.get(`${baseUrl}/email/account-status`))
	},

	async searchEmailRecipients(documentId, query) {
		return ocsData(await axios.get(`${baseUrl}/documents/${documentId}/email-recipients`, { params: { q: query } })).recipients
	},

	async sendDocumentEmail(documentId, payload) {
		return ocsData(await axios.post(`${baseUrl}/documents/${documentId}/send-email`, payload))
	},

	// ---------------------------------------------------------------
	// Notifications
	// ---------------------------------------------------------------

	async getMyNotifications() {
		const url = generateOcsUrl('/apps/notifications/api/v2/notifications')
		const resp = await axios.get(url)
		const all = ocsData(resp) ?? []
		return all
			.filter(n => n.app === 'opencase')
			.sort((a, b) => new Date(b.datetime) - new Date(a.datetime))
	},

	// Aktindsigt (right of access)
	// ---------------------------------------------------------------

	async getAccessRequests(caseId) {
		return ocsData(await axios.get(`${baseUrl}/cases/${caseId}/access-requests`))
	},

	async createAccessRequest(caseId, payload) {
		return ocsData(await axios.post(`${baseUrl}/cases/${caseId}/access-requests`, payload)).access_request
	},

	async getAccessRequest(id) {
		return ocsData(await axios.get(`${baseUrl}/access-requests/${id}`))
	},

	async updateAccessRequest(id, payload) {
		return ocsData(await axios.patch(`${baseUrl}/access-requests/${id}`, payload)).access_request
	},

	async changeAccessRequestStatus(id, status) {
		return ocsData(await axios.put(`${baseUrl}/access-requests/${id}/status`, { status })).access_request
	},

	async searchAccessCandidates(requestId, params = {}) {
		return ocsData(await axios.get(`${baseUrl}/access-requests/${requestId}/search`, { params }))
	},

	async addAccessItem(requestId, payload) {
		return ocsData(await axios.post(`${baseUrl}/access-requests/${requestId}/items`, payload)).item
	},

	async updateAccessItem(itemId, payload) {
		return ocsData(await axios.patch(`${baseUrl}/access-request-items/${itemId}`, payload)).item
	},

	async removeAccessItem(itemId) {
		return ocsData(await axios.delete(`${baseUrl}/access-request-items/${itemId}`))
	},

	async addAccessRedaction(itemId, payload) {
		return ocsData(await axios.post(`${baseUrl}/access-request-items/${itemId}/redactions`, payload)).redaction
	},

	async removeAccessRedaction(redactionId) {
		return ocsData(await axios.delete(`${baseUrl}/access-redactions/${redactionId}`))
	},

	async saveAccessDecision(requestId, payload) {
		return ocsData(await axios.post(`${baseUrl}/access-requests/${requestId}/decision`, payload)).decision
	},

	async approveAccessDecision(requestId) {
		return ocsData(await axios.post(`${baseUrl}/access-requests/${requestId}/approve`)).decision
	},

	async getAccessDecisionTemplates() {
		return ocsData(await axios.get(`${baseUrl}/access-requests/decision-templates`))
	},

	async getAccessExclusionReasons() {
		return ocsData(await axios.get(`${baseUrl}/access-requests/exclusion-reasons`))
	},

	async getAccessMaskingFiles(requestId) {
		return ocsData(await axios.get(`${baseUrl}/access-requests/${requestId}/masking-files`)).files
	},

	async uploadAccessMasked(itemId, fileId, file) {
		const form = new FormData()
		form.append('file', file)
		return ocsData(await axios.post(`${baseUrl}/access-request-items/${itemId}/files/${fileId}/masked`, form))
	},

	async downloadAccessMasked(itemId, fileId, filename) {
		const response = await axios.get(
			`${baseUrl}/access-request-items/${itemId}/files/${fileId}/masked/download`,
			{ responseType: 'blob' },
		)
		const url  = URL.createObjectURL(response.data)
		const link = document.createElement('a')
		link.href = url
		link.download = 'maskeret_' + filename
		link.click()
		URL.revokeObjectURL(url)
	},

	getAccessExportUrl(requestId) {
		return `${baseUrl}/access-requests/${requestId}/export`
	},

	async markAccessRequestSent(requestId) {
		return ocsData(await axios.post(`${baseUrl}/access-requests/${requestId}/mark-sent`)).access_request
	},
}
