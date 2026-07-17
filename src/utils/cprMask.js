export function isCpr(value) {
	return /^\d{10}$/.test(String(value || '').replace(/\s/g, ''))
}

export function maskCpr(value) {
	if (!value) return ''
	const v = String(value).replace(/\s/g, '')
	if (isCpr(v)) return v.slice(0, 6) + 'XXXX'
	return value
}
