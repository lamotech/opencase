<?php

declare(strict_types=1);

/**
 * OpenCase API routes.
 *
 * All API routes are prefixed with /apps/opencase/api/v1/
 * by Nextcloud's routing system.
 *
 * Authentication: All routes require a valid Nextcloud session or
 * API token. Admin routes additionally require admin privileges.
 */
return [
    'routes' => [
        // Main SPA entry point
        ['name' => 'page#index',     'url' => '/',       'verb' => 'GET'],

        // ---------------------------------------------------------
        // Public API — authenticated by mTLS via PublicApiMiddleware
        // nginx must terminate TLS and forward X-SSL-Client-* headers
        // ---------------------------------------------------------
        ['name' => 'public_message_receiver#receive',              'url' => '/public/v1/messages',                  'verb' => 'POST'],
        ['name' => 'public_digital_post_receiver#receive',        'url' => '/public/v1/digital-post/messages',        'verb' => 'POST'],
        ['name' => 'public_distribution_receiver#receive',        'url' => '/public/v1/distribution/messages',        'verb' => 'POST'],

        // ---------------------------------------------------------
        // Public data API — authenticated by bearer SAML assertion token
        // via PublicDataApiMiddleware (see opencase_api_client.valid_for = 'API')
        // ---------------------------------------------------------
        ['name' => 'public_case_api#show',          'url' => '/public/v1/api/cases',               'verb' => 'GET'],
        ['name' => 'public_case_api#search',        'url' => '/public/v1/api/cases/search',        'verb' => 'GET'],
        ['name' => 'public_case_api#create',        'url' => '/public/v1/api/cases',               'verb' => 'POST'],
        ['name' => 'public_case_api#update',        'url' => '/public/v1/api/cases',               'verb' => 'PUT'],
        ['name' => 'public_case_api#documents',     'url' => '/public/v1/api/cases/documents',     'verb' => 'GET'],
        ['name' => 'public_case_api#create_document', 'url' => '/public/v1/api/cases/documents',   'verb' => 'POST'],
        ['name' => 'public_case_api#journal_notes', 'url' => '/public/v1/api/cases/journal-notes', 'verb' => 'GET'],
        ['name' => 'public_case_api#add_participants', 'url' => '/public/v1/api/cases/participants', 'verb' => 'POST'],
        ['name' => 'public_case_api#add_caseworkers', 'url' => '/public/v1/api/cases/caseworkers', 'verb' => 'POST'],
        ['name' => 'public_case_api#add_journal_note', 'url' => '/public/v1/api/cases/journal-notes', 'verb' => 'POST'],
        ['name' => 'public_document_api#show',      'url' => '/public/v1/api/documents',           'verb' => 'GET'],
        ['name' => 'public_document_api#search',    'url' => '/public/v1/api/documents/search',    'verb' => 'GET'],
        ['name' => 'public_document_api#update',    'url' => '/public/v1/api/documents',           'verb' => 'PUT'],
        ['name' => 'public_document_api#add_contacts', 'url' => '/public/v1/api/documents/contacts', 'verb' => 'POST'],
        ['name' => 'public_document_api#upload_file', 'url' => '/public/v1/api/documents/files', 'verb' => 'POST'],
        ['name' => 'public_document_api#create_file_from_template', 'url' => '/public/v1/api/documents/files/from-template', 'verb' => 'POST'],
        ['name' => 'public_document_api#add_note', 'url' => '/public/v1/api/documents/notes', 'verb' => 'POST'],
        ['name' => 'public_file_api#show', 'url' => '/public/v1/api/files', 'verb' => 'GET'],
        ['name' => 'public_file_api#upload_version', 'url' => '/public/v1/api/files/version', 'verb' => 'POST'],
        ['name' => 'public_file_api#versions', 'url' => '/public/v1/api/files/versions', 'verb' => 'GET'],
        ['name' => 'public_file_api#version_content', 'url' => '/public/v1/api/files/versions/content', 'verb' => 'GET'],
        ['name' => 'public_search_api#search', 'url' => '/public/v1/api/search', 'verb' => 'GET'],

        // Public reference-data API — code lists and other lookup values
        ['name' => 'public_reference_api#case_statuses',         'url' => '/public/v1/api/casestatus',            'verb' => 'GET'],
        ['name' => 'public_reference_api#case_types',             'url' => '/public/v1/api/casetype',              'verb' => 'GET'],
        ['name' => 'public_reference_api#contact_roles',          'url' => '/public/v1/api/contactroles',          'verb' => 'GET'],
        ['name' => 'public_reference_api#document_categories',    'url' => '/public/v1/api/documentcategory',      'verb' => 'GET'],
        ['name' => 'public_reference_api#document_statuses',      'url' => '/public/v1/api/documentstatus',        'verb' => 'GET'],
        ['name' => 'public_reference_api#insight_levels',         'url' => '/public/v1/api/insightlevel',          'verb' => 'GET'],
        ['name' => 'public_reference_api#participant_roles',      'url' => '/public/v1/api/participantroles',      'verb' => 'GET'],
        ['name' => 'public_reference_api#organisations',          'url' => '/public/v1/api/organisations',         'verb' => 'GET'],
        ['name' => 'public_reference_api#kle_numbers',             'url' => '/public/v1/api/kle-numbers',           'verb' => 'GET'],
        ['name' => 'public_reference_api#classification_facets',  'url' => '/public/v1/api/classification-facets', 'verb' => 'GET'],
        ['name' => 'public_reference_api#sensitivities',           'url' => '/public/v1/api/sensitivities',         'verb' => 'GET'],
        ['name' => 'public_reference_api#users',                  'url' => '/public/v1/api/users',                 'verb' => 'GET'],
        ['name' => 'public_reference_api#templates',               'url' => '/public/v1/api/templates',             'verb' => 'GET'],

        // Login Flow v2 helper page for 3rd-party clients (e.g. Outlook add-in)
        ['name' => 'auth#login_helper', 'url' => '/login-helper', 'verb' => 'GET'],

        // SAML endpoints
        ['name' => 'saml#login',     'url' => '/saml/login',     'verb' => 'GET'],
        ['name' => 'saml#logout',    'url' => '/saml/logout',    'verb' => 'GET'],
        ['name' => 'saml#loggedout', 'url' => '/saml/loggedout', 'verb' => 'GET'],
        ['name' => 'saml#noaccess',  'url' => '/saml/noaccess',  'verb' => 'GET'],
        ['name' => 'saml#acs',       'url' => '/saml/acs',       'verb' => 'POST'],
        ['name' => 'saml#sls',       'url' => '/saml/sls',       'verb' => 'GET'],
        ['name' => 'saml#sls_post',  'url' => '/saml/sls',       'verb' => 'POST'],

        // Admin settings page endpoints (non-OCS, called from the NC admin settings Vue)
        ['name' => 'settings#save_config',              'url' => '/settings/config',               'verb' => 'POST'],
        ['name' => 'settings#sync_organisations',       'url' => '/settings/sync-organisations',        'verb' => 'POST'],
        ['name' => 'settings#org_sync_status',          'url' => '/settings/sync-organisations/status', 'verb' => 'GET'],
        ['name' => 'settings#sync_klassifikation',      'url' => '/settings/sync-klassifikation',  'verb' => 'POST'],
        ['name' => 'settings#upload_classification_subjects', 'url' => '/settings/classification/subjects/upload', 'verb' => 'POST'],
        ['name' => 'settings#upload_classification_facets',   'url' => '/settings/classification/facets/upload',   'verb' => 'POST'],
        ['name' => 'settings#validate_certificate',     'url' => '/settings/certificate/validate', 'verb' => 'POST'],
        ['name' => 'settings#download_metadata',        'url' => '/settings/metadata',             'verb' => 'POST'],
        ['name' => 'settings#download_msoffice_manifest', 'url' => '/settings/msoffice/{app}/manifest.xml', 'verb' => 'GET'],
        ['name' => 'settings#download_export_schema',    'url' => '/settings/export/schema.xsd',    'verb' => 'GET'],
        ['name' => 'settings#run_export',                'url' => '/settings/export/run',           'verb' => 'POST'],
        ['name' => 'settings#search_transaction_log',   'url' => '/settings/transaction-log',      'verb' => 'POST'],
        ['name' => 'settings#transaction_log_types',    'url' => '/settings/transaction-log/types', 'verb' => 'GET'],
        ['name' => 'settings#set_api_client_active',    'url' => '/settings/api-clients/{id}/active',  'verb' => 'POST'],
        ['name' => 'settings#set_api_client_expires',   'url' => '/settings/api-clients/{id}/expires', 'verb' => 'POST'],
        ['name' => 'settings#delete_api_client',        'url' => '/settings/api-clients/{id}/delete',  'verb' => 'POST'],
        ['name' => 'settings#search_local_users',       'url' => '/settings/local-users/search',   'verb' => 'GET'],
        ['name' => 'settings#get_local_user_info',      'url' => '/settings/local-users/{userId}', 'verb' => 'GET'],
        ['name' => 'settings#save_local_user_info',     'url' => '/settings/local-users/{userId}', 'verb' => 'POST'],
        ['name' => 'settings#get_local_user_privileges',    'url' => '/settings/local-users/{userId}/privileges',      'verb' => 'GET'],
        ['name' => 'settings#add_local_user_privilege',     'url' => '/settings/local-users/{userId}/privileges',      'verb' => 'POST'],
        ['name' => 'settings#delete_local_user_privilege',  'url' => '/settings/local-users/{userId}/privileges/{id}', 'verb' => 'DELETE'],

        // Catch-all for client-side routing — must be last
        ['name' => 'page#catch_all', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],

    ],    
    'ocs' => [
        // ---------------------------------------------------------
        // CORS preflight (OPTIONS) for endpoints exposed to 3rd-party
        // clients (e.g. the Outlook task pane add-in) via #[CORS]
        // ---------------------------------------------------------
        ['name' => 'case#preflighted_cors',             'url' => '/api/v1/cases',                            'verb' => 'OPTIONS', 'postfix' => '_list'],
        ['name' => 'case#preflighted_cors',             'url' => '/api/v1/cases/{id}',                       'verb' => 'OPTIONS', 'postfix' => '_show'],
        ['name' => 'document#preflighted_cors',         'url' => '/api/v1/document-categories',              'verb' => 'OPTIONS', 'postfix' => '_categories'],
        ['name' => 'document#preflighted_cors',         'url' => '/api/v1/cases/{caseId}/documents',         'verb' => 'OPTIONS', 'postfix' => '_create'],
        ['name' => 'file#preflighted_cors',             'url' => '/api/v1/documents/{docId}/files',          'verb' => 'OPTIONS', 'postfix' => '_upload'],
        ['name' => 'document_contact#preflighted_cors', 'url' => '/api/v1/contact-roles',                    'verb' => 'OPTIONS', 'postfix' => '_roles'],
        ['name' => 'document_contact#preflighted_cors', 'url' => '/api/v1/documents/{documentId}/contacts',  'verb' => 'OPTIONS', 'postfix' => '_create'],
        ['name' => 'me#preflighted_cors',               'url' => '/api/v1/me/roles',                         'verb' => 'OPTIONS', 'postfix' => '_roles'],

        // ---------------------------------------------------------
        // Dashboard widget
        // ---------------------------------------------------------
        ['name' => 'widget#my_cases',  'url' => '/api/v1/widget/my-cases',   'verb' => 'GET'],
        ['name' => 'widget#favorites', 'url' => '/api/v1/widget/favorites',  'verb' => 'GET'],
        ['name' => 'widget#recent',    'url' => '/api/v1/widget/recent',     'verb' => 'GET'],

        // ---------------------------------------------------------
        // Cases
        // ---------------------------------------------------------
        ['name' => 'case#index',         'url' => '/api/v1/cases',               'verb' => 'GET'],
        ['name' => 'case#create',        'url' => '/api/v1/cases',               'verb' => 'POST'],
        ['name' => 'case#stats',                   'url' => '/api/v1/cases/stats',             'verb' => 'GET'],
        ['name' => 'case#organisations',           'url' => '/api/v1/cases/organisations',     'verb' => 'GET'],
        ['name' => 'case#search_organisations',    'url' => '/api/v1/organisations',            'verb' => 'GET'],
        ['name' => 'case#classification_subjects', 'url' => '/api/v1/classification-subjects', 'verb' => 'GET'],
        ['name' => 'case#classification_facets',  'url' => '/api/v1/classification-facets',  'verb' => 'GET'],
        ['name' => 'case#sensitivities',           'url' => '/api/v1/sensitivities',            'verb' => 'GET'],
        ['name' => 'case#case_statuses',           'url' => '/api/v1/case-statuses',            'verb' => 'GET'],
        ['name' => 'case#case_types',               'url' => '/api/v1/case-types',                'verb' => 'GET'],
        ['name' => 'case#insight_levels',          'url' => '/api/v1/insight-levels',           'verb' => 'GET'],
        ['name' => 'case#show',          'url' => '/api/v1/cases/{id}',               'verb' => 'GET'],
        ['name' => 'case#update',        'url' => '/api/v1/cases/{id}',               'verb' => 'PUT'],
        ['name' => 'case#change_status', 'url' => '/api/v1/cases/{id}/status',        'verb' => 'PUT'],
        ['name' => 'case#hierarchy',     'url' => '/api/v1/cases/{id}/hierarchy',     'verb' => 'GET'],

        // ---------------------------------------------------------
        // Documents (nested under cases for creation/listing)
        // ---------------------------------------------------------
        ['name' => 'document#index',     'url' => '/api/v1/cases/{caseId}/documents',  'verb' => 'GET'],
        ['name' => 'document#create',    'url' => '/api/v1/cases/{caseId}/documents',  'verb' => 'POST'],
        ['name' => 'document#show',      'url' => '/api/v1/documents/{id}',            'verb' => 'GET'],
        ['name' => 'document#update',    'url' => '/api/v1/documents/{id}',            'verb' => 'PUT'],
        ['name' => 'document#destroy',   'url' => '/api/v1/documents/{id}',            'verb' => 'DELETE'],
        ['name' => 'document#set_access', 'url' => '/api/v1/documents/{id}/access/{targetUser}', 'verb' => 'PUT'],

        // ---------------------------------------------------------
        // Files
        // ---------------------------------------------------------
        ['name' => 'file#index_by_document',  'url' => '/api/v1/documents/{docId}/files',               'verb' => 'GET'],
        ['name' => 'file#upload',             'url' => '/api/v1/documents/{docId}/files',               'verb' => 'POST'],
        ['name' => 'file#create_from_template','url' => '/api/v1/documents/{docId}/files/from-template','verb' => 'POST'],
        ['name' => 'file#index_by_case',     'url' => '/api/v1/cases/{caseId}/files',     'verb' => 'GET'],
        ['name' => 'file#show',              'url' => '/api/v1/files/{id}',               'verb' => 'GET'],
        ['name' => 'file#download',          'url' => '/api/v1/files/{id}/download',      'verb' => 'GET'],
        ['name' => 'file#get_edit_url',      'url' => '/api/v1/files/{id}/edit-url',      'verb' => 'GET'],
        ['name' => 'file#upload_new_version', 'url' => '/api/v1/files/{id}/version',      'verb' => 'POST'],
        ['name' => 'file#destroy',           'url' => '/api/v1/files/{id}',               'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // File shares
        // ---------------------------------------------------------
        ['name' => 'file_share#shared_with_me',        'url' => '/api/v1/me/shared-files',                         'verb' => 'GET'],
        ['name' => 'file_share#index',                 'url' => '/api/v1/files/{fileId}/shares',                   'verb' => 'GET'],
        ['name' => 'file_share#create',                'url' => '/api/v1/files/{fileId}/shares',                   'verb' => 'POST'],
        ['name' => 'file_share#destroy',               'url' => '/api/v1/files/{fileId}/shares/{userId}',          'verb' => 'DELETE'],
        ['name' => 'file_share#document_shares',       'url' => '/api/v1/documents/{documentId}/shares',           'verb' => 'GET'],
        ['name' => 'file_share#create_document_share', 'url' => '/api/v1/documents/{documentId}/shares',           'verb' => 'POST'],
        ['name' => 'file_share#destroy_document_share','url' => '/api/v1/documents/{documentId}/shares/{userId}',  'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // Document workflows (review / approval)
        // ---------------------------------------------------------
        ['name' => 'workflow#my_tasks',      'url' => '/api/v1/me/workflow-tasks',                       'verb' => 'GET'],
        ['name' => 'workflow#history',       'url' => '/api/v1/documents/{documentId}/workflows',        'verb' => 'GET'],
        ['name' => 'workflow#create',        'url' => '/api/v1/documents/{documentId}/workflows',        'verb' => 'POST'],
        ['name' => 'workflow#active',        'url' => '/api/v1/documents/{documentId}/workflows/active', 'verb' => 'GET'],
        ['name' => 'workflow#submit_action', 'url' => '/api/v1/workflows/{workflowId}/action',           'verb' => 'POST'],
        ['name' => 'workflow#cancel',        'url' => '/api/v1/workflows/{workflowId}',                  'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // Document contacts (senders / receivers)
        // ---------------------------------------------------------
        ['name' => 'document_contact#contact_roles',     'url' => '/api/v1/contact-roles',                          'verb' => 'GET'],
        ['name' => 'document_contact#index_by_document', 'url' => '/api/v1/documents/{documentId}/contacts',         'verb' => 'GET'],
        ['name' => 'document_contact#create',            'url' => '/api/v1/documents/{documentId}/contacts',                  'verb' => 'POST'],
        ['name' => 'document_contact#destroy',           'url' => '/api/v1/documents/{documentId}/contacts/{contactId}',      'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // Case participants
        // ---------------------------------------------------------
        ['name' => 'case_participant#participant_roles', 'url' => '/api/v1/participant-roles',                                    'verb' => 'GET'],
        ['name' => 'case_participant#index_by_case',     'url' => '/api/v1/cases/{caseId}/participants',                          'verb' => 'GET'],
        ['name' => 'case_participant#create',            'url' => '/api/v1/cases/{caseId}/participants',                          'verb' => 'POST'],
        ['name' => 'case_participant#destroy',           'url' => '/api/v1/cases/{caseId}/participants/{participantId}',           'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // Company search (Datafordeler)
        // ---------------------------------------------------------
        ['name' => 'company#search',    'url' => '/api/v1/company/search',    'verb' => 'GET'],
        ['name' => 'company#cases',     'url' => '/api/v1/company/cases',     'verb' => 'GET'],
        ['name' => 'company#documents', 'url' => '/api/v1/company/documents', 'verb' => 'GET'],

        // ---------------------------------------------------------
        // Citizen search (Datafordeler)
        // ---------------------------------------------------------
        ['name' => 'citizen#search',    'url' => '/api/v1/citizen/search',    'verb' => 'GET'],
        ['name' => 'citizen#cases',     'url' => '/api/v1/citizen/cases',     'verb' => 'GET'],
        ['name' => 'citizen#documents', 'url' => '/api/v1/citizen/documents', 'verb' => 'GET'],

        // ---------------------------------------------------------
        // Organisation search (full data for the search page)
        // ---------------------------------------------------------
        ['name' => 'organisation#search',  'url' => '/api/v1/org/search',             'verb' => 'GET'],
        ['name' => 'organisation#members', 'url' => '/api/v1/org/{orgUuid}/members',  'verb' => 'GET'],

        // ---------------------------------------------------------
        // Employees
        // ---------------------------------------------------------
        ['name' => 'employee#search',      'url' => '/api/v1/employees/search',              'verb' => 'GET'],
        ['name' => 'employee#org_tree',    'url' => '/api/v1/employees/org-tree',            'verb' => 'GET'],
        ['name' => 'employee#user_grants', 'url' => '/api/v1/employees/{uuid}/grants',   'verb' => 'GET'],
        ['name' => 'employee#cases',       'url' => '/api/v1/employees/{username}/cases',    'verb' => 'GET'],

        // ---------------------------------------------------------
        // Caseworkers
        // ---------------------------------------------------------
        ['name' => 'case_worker#search_users',   'url' => '/api/v1/users/search',                            'verb' => 'GET'],
        ['name' => 'case_worker#index_by_case',  'url' => '/api/v1/cases/{caseId}/caseworkers',              'verb' => 'GET'],
        ['name' => 'case_worker#create',         'url' => '/api/v1/cases/{caseId}/caseworkers',              'verb' => 'POST'],
        ['name' => 'case_worker#destroy',        'url' => '/api/v1/cases/{caseId}/caseworkers/{targetUserId}', 'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // Audit log
        // ---------------------------------------------------------
        ['name' => 'audit#index',             'url' => '/api/v1/cases/{caseId}/audit-log',        'verb' => 'GET'],
        ['name' => 'audit#index_by_document', 'url' => '/api/v1/documents/{documentId}/audit-log', 'verb' => 'GET'],
        ['name' => 'audit#index_by_file',     'url' => '/api/v1/files/{fileId}/audit-log',         'verb' => 'GET'],

        // ---------------------------------------------------------
        // Search
        // ---------------------------------------------------------
        ['name' => 'search#search', 'url' => '/api/v1/search', 'verb' => 'GET'],

        // ---------------------------------------------------------
        // Favorites
        // ---------------------------------------------------------
        ['name' => 'favorite#index',   'url' => '/api/v1/favorites',                  'verb' => 'GET'],
        ['name' => 'favorite#create',  'url' => '/api/v1/favorites',                  'verb' => 'POST'],
        ['name' => 'favorite#destroy', 'url' => '/api/v1/favorites/{entity}/{key}',   'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // Recent (per-user access history)
        // ---------------------------------------------------------
        ['name' => 'recent#index', 'url' => '/api/v1/recent', 'verb' => 'GET'],

        // ---------------------------------------------------------
        // Current user
        // ---------------------------------------------------------
        ['name' => 'me#roles', 'url' => '/api/v1/me/roles', 'verb' => 'GET'],

        // ---------------------------------------------------------
        // Config (requires OpenCase Administrator role)
        // ---------------------------------------------------------
        ['name' => 'config#index',  'url' => '/api/v1/config',       'verb' => 'GET'],
        ['name' => 'config#update', 'url' => '/api/v1/config/{key}', 'verb' => 'PUT'],

        // ---------------------------------------------------------
        // Code lists (requires OpenCase Administrator role)
        // ---------------------------------------------------------
        ['name' => 'code_list#index',  'url' => '/api/v1/codelists/{list}',     'verb' => 'GET'],
        ['name' => 'code_list#create', 'url' => '/api/v1/codelists/{list}',     'verb' => 'POST'],
        ['name' => 'code_list#update', 'url' => '/api/v1/codelists/{list}/{id}', 'verb' => 'PUT'],

        // ---------------------------------------------------------
        // Templates
        // ---------------------------------------------------------
        ['name' => 'template#index',    'url' => '/api/v1/templates',              'verb' => 'GET'],
        ['name' => 'template#upload',   'url' => '/api/v1/templates',              'verb' => 'POST'],
        ['name' => 'template#destroy',  'url' => '/api/v1/templates/{id}',         'verb' => 'DELETE'],
        ['name' => 'template#download', 'url' => '/api/v1/templates/{id}/download','verb' => 'GET'],

        // ---------------------------------------------------------
        // Digital Post
        // ---------------------------------------------------------
        ['name' => 'digital_post#check',                'url' => '/api/v1/digital-post/check',                                   'verb' => 'GET'],
        ['name' => 'digital_post#create_shipment',      'url' => '/api/v1/documents/{documentId}/digital-post/shipments',        'verb' => 'POST'],
        ['name' => 'digital_post#latest_shipment',      'url' => '/api/v1/documents/{documentId}/digital-post/shipments/latest', 'verb' => 'GET'],
        ['name' => 'digital_post#download_shipment_file', 'url' => '/api/v1/digital-post/shipment-files/{id}/download',         'verb' => 'GET'],
        ['name' => 'digital_post#download_receiver_file', 'url' => '/api/v1/digital-post/receiver-files/{id}/download',         'verb' => 'GET'],

        // ---------------------------------------------------------
        // Email (send document via the user's Mail app account)
        // ---------------------------------------------------------
        ['name' => 'email#account_status', 'url' => '/api/v1/email/account-status',                'verb' => 'GET'],
        ['name' => 'email#recipients',     'url' => '/api/v1/documents/{documentId}/email-recipients', 'verb' => 'GET'],
        ['name' => 'email#send',           'url' => '/api/v1/documents/{documentId}/send-email',       'verb' => 'POST'],

        // Aktindsigt (right of access)
        // ---------------------------------------------------------
        ['name' => 'access_request#index',            'url' => '/api/v1/cases/{caseId}/access-requests',                     'verb' => 'GET'],
        ['name' => 'access_request#create',           'url' => '/api/v1/cases/{caseId}/access-requests',                     'verb' => 'POST'],
        ['name' => 'access_request#decision_templates',  'url' => '/api/v1/access-requests/decision-templates',              'verb' => 'GET'],
        ['name' => 'access_request#exclusion_reasons',   'url' => '/api/v1/access-requests/exclusion-reasons',               'verb' => 'GET'],
        ['name' => 'access_request#show',             'url' => '/api/v1/access-requests/{id}',                               'verb' => 'GET'],
        ['name' => 'access_request#update',           'url' => '/api/v1/access-requests/{id}',                               'verb' => 'PATCH'],
        ['name' => 'access_request#change_status',    'url' => '/api/v1/access-requests/{id}/status',                        'verb' => 'PUT'],
        ['name' => 'access_request#search',           'url' => '/api/v1/access-requests/{id}/search',                        'verb' => 'GET'],
        ['name' => 'access_request#add_item',         'url' => '/api/v1/access-requests/{id}/items',                         'verb' => 'POST'],
        ['name' => 'access_request#update_item',      'url' => '/api/v1/access-request-items/{itemId}',                      'verb' => 'PATCH'],
        ['name' => 'access_request#remove_item',      'url' => '/api/v1/access-request-items/{itemId}',                      'verb' => 'DELETE'],
        ['name' => 'access_request#save_decision',    'url' => '/api/v1/access-requests/{id}/decision',                      'verb' => 'POST'],
        ['name' => 'access_request#approve_decision', 'url' => '/api/v1/access-requests/{id}/approve',                       'verb' => 'POST'],
        ['name' => 'access_request#add_redaction',    'url' => '/api/v1/access-request-items/{itemId}/redactions',            'verb' => 'POST'],
        ['name' => 'access_request#remove_redaction', 'url' => '/api/v1/access-redactions/{redactionId}',                    'verb' => 'DELETE'],
        ['name' => 'access_request#masking_files',   'url' => '/api/v1/access-requests/{id}/masking-files',                  'verb' => 'GET'],
        ['name' => 'access_request#upload_masked',   'url' => '/api/v1/access-request-items/{itemId}/files/{fileId}/masked', 'verb' => 'POST'],
        ['name' => 'access_request#download_masked', 'url' => '/api/v1/access-request-items/{itemId}/files/{fileId}/masked/download', 'verb' => 'GET'],
        ['name' => 'access_export#export',            'url' => '/api/v1/access-requests/{id}/export',                        'verb' => 'GET'],
        ['name' => 'access_export#mark_sent',         'url' => '/api/v1/access-requests/{id}/mark-sent',                     'verb' => 'POST'],

        // ---------------------------------------------------------
        // Talk integration
        // ---------------------------------------------------------
        ['name' => 'talk#save_chat', 'url' => '/api/v1/talk/save-chat', 'verb' => 'POST'],

        // ---------------------------------------------------------
        // Transaction log
        // ---------------------------------------------------------
        ['name' => 'transaction_log#log_address_protection', 'url' => '/api/v1/transaction-log/address-protection', 'verb' => 'POST'],

        // ---------------------------------------------------------
        // Reminders
        // ---------------------------------------------------------
        ['name' => 'reminder#my_reminders',        'url' => '/api/v1/me/reminders',                    'verb' => 'GET'],
        ['name' => 'reminder#index_by_case',       'url' => '/api/v1/cases/{caseId}/reminders',        'verb' => 'GET'],
        ['name' => 'reminder#create_for_case',     'url' => '/api/v1/cases/{caseId}/reminders',        'verb' => 'POST'],
        ['name' => 'reminder#index_by_document',   'url' => '/api/v1/documents/{documentId}/reminders','verb' => 'GET'],
        ['name' => 'reminder#create_for_document', 'url' => '/api/v1/documents/{documentId}/reminders','verb' => 'POST'],
        ['name' => 'reminder#update',              'url' => '/api/v1/reminders/{id}',                  'verb' => 'PUT'],
        ['name' => 'reminder#destroy',             'url' => '/api/v1/reminders/{id}',                  'verb' => 'DELETE'],

        // ---------------------------------------------------------
        // Admin (requires NC admin privileges)
        // ---------------------------------------------------------
        ['name' => 'admin#list_profiles',       'url' => '/api/v1/admin/profiles',                          'verb' => 'GET'],
        ['name' => 'admin#create_profile',      'url' => '/api/v1/admin/profiles',                          'verb' => 'POST'],
        ['name' => 'admin#profile_users',       'url' => '/api/v1/admin/profiles/{id}/users',               'verb' => 'GET'],
        ['name' => 'admin#grant_access',        'url' => '/api/v1/admin/users/{userId}/access',             'verb' => 'POST'],
        ['name' => 'admin#revoke_access',       'url' => '/api/v1/admin/users/{userId}/access/{profileId}', 'verb' => 'DELETE'],
        ['name' => 'admin#bulk_grant',          'url' => '/api/v1/admin/users/{userId}/bulk-access',        'verb' => 'POST'],
        ['name' => 'admin#revoke_all_access',   'url' => '/api/v1/admin/users/{userId}/access',             'verb' => 'DELETE'],
        ['name' => 'admin#setup_elasticsearch', 'url' => '/api/v1/admin/es/setup',                         'verb' => 'POST'],
        ['name' => 'admin#reindex_all',         'url' => '/api/v1/admin/reindex',                           'verb' => 'POST'],
        ['name' => 'admin#reindex_case',        'url' => '/api/v1/admin/reindex/{caseId}',                  'verb' => 'POST'],
    ],
];
