/**
 * OpenCase – Nextcloud Files integration.
 *
 * Registers a "Save to OpenCase" file action in the Files app context menu.
 * Uses the @nextcloud/files FileAction API (NC26+).
 *
 * Script injection: FilesIntegrationListener registers this bundle via
 * OCA\Files\Event\LoadAdditionalScriptsEvent, which the Files app dispatches
 * when it renders its main page.
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import { registerFileAction } from '@nextcloud/files'

// ─── File action ──────────────────────────────────────────────────────────────

registerFileAction({
	id: 'opencase-save-to-case',
	displayName: () => t('opencase', 'Save to OpenCase'),
	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
		<path d="M20 6h-2.18c.07-.44.18-.88.18-1a3 3 0 0 0-6 0c0 .12.11.56.18 1H10a2 2 0 0 0-2 2v2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2h-2V8a2 2 0 0 0-2-2zm-6-1a1 1 0 0 1 1 1c0 .12-.11.56-.18 1h-1.64c-.07-.44-.18-.88-.18-1a1 1 0 0 1 1-1zm6 15H6v-8h12v8zm-2-8V8h-2v2H8V8H6h2v2H6V8h2v2h2V8h4v2h2z"/>
		<path d="M10 8h4v2h-4z"/>
	</svg>`,

	// Only enable for single files (not folders), and not for files already
	// inside the OpenCase virtual mount (.Sager/ path).
	enabled: (context) => {
		if (context.nodes.length !== 1) return false
		const node = context.nodes[0]
		// Use node.type string comparison — instanceof fails across webpack bundles
		if (node.type === 'folder') return false
		// Exclude files already inside the OpenCase mount
		const path = node.path ?? ''
		if (path.includes('/.Sager/') || path.startsWith('.Sager/')) return false
		return true
	},

	async exec(context) {
		const node = context.nodes[0]
		const filename = node.basename ?? t('opencase', 'File')
		const defaultTitle = filename.replace(/\.[^/.]+$/, '') // strip extension

		const dialog = openDialog(filename, defaultTitle)

		return new Promise((resolve) => {
			dialog.onConfirm(async (caseId, documentCategoryId, title) => {
				try {
					await axios.post(
						generateOcsUrl('apps/opencase/api/v1/files/save-to-case'),
						{ fileId: node.fileid, caseId, documentCategoryId, title },
					)
					showSuccess(t('opencase', 'File saved to OpenCase'))
					resolve(true)
				} catch {
					showError(t('opencase', 'Failed to save file to OpenCase'))
					resolve(false)
				}
			})

			dialog.onCancel(() => resolve(null))
		})
	},
})

// ─── Dialog ───────────────────────────────────────────────────────────────────

/**
 * Open a modal dialog that lets the user search for a case and set a document title.
 *
 * @param {string} filename     Original filename (shown as subtitle)
 * @param {string} defaultTitle Pre-filled document title
 * @returns {{ onConfirm: Function, onCancel: Function, close: Function }}
 */
