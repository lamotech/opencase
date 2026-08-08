import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

import HomeView from '../views/HomeView.vue'
import CaseListView from '../views/CaseListView.vue'
import MyCasesView from '../views/MyCasesView.vue'
import TemplateManagerView from '../views/TemplateManagerView.vue'
import TemplateFieldsView from '../views/TemplateFieldsView.vue'
import CaseDetailView from '../views/CaseDetailView.vue'
import CaseCreateView from '../views/CaseCreateView.vue'
import DocumentDetailView from '../views/DocumentDetailView.vue'
import SearchView from '../views/SearchView.vue'
import CaseFieldSearchView from '../views/CaseFieldSearchView.vue'
import DocumentFieldSearchView from '../views/DocumentFieldSearchView.vue'
import CitizenSearchView from '../views/CitizenSearchView.vue'
import CompanySearchView from '../views/CompanySearchView.vue'
import EmployeeSearchView from '../views/EmployeeSearchView.vue'
import OrganisationSearchView from '../views/OrganisationSearchView.vue'
import RecentView from '../views/RecentView.vue'
import SharedWithMeView from '../views/SharedWithMeView.vue'
import InboundDocumentsView from '../views/InboundDocumentsView.vue'
import FavoritesView from '../views/FavoritesView.vue'
import MyDocumentsView from '../views/MyDocumentsView.vue'
import ConfigurationView from '../views/ConfigurationView.vue'
import AccessRequestDetailView from '../views/AccessRequestDetailView.vue'

export default createRouter({
	history: createWebHistory(generateUrl('/apps/opencase')),
	routes: [
		{
			path: '/',
			name: 'home',
			component: HomeView,
		},
		{
			path: '/cases',
			name: 'cases',
			component: CaseListView,
		},
		{
			path: '/my-cases',
			name: 'my-cases',
			component: MyCasesView,
		},
		{
			path: '/my-documents',
			name: 'my-documents',
			component: MyDocumentsView,
		},
		{
			path: '/case/new',
			name: 'case-create',
			component: CaseCreateView,
		},
		{
			path: '/case/:id',
			name: 'case-detail',
			component: CaseDetailView,
			props: true,
		},
		{
			path: '/document/:id',
			name: 'document-detail',
			component: DocumentDetailView,
			props: true,
		},
		{
			path: '/search',
			name: 'search',
			component: SearchView,
		},
		{
			path: '/search/cases',
			name: 'search-cases',
			component: CaseFieldSearchView,
		},
		{
			path: '/search/documents',
			name: 'search-documents',
			component: DocumentFieldSearchView,
		},
		{
			path: '/search/citizen',
			name: 'search-citizen',
			component: CitizenSearchView,
		},
		{
			path: '/search/company',
			name: 'search-company',
			component: CompanySearchView,
		},
		{
			path: '/search/employees',
			name: 'search-employees',
			component: EmployeeSearchView,
		},
		{
			path: '/search/organisations',
			name: 'search-organisations',
			component: OrganisationSearchView,
		},
		{
			path: '/recent',
			name: 'recent',
			component: RecentView,
			props: () => ({ entity: null }),
		},
		{
			path: '/recent/cases',
			name: 'recent-cases',
			component: RecentView,
			props: () => ({ entity: 'case' }),
		},
		{
			path: '/recent/documents',
			name: 'recent-documents',
			component: RecentView,
			props: () => ({ entity: 'document' }),
		},
		{
			path: '/shared',
			name: 'shared',
			component: SharedWithMeView,
		},
		{
			path: '/inbound',
			name: 'inbound',
			component: InboundDocumentsView,
		},
		{
			path: '/favorites',
			name: 'favorites',
			component: FavoritesView,
			props: () => ({ entity: null }),
		},
		{
			path: '/favorites/cases',
			name: 'favorites-cases',
			component: FavoritesView,
			props: () => ({ entity: 'case' }),
		},
		{
			path: '/favorites/documents',
			name: 'favorites-documents',
			component: FavoritesView,
			props: () => ({ entity: 'document' }),
		},
		{
			path: '/configuration',
			name: 'configuration',
			component: ConfigurationView,
		},
		{
			path: '/templates',
			name: 'templates',
			component: TemplateManagerView,
		},
		{
			path: '/templates/fields',
			name: 'template-fields',
			component: TemplateFieldsView,
		},
		{
			path: '/access-request/:id',
			name: 'access-request-detail',
			component: AccessRequestDetailView,
			props: true,
		},
	],
})
