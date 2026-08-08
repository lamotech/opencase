export function isCpr(value) {
	return /^\d{10}$/.test(String(value || '').replace(/\s/g, ''))
}

export function maskCpr(value) {
	if (!value) return ''
	const v = String(value).replace(/\s/g, '')
	if (isCpr(v)) return v.slice(0, 6) + 'XXXX'
	return value
}

/**
 * The party number from an audit entry's details, masked when it is a CPR.
 *
 * A citizen's number is stored under `cpr` and a company's under `cvr`.
 * Entries written before that split stored both kinds under `cvr`, so a
 * 10-digit value found there is masked too.
 *
 * @param {object} details parsed audit-log details
 * @return {string} the number as it may be shown
 */
export function maskedPartyNumber(details) {
	return maskCpr(details?.cpr || details?.cvr || '')
}
