import { createStore } from 'vuex'
import { loadState } from '@nextcloud/initial-state'
import api from '../services/api.js'

export default createStore({
	state: {
		// Search pagination settings (from server config)
		searchPageSize: loadState('opencase', 'search_page_size', 50),
		searchMaxResultCount: loadState('opencase', 'search_max_result_count', 500),
		// Whether Enterprise features (Datafordeler access) are enabled on this instance
		enterpriseVersionEnabled: loadState('opencase', 'enterprise_version_enabled', false),
		// Enterprise Digital Post feature (from server config: enterprise_version)
		digitalPostEnabled: loadState('opencase', 'digital_post_enabled', false),
		// Enterprise Datafordeler CPR (citizen) search (from server config: enterprise_version)
		citizenSearchEnabled: loadState('opencase', 'citizen_search_enabled', false),
		// Enterprise Datafordeler CVR (company) search (from server config: enterprise_version)
		companySearchEnabled: loadState('opencase', 'company_search_enabled', false),
		// Whether the Mail app is enabled for the current user (for "Send with email")
		mailAppEnabled: loadState('opencase', 'mail_app_enabled', false),
		// Current user's OpenCase roles
		isAdministrator: false,
		isTemplateDesigner: false,
		// Organisation list derived from stats
		organisations: [],
		// Classification subjects (KLE emneord)
		classificationSubjects: [],
		// Sensitivity levels
		sensitivities: [],
		// Case statuses (localized)
		caseStatuses: [],
		// Case types (localized)
		caseTypes: [],
		// Insight levels (localized)
		insightLevels: [],
		// Classification facets (KLE handlingsfacetter)
		classificationFacets: [],
		// Document statuses (localized)
		documentStatuses: [],
		// Case stats: { org: { year: count } }
		stats: {},
		// Current case list
		cases: [],
		casesTotal: 0,
		casesLoading: false,
		// Current case detail
		currentCase: null,
		// Documents for current case
		documents: [],
		documentsLoading: false,
		// Files for current case
		files: [],
		filesLoading: false,
		// Current document detail
		currentDocument: null,
		// Audit log for current case
		auditLog: [],
		auditLogLoading: false,
		// Search state
		searchCases: [],
		searchDocuments: [],
		searchFiles: [],
		searchResults: [],
		searchTotal: 0,
		searchAggregations: {},
		searchLoading: false,
		// Favorites: array of { entity, key }
		favorites: [],
		// Active filters
		filters: {
			organisation: null,
			year: null,
			status_id: null,
			search: null,
		},
	},

	mutations: {
		// The initial values come from loadState() (server-rendered at page
		// load) — these mutations let ConfigurationView.vue apply a changed
		// search_page_size/search_max_result_count immediately, without
		// requiring a full page reload to pick up the new config value.
		SET_SEARCH_PAGE_SIZE(state, value) {
			state.searchPageSize = value
		},

		SET_SEARCH_MAX_RESULT_COUNT(state, value) {
			state.searchMaxResultCount = value
		},

		SET_IS_ADMINISTRATOR(state, value) {
			state.isAdministrator = value
		},

		SET_IS_TEMPLATE_DESIGNER(state, value) {
			state.isTemplateDesigner = value
		},

		SET_ORGANISATIONS(state, organisations) {
			state.organisations = organisations
		},

		SET_CLASSIFICATION_SUBJECTS(state, subjects) {
			state.classificationSubjects = subjects
		},

		SET_SENSITIVITIES(state, sensitivities) {
			state.sensitivities = sensitivities
		},

		SET_CASE_STATUSES(state, statuses) {
			state.caseStatuses = statuses
		},

		SET_CASE_TYPES(state, types) {
			state.caseTypes = types
		},

		SET_INSIGHT_LEVELS(state, levels) {
			state.insightLevels = levels
		},

		SET_CLASSIFICATION_FACETS(state, facets) {
			state.classificationFacets = facets
		},

		SET_DOCUMENT_STATUSES(state, statuses) {
			state.documentStatuses = statuses
		},

		SET_STATS(state, stats) {
			state.stats = stats
		},

		SET_CASES(state, { cases, total }) {
			state.cases = cases
			state.casesTotal = total
		},

		SET_CASES_LOADING(state, loading) {
			state.casesLoading = loading
		},

		SET_CURRENT_CASE(state, caseEntity) {
			state.currentCase = caseEntity
		},

		SET_DOCUMENTS(state, documents) {
			state.documents = documents
		},

		SET_DOCUMENTS_LOADING(state, loading) {
			state.documentsLoading = loading
		},

		SET_FILES(state, files) {
			state.files = files
		},

		SET_FILES_LOADING(state, loading) {
			state.filesLoading = loading
		},

		SET_CURRENT_DOCUMENT(state, document) {
			state.currentDocument = document
		},

		SET_AUDIT_LOG(state, entries) {
			state.auditLog = entries
		},

		SET_AUDIT_LOG_LOADING(state, loading) {
			state.auditLogLoading = loading
		},

		SET_SEARCH_RESULTS(state, { cases, documents, files, hits, total, aggregations }) {
			state.searchCases = cases || []
			state.searchDocuments = documents || []
			state.searchFiles = files || []
			state.searchResults = files || hits || []
			state.searchTotal = total
			state.searchAggregations = aggregations
		},

		SET_SEARCH_LOADING(state, loading) {
			state.searchLoading = loading
		},

		SET_FILTERS(state, filters) {
			state.filters = { ...state.filters, ...filters }
		},

		SET_FAVORITES(state, favorites) {
			state.favorites = favorites
		},

		ADD_FAVORITE(state, { entity, key }) {
			if (!state.favorites.some(f => f.entity === entity && f.key === key)) {
				state.favorites.unshift({ entity, key, added_at: new Date().toISOString() })
			}
		},

		REMOVE_FAVORITE(state, { entity, key }) {
			state.favorites = state.favorites.filter(f => !(f.entity === entity && f.key === key))
		},

		ADD_DOCUMENT(state, document) {
			state.documents.push(document)
		},

		REMOVE_DOCUMENT(state, documentId) {
			state.documents = state.documents.filter(d => d.id !== documentId)
		},

		ADD_FILE(state, file) {
			state.files.push(file)
		},

		REMOVE_FILE(state, fileId) {
			state.files = state.files.filter(f => f.id !== fileId)
		},

		UPDATE_FILE(state, updatedFile) {
			const idx = state.files.findIndex(f => f.id === updatedFile.id)
			if (idx >= 0) {
				state.files[idx] = updatedFile
			}
		},
	},

	actions: {
		async fetchMyRoles({ commit }) {
			const roles = await api.getMyRoles()
			commit('SET_IS_ADMINISTRATOR', roles.includes('Administrator'))
			commit('SET_IS_TEMPLATE_DESIGNER', roles.includes('Template Designer'))
		},

		async fetchOrganisations({ commit }) {
			const orgs = await api.getOrganisations()
			commit('SET_ORGANISATIONS', orgs)
		},

		async fetchClassificationSubjects({ commit }) {
			const subjects = await api.getClassificationSubjects()
			commit('SET_CLASSIFICATION_SUBJECTS', subjects)
		},

		async fetchSensitivities({ commit }) {
			const sensitivities = await api.getSensitivities()
			commit('SET_SENSITIVITIES', sensitivities)
		},

		async fetchCaseStatuses({ commit }) {
			const statuses = await api.getCaseStatuses()
			commit('SET_CASE_STATUSES', statuses)
		},

		async fetchCaseTypes({ commit }) {
			const types = await api.getCaseTypes()
			commit('SET_CASE_TYPES', types)
		},

		async fetchInsightLevels({ commit }) {
			const levels = await api.getInsightLevels()
			commit('SET_INSIGHT_LEVELS', levels)
		},

		async fetchClassificationFacets({ commit }) {
			const facets = await api.getClassificationFacets()
			commit('SET_CLASSIFICATION_FACETS', facets)
		},

		async fetchDocumentStatuses({ commit }) {
			const statuses = await api.getDocumentStatuses()
			commit('SET_DOCUMENT_STATUSES', statuses)
		},

		async fetchStats({ commit }) {
			const stats = await api.getCaseStats()
			commit('SET_STATS', stats)
			commit('SET_ORGANISATIONS', Object.keys(stats).sort())
		},

		async fetchCases({ commit, state }, { limit = state.searchPageSize, offset = 0 } = {}) {
			commit('SET_CASES_LOADING', true)
			try {
				const params = { limit, offset }
				if (state.filters.organisation) params.organisation = state.filters.organisation
				if (state.filters.year) params.year = state.filters.year
				if (state.filters.status_id) params.status_id = state.filters.status_id
				if (state.filters.search) params.search = state.filters.search

				const result = await api.getCases(params)
				commit('SET_CASES', result)
			} finally {
				commit('SET_CASES_LOADING', false)
			}
		},

		async fetchCase({ commit }, caseId) {
			const caseEntity = await api.getCase(caseId)
			commit('SET_CURRENT_CASE', caseEntity)
			return caseEntity
		},

		async createCase({ dispatch }, payload) {
			const caseEntity = await api.createCase(payload)
			await dispatch('fetchStats')
			return caseEntity
		},

		async updateCase({ commit }, { id, payload }) {
			const updated = await api.updateCase(id, payload)
			commit('SET_CURRENT_CASE', updated)
			return updated
		},

		async changeCaseStatus({ commit }, { id, statusId }) {
			const updated = await api.changeCaseStatus(id, statusId)
			commit('SET_CURRENT_CASE', updated)
			return updated
		},

		async fetchDocuments({ commit }, caseId) {
			commit('SET_DOCUMENTS_LOADING', true)
			try {
				const documents = await api.getDocuments(caseId)
				commit('SET_DOCUMENTS', documents)
			} finally {
				commit('SET_DOCUMENTS_LOADING', false)
			}
		},

		async createDocument({ commit }, { caseId, payload }) {
			const document = await api.createDocument(caseId, payload)
			commit('ADD_DOCUMENT', document)
			return document
		},

		async deleteDocument({ commit }, documentId) {
			await api.deleteDocument(documentId)
			commit('REMOVE_DOCUMENT', documentId)
		},

		async fetchFiles({ commit }, caseId) {
			commit('SET_FILES_LOADING', true)
			try {
				const files = await api.getFilesByCase(caseId)
				commit('SET_FILES', files)
			} finally {
				commit('SET_FILES_LOADING', false)
			}
		},

		async uploadFile({ commit }, { documentId, file, onProgress }) {
			const fileEntity = await api.uploadFile(documentId, file, onProgress)
			commit('ADD_FILE', fileEntity)
			return fileEntity
		},

		async uploadNewVersion({ commit }, { fileId, file, onProgress }) {
			const fileEntity = await api.uploadNewVersion(fileId, file, onProgress)
			commit('UPDATE_FILE', fileEntity)
			return fileEntity
		},

		async deleteFile({ commit }, fileId) {
			await api.deleteFile(fileId)
			commit('REMOVE_FILE', fileId)
		},

		async fetchAuditLog({ commit }, caseId) {
			commit('SET_AUDIT_LOG_LOADING', true)
			try {
				const { entries } = await api.getAuditLog(caseId)
				commit('SET_AUDIT_LOG', entries)
			} finally {
				commit('SET_AUDIT_LOG_LOADING', false)
			}
		},

		async search({ commit }, { query, filters = {}, limit = 20, offset = 0 }) {
			commit('SET_SEARCH_LOADING', true)
			try {
				const params = { q: query, limit, offset, ...filters }
				const result = await api.search(params)
				commit('SET_SEARCH_RESULTS', result)
				return result
			} finally {
				commit('SET_SEARCH_LOADING', false)
			}
		},

		setFilters({ commit, dispatch, state }, filters) {
			commit('SET_FILTERS', filters)
			dispatch('fetchCases', { limit: state.searchPageSize, offset: 0 })
		},

		async fetchFavorites({ commit }) {
			const favorites = await api.getFavorites()
			commit('SET_FAVORITES', favorites.map(f => ({ entity: f.entity, key: f.key, added_at: f.added_at })))
		},

		async addFavorite({ commit }, { entity, key }) {
			await api.addFavorite(entity, key)
			commit('ADD_FAVORITE', { entity, key })
		},

		async removeFavorite({ commit }, { entity, key }) {
			await api.removeFavorite(entity, key)
			commit('REMOVE_FAVORITE', { entity, key })
		},
	},
})
