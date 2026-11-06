/**
 * Display label for an Emneplan item. Plans that identify a topic by its
 * name store the name as the code too — show it once then.
 *
 * @param {string} code The item's code
 * @param {string} title The item's title
 * @return {string}
 */
export function classificationPlanLabel(code, title) {
	if (!code) return title || ''
	if (!title || code === title) return code
	return `${code} – ${title}`
}
