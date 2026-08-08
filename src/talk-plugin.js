/**
 * OpenCase – Nextcloud Talk integration.
 *
 * Registers a "Save to OpenCase" message-action button inside Talk.
 * Talk calls window.OCA.Talk.registerMessageAction() from its init.js
 * (apps-writable/spreed/src/init.js) and the callback receives:
 *
 *   { message, metadata, apiVersion: 'v3' }
 *
 * where `message` is the clicked ChatMessage and `metadata` is the
 * current conversation object (with .token, .displayName).
 *
 * Script injection: TalkIntegrationListener registers this bundle via
 * OCP\Collaboration\Resources\LoadAdditionalScriptsEvent, which Talk
 * dispatches when it renders its page.
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'

// ─── Talk registration ────────────────────────────────────────────────────────

/**
 * Wait for Talk's JS API to be available, then register the message action.
 * Talk's init.js sets window.OCA.Talk before mounting the Vue app, so this
 * normally resolves immediately on the first try.
 */
function registerWithTalk() {
	if (window.OCA?.Talk?.registerMessageAction) {
		window.OCA.Talk.registerMessageAction({
			label: t('opencase', 'Save to OpenCase'),
			icon: 'icon-folder',
			callback: handleSaveToOpenCase,
		})
	} else {
		setTimeout(registerWithTalk, 200)
	}
}

// ─── Action callback ──────────────────────────────────────────────────────────

/**
 * Invoked by Talk when the user clicks "Save to OpenCase" on a message.
 *
 * @param {object} data
 * @param {object} data.message  - ChatMessage that was clicked
 * @param {object} data.metadata - Current Talk conversation
 */
async function handleSaveToOpenCase({ message, metadata }) {
	const token = message?.token ?? metadata?.token
	const conversationName = metadata?.displayName ?? t('opencase', 'Chat')
	const currentUserId = window.OC?.currentUser ?? ''

	const dialog = openDialog(conversationName)

	dialog.onConfirm(async (caseId, documentCategoryId) => {
		try {
			// Fetch up to 200 messages for this conversation from Talk.
			const chatResp = await axios.get(
				generateOcsUrl(`apps/spreed/api/v1/chat/${token}`),
				{ params: { lookIntoFuture: 0, limit: 200, lastKnownMessageId: 0 } },
			)
			const messages = chatResp.data?.ocs?.data ?? []

			const content = formatHtmlTranscript(conversationName, messages, currentUserId)
			const filename = buildFilename(conversationName)
			const title = buildTitle(conversationName)

			await axios.post(
				generateOcsUrl('apps/opencase/api/v1/talk/save-chat'),
				{ caseId, documentCategoryId, title, content, filename },
			)

			showSuccess(t('opencase', 'Chat saved to OpenCase'))
		} catch {
			showError(t('opencase', 'Failed to save chat to OpenCase'))
		}
	})
}

// ─── Formatting ───────────────────────────────────────────────────────────────

/**
 * Resolve Talk message parameter placeholders ({mention}, {actor}, etc.)
 * into plain display names.
 *
 * @param {object} msg  Raw ChatMessage from the Talk API
 * @returns {string}
 */
function resolveMessageText(msg) {
	return (msg.message ?? '').replace(/\{([^}]+)\}/g, (match, key) => {
		const param = msg.messageParameters?.[key]
		if (!param) return match
		return param.name ?? param.id ?? match
	})
}

/** Deterministic avatar colour from a string. */
function avatarColor(str) {
	const palette = ['#0082c9', '#e91e63', '#9c27b0', '#3f51b5', '#009688', '#ff5722', '#795548', '#607d8b']
	let h = 0
	for (let i = 0; i < str.length; i++) h = str.charCodeAt(i) + ((h << 5) - h)
	return palette[Math.abs(h) % palette.length]
}

/** Up to two initials from a display name. */
function initials(name) {
	return (name ?? '?').trim().split(/\s+/).map(w => w[0] ?? '').join('').toUpperCase().slice(0, 2) || '?'
}