function openDialog(filename, defaultTitle) {
	injectStyles()

	const overlay = document.createElement('div')
	overlay.className = 'oc-files-overlay'
	overlay.setAttribute('role', 'dialog')
	overlay.setAttribute('aria-modal', 'true')
	overlay.setAttribute('aria-label', t('opencase', 'Save to OpenCase'))

	overlay.innerHTML = `
		<div class="oc-files-dialog">
			<h2 class="oc-files-dialog__title">${escapeHtml(t('opencase', 'Save to OpenCase'))}</h2>
			<p class="oc-files-dialog__subtitle">${escapeHtml(filename)}</p>

			<label class="oc-files-dialog__label" for="oc-files-title-input">
				${escapeHtml(t('opencase', 'Document title'))}
			</label>
			<input
				class="oc-files-dialog__input"
				id="oc-files-title-input"
				type="text"
				value="${escapeHtml(defaultTitle)}"
			/>

			<label class="oc-files-dialog__label" for="oc-files-category-select">
				${escapeHtml(t('opencase', 'Document category'))}
			</label>
			<select class="oc-files-dialog__select" id="oc-files-category-select" disabled>
				<option value="">${escapeHtml(t('opencase', 'Loading…'))}</option>
			</select>

			<label class="oc-files-dialog__label" for="oc-files-case-search">
				${escapeHtml(t('opencase', 'Save to case'))}
			</label>
			<div class="oc-files-case-search">
				<input
					class="oc-files-dialog__input oc-files-case-search__input"
					id="oc-files-case-search"
					type="text"
					autocomplete="off"
					placeholder="${escapeHtml(t('opencase', 'Search for a case…'))}"
				/>
				<div class="oc-files-case-search__results" hidden></div>
				<div class="oc-files-case-search__selected" hidden></div>
			</div>

			<div class="oc-files-dialog__actions">
				<button class="button oc-files-dialog__cancel">${escapeHtml(t('opencase', 'Cancel'))}</button>
				<button class="button primary oc-files-dialog__save" disabled>${escapeHtml(t('opencase', 'Save'))}</button>
			</div>
		</div>
	`

	document.body.appendChild(overlay)

	const titleInput = overlay.querySelector('#oc-files-title-input')
	const searchInput = overlay.querySelector('.oc-files-case-search__input')
	const resultsEl = overlay.querySelector('.oc-files-case-search__results')
	const selectedEl = overlay.querySelector('.oc-files-case-search__selected')
	const categorySelect = overlay.querySelector('#oc-files-category-select')
	const saveBtn = overlay.querySelector('.oc-files-dialog__save')
	const cancelBtn = overlay.querySelector('.oc-files-dialog__cancel')

	let confirmCb = null
	let cancelCb = null
	let selectedCaseId = null
	let debounceTimer = null

	// Load document categories
	axios.get(generateOcsUrl('apps/opencase/api/v1/document-categories')).then((resp) => {
		const data = resp.data?.ocs?.data ?? resp.data
		const categories = data?.categories ?? []
		categorySelect.innerHTML = `<option value="">${escapeHtml(t('opencase', 'Select category…'))}</option>`
		categories.forEach((cat) => {
			const opt = document.createElement('option')
			opt.value = cat.id
			opt.textContent = cat.name
			categorySelect.appendChild(opt)
		})
		categorySelect.disabled = false
	}).catch(() => {
		categorySelect.innerHTML = `<option value="">${escapeHtml(t('opencase', 'Failed to load categories'))}</option>`
	})

	const close = () => {
		document.removeEventListener('keydown', onKeyDown)
		overlay.remove()
	}

	cancelBtn.addEventListener('click', () => { close(); cancelCb?.() })
	overlay.addEventListener('click', (e) => { if (e.target === overlay) { close(); cancelCb?.() } })

	const onKeyDown = (e) => {
		if (e.key === 'Escape') { close(); cancelCb?.() }
	}
	document.addEventListener('keydown', onKeyDown)

	saveBtn.addEventListener('click', () => {
		if (!selectedCaseId) return
		const documentCategoryId = parseInt(categorySelect.value, 10)
		if (!documentCategoryId) return
		const title = titleInput.value.trim() || titleInput.value
		close()
		confirmCb?.(selectedCaseId, documentCategoryId, title)
	})

	const updateSaveBtn = () => {
		saveBtn.disabled = !selectedCaseId || !parseInt(categorySelect.value, 10)
	}

	categorySelect.addEventListener('change', updateSaveBtn)

	const selectCase = (id, label) => {
		selectedCaseId = id
		resultsEl.hidden = true
		resultsEl.innerHTML = ''
		selectedEl.hidden = false
		selectedEl.innerHTML = `
			<span class="oc-files-case-search__selected-label">${escapeHtml(label)}</span>
			<button class="oc-files-case-search__clear" aria-label="${escapeHtml(t('opencase', 'Clear selection'))}">✕</button>
		`
		selectedEl.querySelector('.oc-files-case-search__clear').addEventListener('click', () => {
			selectedCaseId = null
			selectedEl.hidden = true
			selectedEl.innerHTML = ''
			searchInput.value = ''
			searchInput.hidden = false
			searchInput.focus()
			updateSaveBtn()
		})
		searchInput.hidden = true
		updateSaveBtn()
	}

	const showResults = (cases) => {
		if (!cases.length) {
			resultsEl.innerHTML = `<div class="oc-files-case-search__no-results">${escapeHtml(t('opencase', 'No cases found'))}</div>`
			resultsEl.hidden = false
			return
		}
		resultsEl.innerHTML = ''
		cases.forEach((c) => {
			const item = document.createElement('div')
			item.className = 'oc-files-case-search__item'
			item.textContent = `${c.case_number} — ${c.title}`
			item.addEventListener('click', () => selectCase(c.id, `${c.case_number} — ${c.title}`))
			resultsEl.appendChild(item)
		})
		resultsEl.hidden = false
	}

	const doSearch = async (query) => {
		if (!query.trim()) {
			resultsEl.hidden = true
			resultsEl.innerHTML = ''
			return
		}
		resultsEl.innerHTML = `<div class="oc-files-case-search__loading">${escapeHtml(t('opencase', 'Searching…'))}</div>`
		resultsEl.hidden = false
		try {
			const resp = await axios.get(
				generateOcsUrl('apps/opencase/api/v1/cases'),
				{ params: { search: query, limit: 20 } },
			)
			const casesData = resp.data?.ocs?.data ?? resp.data
			showResults(casesData?.cases ?? [])
		} catch {
			resultsEl.innerHTML = `<div class="oc-files-case-search__no-results">${escapeHtml(t('opencase', 'Failed to search cases'))}</div>`
		}
	}

	searchInput.addEventListener('input', () => {
		clearTimeout(debounceTimer)
		debounceTimer = setTimeout(() => doSearch(searchInput.value), 300)
	})

	// Close results when clicking outside
	document.addEventListener('click', (e) => {
		if (!overlay.contains(e.target)) {
			resultsEl.hidden = true
		}
	})

	return {
		onConfirm(cb) { confirmCb = cb },
		onCancel(cb) { cancelCb = cb },
		close,
	}
}

