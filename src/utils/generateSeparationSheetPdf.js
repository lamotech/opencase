const TYPE_LABELS = {
	'existing case': t('opencase', 'Eksisterende sag'),
	'new case': t('opencase', 'Ny sag'),
	'inbox case': t('opencase', 'Indbakkesag'),
	attachment: t('opencase', 'Bilag'),
}

// Types that file a document under freshly chosen values rather than an
// existing case — those values go on the sheet so they can be read off it.
const TYPES_WITH_VALUES = ['new case', 'inbox case']

/**
 * The stored values of a sheet, as label/value pairs ready to print.
 * Values the sheet does not carry are left out.
 *
 * @param {object} sheet - A separation sheet as returned by the API
 * @return {Array<{label: string, value: string}>}
 */
function valueRows(sheet) {
	if (!TYPES_WITH_VALUES.includes(sheet.type)) {
		return []
	}

	const facet = [sheet.classification_facet_code, sheet.classification_facet_title]
		.filter(Boolean).join(' – ')
	const kle = [sheet.class_subject_code, sheet.class_subject_title]
		.filter(Boolean).join(' – ')

	return [
		{ label: t('opencase', 'Ansvarlig'), value: sheet.responsible_user_display_name },
		{ label: t('opencase', 'Organisation'), value: sheet.organisation_name },
		{ label: t('opencase', 'KLE-nummer'), value: kle },
		{ label: t('opencase', 'Følsomhed'), value: sheet.sensitivity_title },
		{ label: t('opencase', 'Handlingsfacet'), value: facet },
		{ label: t('opencase', 'Indsigtsgrad'), value: sheet.insight_level_name },
	].filter(row => row.value)
}

/**
 * Generate a printable A4 PDF for a separation sheet — a large QR code
 * encoding the sheet's unique name, plus its type/case/title and, for the
 * types that carry them, the filing values — and open it in a new tab with
 * the browser print dialog armed.
 *
 * @param {object} sheet - A separation sheet as returned by the API
 *   ({id, type, name, case_number, title, ...}).
 */
export async function generateAndPrintSeparationSheetPdf(sheet) {
	// Open the tab synchronously (before any await) so popup blockers don't
	// treat it as an unsolicited popup.
	const printWindow = window.open('', '_blank')

	const [{ jsPDF }, QRCode] = await Promise.all([
		import('jspdf'),
		import('qrcode'),
	])

	const doc = new jsPDF({ unit: 'mm', format: 'a4' })
	const pageW = doc.internal.pageSize.getWidth()

	doc.setFont('helvetica', 'bold')
	doc.setFontSize(20)
	doc.text(t('opencase', 'Separationsark'), pageW / 2, 30, { align: 'center' })

	const qrDataUrl = await QRCode.toDataURL(sheet.name || String(sheet.id), { margin: 1, width: 500 })
	const qrSize = 90
	const qrX = (pageW - qrSize) / 2
	const qrY = 45
	doc.addImage(qrDataUrl, 'PNG', qrX, qrY, qrSize, qrSize)

	let y = qrY + qrSize + 14
	doc.setFont('helvetica', 'bold')
	doc.setFontSize(16)
	doc.text(sheet.name || '', pageW / 2, y, { align: 'center' })
	y += 10

	doc.setFont('helvetica', 'normal')
	doc.setFontSize(12)
	doc.setTextColor(100, 100, 100)
	doc.text(TYPE_LABELS[sheet.type] || sheet.type, pageW / 2, y, { align: 'center' })
	doc.setTextColor(0, 0, 0)
	y += 10

	if (sheet.case_number) {
		doc.setFontSize(12)
		doc.text(sheet.case_number, pageW / 2, y, { align: 'center' })
		y += 7
	}

	if (sheet.title) {
		doc.setFontSize(11)
		const lines = doc.splitTextToSize(sheet.title, pageW - 40)
		doc.text(lines, pageW / 2, y, { align: 'center' })
		y += lines.length * 6
	}

	// Two left-aligned columns, so long values wrap under their own label
	const rows = valueRows(sheet)
	if (rows.length) {
		const labelX = 30
		const valueX = 75
		const valueW = pageW - valueX - 30

		y += 8
		doc.setDrawColor(200, 200, 200)
		doc.line(labelX, y, pageW - labelX, y)
		y += 10

		doc.setFontSize(11)
		for (const row of rows) {
			const lines = doc.splitTextToSize(String(row.value), valueW)

			doc.setFont('helvetica', 'bold')
			doc.setTextColor(100, 100, 100)
			doc.text(row.label, labelX, y)

			doc.setFont('helvetica', 'normal')
			doc.setTextColor(0, 0, 0)
			doc.text(lines, valueX, y)

			y += lines.length * 6 + 2
		}
	}

	doc.autoPrint()
	const blobUrl = doc.output('bloburl')

	if (printWindow) {
		printWindow.location.href = blobUrl
	} else {
		window.open(blobUrl, '_blank')
	}
}