/**
 * Build a self-contained HTML chat transcript styled like the Nextcloud Talk UI.
 *
 * @param {string}   conversationName
 * @param {object[]} messages       Raw ChatMessage objects from the Talk API
 * @param {string}   currentUserId  Nextcloud uid of the user saving the chat
 * @returns {string}  Complete HTML document
 */
function formatHtmlTranscript(conversationName, messages, currentUserId) {
	const sorted = [...messages].sort((a, b) => (a.timestamp ?? 0) - (b.timestamp ?? 0))
	const exportDate = new Date().toLocaleString()

	// ── Build message rows ──
	let prevDateStr = null
	let rows = ''

	for (const msg of sorted) {
		const ts = new Date((msg.timestamp ?? 0) * 1000)
		const dateStr = ts.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
		const timeStr = ts.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

		if (dateStr !== prevDateStr) {
			rows += `<div class="date-sep"><span>${escapeHtml(dateStr)}</span></div>\n`
			prevDateStr = dateStr
		}

		if (msg.messageType === 'system') {
			const text = resolveMessageText(msg)
			rows += `<div class="sys-msg">${escapeHtml(text)}</div>\n`
			continue
		}

		const isOwn = msg.actorId === currentUserId
		const name = msg.actorDisplayName || msg.actorId || '?'
		const text = resolveMessageText(msg)
		const color = avatarColor(msg.actorId || name)
		const textHtml = escapeHtml(text).replace(/\n/g, '<br>')

		if (isOwn) {
			rows += `
<div class="row own">
  <div class="col">
    <div class="bubble own-bubble">${textHtml}<span class="ts">${escapeHtml(timeStr)}</span></div>
  </div>
</div>\n`
		} else {
			rows += `
<div class="row other">
  <div class="av" style="background:${color}">${escapeHtml(initials(name))}</div>
  <div class="col">
    <div class="sender">${escapeHtml(name)}</div>
    <div class="bubble other-bubble">${textHtml}<span class="ts">${escapeHtml(timeStr)}</span></div>
  </div>
</div>\n`
		}
	}

	// ── Assemble document ──
	return `<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>${escapeHtml(conversationName)}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         background: #f0f2f5; color: #222; font-size: 14px; line-height: 1.45; }
  .header { background: #0082c9; color: #fff; padding: 14px 20px; }
  .header h1 { font-size: 1.1em; font-weight: 600; }
  .header p { font-size: 0.82em; opacity: .75; margin-top: 2px; }
  .messages { max-width: 760px; margin: 0 auto; padding: 16px 12px; display: flex;
              flex-direction: column; gap: 2px; }
  .date-sep { text-align: center; margin: 12px 0 6px; }
  .date-sep span { background: #d4d8df; color: #555; font-size: 0.78em;
                   padding: 3px 10px; border-radius: 10px; }
  .sys-msg { text-align: center; color: #0082c9; font-size: 0.82em;
             font-style: italic; margin: 4px 0; }
  .row { display: flex; align-items: flex-end; gap: 8px; }
  .row.own { flex-direction: row-reverse; }
  .av { width: 32px; height: 32px; border-radius: 50%; color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.78em; font-weight: 700; flex-shrink: 0; }
  .col { display: flex; flex-direction: column; max-width: 65%; }
  .row.own .col { align-items: flex-end; }
  .sender { font-size: 0.78em; color: #666; margin-bottom: 2px; padding-left: 4px; }
  .bubble { position: relative; padding: 8px 10px 6px;
            border-radius: 16px; word-break: break-word; }
  .own-bubble { background: #0082c9; color: #fff; border-bottom-right-radius: 4px; }
  .other-bubble { background: #fff; color: #222; border-bottom-left-radius: 4px;
                  box-shadow: 0 1px 2px rgba(0,0,0,.12); }
  .ts { font-size: 0.72em; float: right; margin-left: 8px; margin-top: 2px;
        white-space: nowrap; opacity: .7; }
</style>
</head>
<body>
<div class="header">
  <h1>${escapeHtml(conversationName)}</h1>
  <p>${escapeHtml(t('opencase', 'Exported'))}: ${escapeHtml(exportDate)}</p>
</div>
<div class="messages">
${rows}</div>
</body>
</html>`
}

