/**
 * Flatten phrase categories into tree order (depth-first, siblings by
 * sort_order then name). Categories whose parent is missing are treated as
 * top level, so nothing is ever dropped.
 *
 * @param {Array<object>} categories - Categories with id, parent_id, sort_order, name
 * @returns {Array<{category: object, depth: number}>}
 */
export function categoryTree(categories) {
	const ids = new Set(categories.map(c => c.id))
	const children = new Map()
	for (const c of categories) {
		const parent = c.parent_id != null && ids.has(c.parent_id) ? c.parent_id : null
		if (!children.has(parent)) children.set(parent, [])
		children.get(parent).push(c)
	}
	const compare = (a, b) => (a.sort_order - b.sort_order) || a.name.localeCompare(b.name, 'da')

	const result = []
	const seen = new Set()
	const walk = (parent, depth) => {
		for (const c of (children.get(parent) || []).sort(compare)) {
			if (seen.has(c.id)) continue
			seen.add(c.id)
			result.push({ category: c, depth })
			walk(c.id, depth + 1)
		}
	}
	walk(null, 0)
	return result
}

/**
 * Full path of a category, e.g. "Afgørelser › Afslag".
 *
 * @param {Array<object>} categories - All categories
 * @param {number|null} id - Category id
 * @returns {string}
 */
export function categoryPath(categories, id) {
	const byId = new Map(categories.map(c => [c.id, c]))
	const names = []
	const seen = new Set()
	for (let c = byId.get(id); c && !seen.has(c.id); c = byId.get(c.parent_id)) {
		seen.add(c.id)
		names.unshift(c.name)
	}
	return names.join(' › ')
}

/** File types accepted as phrase content (see PhraseService::ALLOWED_EXTENSIONS). */
export const PHRASE_FILE_ACCEPT = '.docx,.odt,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.oasis.opendocument.text'

export const PHRASE_STATUSES = ['draft', 'active', 'archived']

/**
 * @param {string} status - draft | active | archived
 * @returns {string}
 */
export function phraseStatusLabel(status) {
	return {
		draft: t('opencase', 'Kladde'),
		active: t('opencase', 'Aktiv'),
		archived: t('opencase', 'Arkiveret'),
	}[status] || status
}

/**
 * Whether a phrase may be attached to a template limited to the given
 * organisations (see TemplateService::phraseMatchesOrgs): the template has
 * no limit, the phrase has no organisation scope, or one of the phrase's
 * organisations is one of the template's organisations or above it.
 *
 * @param {object} phrase - Phrase with scopes [{scope_type, scope_value}]
 * @param {string[]} templateOrgUuids - The template's organisations ([] = all)
 * @param {Object<string, string>} parentOf - Organisation uuid → parent uuid
 * @return {boolean}
 */
export function phraseMatchesOrgs(phrase, templateOrgUuids, parentOf) {
	if (!templateOrgUuids.length) return true
	const phraseOrgs = new Set((phrase.scopes || [])
		.filter(s => s.scope_type === 'organisation')
		.map(s => s.scope_value))
	if (!phraseOrgs.size) return true
	return templateOrgUuids.some(uuid => {
		const seen = new Set()
		for (let cur = uuid; cur && !seen.has(cur); cur = parentOf[cur]) {
			if (phraseOrgs.has(cur)) return true
			seen.add(cur)
		}
		return false
	})
}

/**
 * Organisation tree flattened for a picker, with each name indented by depth.
 *
 * @param {Array<object>} orgs - Organisations with org_uuid, org_name, org_parent_uuid
 * @return {Array<{id: string, label: string}>}
 */
export function orgOptions(orgs) {
	const uuids = new Set(orgs.map(o => o.org_uuid))
	const children = new Map()
	for (const o of orgs) {
		const parent = o.org_parent_uuid && uuids.has(o.org_parent_uuid) ? o.org_parent_uuid : ''
		if (!children.has(parent)) children.set(parent, [])
		children.get(parent).push(o)
	}
	const result = []
	const seen = new Set()
	const walk = (parent, depth) => {
		for (const o of (children.get(parent) || []).sort((a, b) => a.org_name.localeCompare(b.org_name, 'da'))) {
			if (seen.has(o.org_uuid)) continue
			seen.add(o.org_uuid)
			result.push({ id: o.org_uuid, label: ' '.repeat(depth) + o.org_name, name: o.org_name })
			walk(o.org_uuid, depth + 1)
		}
	}
	walk('', 0)
	return result
}
