import { classificationPlanLabel } from './classificationPlanLabel.js'

/**
 * Shared helpers for case scopes (afgrænsninger) on custom fields, phrases
 * and processes: which scope types can be chosen, and how a scope is shown.
 *
 * Scope types (see CaseScopeMatcher on the server):
 *   casetype     — a case type id
 *   organisation — an organisation uuid (also covers its sub-units)
 *   kle          — a KLE pattern ("27.69.00", "27.%", "*")
 *   class_plan   — an Emneplan item uuid (classification plan Emneplan)
 */

const SCOPE_TYPE_LABELS = {
	casetype: t('opencase', 'Sagstype'),
	organisation: t('opencase', 'Organisation'),
	kle: t('opencase', 'KLE-nummer'),
	class_plan: t('opencase', 'Emne'),
}

/**
 * The scope types an admin can add, for the active classification plan and
 * access profile type. Emneplan items are offered with the Emneplan; KLE is
 * left out when cases have no KLE number (Grupper with an Emneplan).
 *
 * @param {object} state The Vuex store state
 * @return {Array<{id: string, label: string}>}
 */
export function scopeTypeOptions(state) {
	const emneplan = state.classificationPlan === 'Emneplan'
	const groupMode = state.accessProfileType === 'group'
	return ['casetype', 'organisation', 'kle', 'class_plan']
		.filter(type => type !== 'class_plan' || emneplan)
		.filter(type => type !== 'kle' || !(emneplan && groupMode))
		.map(id => ({ id, label: SCOPE_TYPE_LABELS[id] }))
}

/**
 * @param {string} type A scope type
 * @return {string}
 */
export function caseScopeTypeLabel(type) {
	return SCOPE_TYPE_LABELS[type] || type
}

/**
 * Label of a class_plan scope: the Emneplan item's code and title, or the
 * uuid while the items are loading or for an item that is no longer active.
 *
 * @param {object} state The Vuex store state
 * @param {string} uuid The Emneplan item uuid
 * @return {string}
 */
export function classPlanScopeLabel(state, uuid) {
	const item = state.classificationPlanItems.find(i => i.uuid === uuid)
	return item ? classificationPlanLabel(item.code, item.title) : uuid
}

/**
 * Load the Emneplan items once, so class_plan scopes can be labelled.
 *
 * @param {object} store The Vuex store
 */
export function ensureClassificationPlanItems(store) {
	if (store.state.classificationPlan === 'Emneplan' && store.state.classificationPlanItems.length === 0) {
		store.dispatch('fetchClassificationPlanItems').catch(() => {})
	}
}