function buildFilename(conversationName) {
	const date = new Date().toISOString().split('T')[0]
	const safe = conversationName
		.replace(/[^a-zA-Z0-9\s\-_]/g, '')
		.replace(/\s+/g, '-')
		.slice(0, 40)
	return `Chat-${date}-${safe}.html`
}

function buildTitle(conversationName) {
	const date = new Date().toISOString().split('T')[0]
	return `${t('opencase', 'Chat')} – ${conversationName} (${date})`
}

// ─── Dialog ───────────────────────────────────────────────────────────────────

/**
 * Open a modal dialog that lets the user pick a document category and search
 * for a case to save the chat into.
 *
 * @param {string} conversationName
 * @returns {{ onConfirm: Function, close: Function }}
 */
function openDialog(conversationName) {
	injectStyles()

	const overlay = document.createElement('div')
	overlay.className = 'oc-talk-overlay'
	overlay.setAttribute('role', 'dialog')
	overlay.setAttribute('aria-modal', 'true')
	overlay.setAttribute('aria-label', t('opencase', 'Save chat to OpenCase'))

	overlay.innerHTML = `
		<div class="oc-talk-dialog">
			<h2 class="oc-talk-dialog__title">${escapeHtml(t('opencase', 'Save chat to OpenCase'))}</h2>
			<p class="oc-talk-dialog__conv">${escapeHtml(conversationName)}</p>

			<label class="oc-talk-dialog__label" for="oc-talk-category-select">
				${escapeHtml(t('opencase', 'Document category'))}
			</label>
			<select class="oc-talk-dialog__select" id="oc-talk-category-select" disabled>
				<option value="">${escapeHtml(t('opencase', 'Loading…'))}</option>
			</select>

			<label class="oc-talk-dialog__label" for="oc-talk-case-search">
				${escapeHtml(t('opencase', 'Save to case'))}
			</label>
			<div class="oc-talk-case-search">
				<input
					class="oc-talk-dialog__input oc-talk-case-search__input"
					id="oc-talk-case-search"
					type="text"
					autocomplete="off"
					placeholder="${escapeHtml(t('opencase', 'Search for a case…'))}"
				/>
				<div class="oc-talk-case-search__results" hidden></div>
				<div class="oc-talk-case-search__selected" hidden></div>
			</div>

			<div class="oc-talk-dialog__actions">
				<button class="button oc-talk-dialog__cancel">${escapeHtml(t('opencase', 'Cancel'))}</button>
				<button class="button primary oc-talk-dialog__save" disabled>${escapeHtml(t('opencase', 'Save'))}</button>
			</div>
		</div>
	`

	document.body.appendChild(overlay)

	const categorySelect = overlay.querySelector('#oc-talk-category-select')
	const searchInput = overlay.querySelector('.oc-talk-case-search__input')
	const resultsEl = overlay.querySelector('.oc-talk-case-search__results')
	const selectedEl = overlay.querySelector('.oc-talk-case-search__selected')
	const saveBtn = overlay.querySelector('.oc-talk-dialog__save')
	const cancelBtn = overlay.querySelector('.oc-talk-dialog__cancel')

	let confirmCb = null
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

	cancelBtn.addEventListener('click', () => close())
	overlay.addEventListener('click', (e) => { if (e.target === overlay) close() })

	const onKeyDown = (e) => { if (e.key === 'Escape') close() }
	document.addEventListener('keydown', onKeyDown)

	const updateSaveBtn = () => {
		saveBtn.disabled = !selectedCaseId || !parseInt(categorySelect.value, 10)
	}

	categorySelect.addEventListener('change', updateSaveBtn)

	saveBtn.addEventListener('click', () => {
		if (!selectedCaseId) return
		const documentCategoryId = parseInt(categorySelect.value, 10)
		if (!documentCategoryId) return
		close()
		confirmCb?.(selectedCaseId, documentCategoryId)
	})

	const selectCase = (id, label) => {
		selectedCaseId = id
		resultsEl.hidden = true
		resultsEl.innerHTML = ''
		selectedEl.hidden = false
		selectedEl.innerHTML = `
			<span class="oc-talk-case-search__selected-label">${escapeHtml(label)}</span>
			<button class="oc-talk-case-search__clear" aria-label="${escapeHtml(t('opencase', 'Clear selection'))}">✕</button>
		`
		selectedEl.querySelector('.oc-talk-case-search__clear').addEventListener('click', () => {
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
			resultsEl.innerHTML = `<div class="oc-talk-case-search__no-results">${escapeHtml(t('opencase', 'No cases found'))}</div>`
			resultsEl.hidden = false
			return
		}
		resultsEl.innerHTML = ''
		cases.forEach((c) => {
			const item = document.createElement('div')
			item.className = 'oc-talk-case-search__item'
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
		resultsEl.innerHTML = `<div class="oc-talk-case-search__loading">${escapeHtml(t('opencase', 'Searching…'))}</div>`
		resultsEl.hidden = false
		try {
			const resp = await axios.get(
				generateOcsUrl('apps/opencase/api/v1/cases'),
				{ params: { search: query, limit: 20 } },
			)
			const casesData = resp.data?.ocs?.data ?? resp.data
			showResults(casesData?.cases ?? [])
		} catch {
			resultsEl.innerHTML = `<div class="oc-talk-case-search__no-results">${escapeHtml(t('opencase', 'Failed to search cases'))}</div>`
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
		.oc-talk-overlay {
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, .5);
			z-index: 10000;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.oc-talk-dialog {
			background: var(--color-main-background, #fff);
			color: var(--color-main-text, #222);
			border-radius: var(--border-radius-large, 8px);
			padding: 28px 32px;
			min-width: 340px;
			max-width: 480px;
			width: 90vw;
			box-shadow: 0 4px 24px rgba(0, 0, 0, .3);
		}
		.oc-talk-dialog__title {
			margin: 0 0 6px;
			font-size: 1.15em;
			font-weight: 700;
		}
		.oc-talk-dialog__conv {
			margin: 0 0 20px;
			opacity: .65;
			font-style: italic;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.oc-talk-dialog__label {
			display: block;
			margin-bottom: 6px;
			font-weight: 600;
		}
		.oc-talk-dialog__input,
		.oc-talk-dialog__select {
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
		.oc-talk-dialog__actions {
			display: flex;
			justify-content: flex-end;
			gap: 8px;
		}
		.oc-talk-case-search {
			position: relative;
			margin-bottom: 20px;
		}
		.oc-talk-case-search .oc-talk-dialog__input {
			margin-bottom: 0;
		}
		.oc-talk-case-search__results {
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
		.oc-talk-case-search__item {
			padding: 8px 10px;
			cursor: pointer;
			font-size: 0.95em;
		}
		.oc-talk-case-search__item:hover {
			background: var(--color-background-hover, #f0f0f0);
		}
		.oc-talk-case-search__no-results,
		.oc-talk-case-search__loading {
			padding: 8px 10px;
			font-size: 0.9em;
			opacity: .65;
		}
		.oc-talk-case-search__selected {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 8px 10px;
			border: 1px solid var(--color-border, #ddd);
			border-radius: var(--border-radius, 4px);
			background: var(--color-background-hover, #f0f0f0);
			font-size: 0.95em;
		}
		.oc-talk-case-search__selected-label {
			flex: 1;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.oc-talk-case-search__clear {
			background: none;
			border: none;
			cursor: pointer;
			font-size: 1em;
			padding: 0 2px;
			color: var(--color-main-text, #222);
			opacity: .65;
			flex-shrink: 0;
		}
		.oc-talk-case-search__clear:hover {
			opacity: 1;
		}
	`
	document.head.appendChild(style)
}

// ─── Boot ─────────────────────────────────────────────────────────────────────

registerWithTalk()