function escapeHtml(str) {
	const div = document.createElement('div')
	div.textContent = str
	return div.innerHTML
}

// ─── Styles ───────────────────────────────────────────────────────────────────

let stylesInjected = false

function injectStyles() {
	if (stylesInjected) return
	stylesInjected = true

	const style = document.createElement('style')
	style.textContent = `
		.oc-files-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, .5);
			z-index: 10000;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.oc-files-dialog {
			background: var(--color-main-background, #fff);
			color: var(--color-main-text, #222);
			border-radius: var(--border-radius-large, 8px);
			padding: 28px 32px;
			min-width: 340px;
			max-width: 480px;
			width: 90vw;
			box-shadow: 0 4px 24px rgba(0, 0, 0, .3);
		}
		.oc-files-dialog__title {
			margin: 0 0 4px;
			font-size: 1.15em;
			font-weight: 700;
		}
		.oc-files-dialog__subtitle {
			margin: 0 0 20px;
			opacity: .65;
			font-style: italic;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.oc-files-dialog__label {
			display: block;
			margin-bottom: 6px;
			font-weight: 600;
		}
		.oc-files-dialog__input,
		.oc-files-dialog__select {
			width: 100%;
			padding: 8px 10px;
			border: 1px solid var(--color-border, #ddd);
			border-radius: var(--border-radius, 4px);
			background: var(--color-main-background, #fff);
			color: var(--color-main-text, #222);
			font-size: 1em;
			margin-bottom: 20px;
			box-sizing: border-box;
		}
		.oc-files-dialog__actions {
			display: flex;
			justify-content: flex-end;
			gap: 8px;
		}
		.oc-files-case-search {
			position: relative;
			margin-bottom: 20px;
		}
		.oc-files-case-search .oc-files-dialog__input {
			margin-bottom: 0;
		}
		.oc-files-case-search__results {
			position: absolute;
			top: 100%;
			left: 0;
			right: 0;
			background: var(--color-main-background, #fff);
			border: 1px solid var(--color-border, #ddd);
			border-top: none;
			border-radius: 0 0 var(--border-radius, 4px) var(--border-radius, 4px);
			max-height: 200px;
			overflow-y: auto;
			z-index: 1;
			box-shadow: 0 4px 12px rgba(0,0,0,.15);
		}
		.oc-files-case-search__item {
			padding: 8px 10px;
			cursor: pointer;
			font-size: 0.95em;
		}
		.oc-files-case-search__item:hover {
			background: var(--color-background-hover, #f0f0f0);
		}
		.oc-files-case-search__no-results,
		.oc-files-case-search__loading {
			padding: 8px 10px;
			font-size: 0.9em;
			opacity: .65;
		}
		.oc-files-case-search__selected {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 8px 10px;
			border: 1px solid var(--color-border, #ddd);
			border-radius: var(--border-radius, 4px);
			background: var(--color-background-hover, #f0f0f0);
			font-size: 0.95em;
		}
		.oc-files-case-search__selected-label {
			flex: 1;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.oc-files-case-search__clear {
			background: none;
			border: none;
			cursor: pointer;
			font-size: 1em;
			padding: 0 2px;
			color: var(--color-main-text, #222);
			opacity: .65;
			flex-shrink: 0;
		}
		.oc-files-case-search__clear:hover {
			opacity: 1;
		}
	`
	document.head.appendChild(style)
}
