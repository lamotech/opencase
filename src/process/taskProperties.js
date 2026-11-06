/**
 * Encoding of the OpenCase choices in the process designer as Flowable
 * BPMN attributes, and back.
 *
 * Process variables set by OpenCase when a process is started on a case:
 *   caseResponsible — Nextcloud user id of the case's responsible caseworker
 */

export const CASE_RESPONSIBLE_EXPRESSION = '${caseResponsible}' // eslint-disable-line no-template-curly-in-string

/** Candidate groups referring to an organisation unit are "org:<uuid>". */
const ORG_GROUP_PREFIX = 'org:'

/**
 * flowable:assignee → { mode, value }
 * mode: none | caseResponsible | user | expression
 *
 * @param {string|undefined} assignee the attribute value
 * @return {{mode: string, value: string}}
 */
export function decodeAssignee(assignee) {
	const value = (assignee || '').trim()
	if (value === '') {
		return { mode: 'none', value: '' }
	}
	if (value === CASE_RESPONSIBLE_EXPRESSION) {
		return { mode: 'caseResponsible', value: '' }
	}
	if (value.includes('${') || value.includes('#{')) {
		return { mode: 'expression', value }
	}
	return { mode: 'user', value }
}

/**
 * @param {string} mode none | caseResponsible | user | expression
 * @param {string} value user id or expression
 * @return {string|undefined} undefined removes the attribute
 */
export function encodeAssignee(mode, value) {
	switch (mode) {
	case 'caseResponsible':
		return CASE_RESPONSIBLE_EXPRESSION
	case 'user':
	case 'expression':
		return (value || '').trim() || undefined
	default:
		return undefined
	}
}

/**
 * @param {string|undefined} candidateGroups comma-separated attribute value
 * @return {string[]} organisation uuids
 */
export function decodeCandidateOrgs(candidateGroups) {
	return (candidateGroups || '')
		.split(',')
		.map(g => g.trim())
		.filter(g => g.startsWith(ORG_GROUP_PREFIX))
		.map(g => g.substring(ORG_GROUP_PREFIX.length))
}

/**
 * Replace the organisation groups and keep any other groups.
 *
 * @param {string|undefined} current attribute value
 * @param {string[]} orgUuids organisation uuids
 * @return {string|undefined} undefined removes the attribute
 */
export function encodeCandidateOrgs(current, orgUuids) {
	const others = (current || '')
		.split(',')
		.map(g => g.trim())
		.filter(g => g !== '' && !g.startsWith(ORG_GROUP_PREFIX))
	const groups = [...others, ...orgUuids.map(uuid => ORG_GROUP_PREFIX + uuid)]
	return groups.length ? groups.join(',') : undefined
}

/**
 * flowable:dueDate → { mode, amount, unit, value }
 * mode: none | duration (ISO 8601 "P5D", "P2W", "P1M") | expression
 *
 * @param {string|undefined} dueDate the attribute value
 * @return {{mode: string, amount: number, unit: string, value: string}}
 */
export function decodeDueDate(dueDate) {
	const value = (dueDate || '').trim()
	if (value === '') {
		return { mode: 'none', amount: 5, unit: 'D', value: '' }
	}
	const match = value.match(/^P(\d+)([DWM])$/)
	if (match) {
		return { mode: 'duration', amount: parseInt(match[1], 10), unit: match[2], value }
	}
	return { mode: 'expression', amount: 5, unit: 'D', value }
}

/**
 * @param {string} mode none | duration | expression
 * @param {number} amount number of units
 * @param {string} unit D | W | M
 * @param {string} value expression
 * @return {string|undefined} undefined removes the attribute
 */
export function encodeDueDate(mode, amount, unit, value) {
	if (mode === 'duration') {
		const n = parseInt(amount, 10)
		return n > 0 ? `P${n}${unit}` : undefined
	}
	if (mode === 'expression') {
		return (value || '').trim() || undefined
	}
	return undefined
}

/**
 * Outcomes of a user task: the choices of its "udfald" enum form property.
 * Completing the task stores the chosen id in the process variable "udfald",
 * which gateway conditions test, e.g. ${udfald == 'godkendt'}.
 */
export const OUTCOME_PROPERTY = 'udfald'

/**
 * @param {object} businessObject bpmn:UserTask
 * @return {{id: string, name: string}[]}
 */
export function getOutcomes(businessObject) {
	const property = (businessObject.extensionElements?.values || [])
		.find(v => v.$type === 'flowable:FormProperty' && v.id === OUTCOME_PROPERTY)
	return (property?.values || []).map(v => ({ id: v.id, name: v.name || v.id }))
}

/**
 * New extensionElements for the task with these outcomes, keeping any other
 * extension elements. undefined when nothing is left.
 *
 * @param {object} moddle the bpmn-js moddle service
 * @param {object} businessObject bpmn:UserTask
 * @param {{id: string, name: string}[]} outcomes outcomes to store
 * @return {object|undefined}
 */
export function buildOutcomeExtensions(moddle, businessObject, outcomes) {
	const values = (businessObject.extensionElements?.values || [])
		.filter(v => !(v.$type === 'flowable:FormProperty' && v.id === OUTCOME_PROPERTY))
	if (outcomes.length) {
		values.push(moddle.create('flowable:FormProperty', {
			id: OUTCOME_PROPERTY,
			name: 'Udfald',
			type: 'enum',
			required: true,
			values: outcomes.map(o => moddle.create('flowable:Value', { id: o.id, name: o.name })),
		}))
	}
	return values.length ? moddle.create('bpmn:ExtensionElements', { values }) : undefined
}

/**
 * "Godkend sagen" → "godkend_sagen"
 *
 * @param {string} label outcome label
 * @return {string}
 */
export function outcomeIdFromLabel(label) {
	const id = label.trim().toLowerCase()
		.replace(/æ/g, 'ae').replace(/ø/g, 'oe').replace(/å/g, 'aa')
		.replace(/[^a-z0-9]+/g, '_')
		.replace(/^_+|_+$/g, '')
	return /^[a-z_]/.test(id) ? id : 'u_' + id
}

/**
 * Automatic action of a service task: flowable:type="external-worker",
 * flowable:topic and the parameters as <flowable:field name stringValue>.
 * The available topics and their parameters come from the server
 * (GET /admin/process-topics).
 *
 * @param {object} businessObject bpmn:ServiceTask
 * @return {{topic: string, fields: Object<string, string>}}
 */
export function getServiceAction(businessObject) {
	const topic = businessObject.get('flowable:type') === 'external-worker'
		? (businessObject.get('flowable:topic') || '')
		: ''
	const fields = {}
	for (const v of businessObject.extensionElements?.values || []) {
		if (v.$type === 'flowable:Field' && v.name) {
			fields[v.name] = v.stringValue ?? v.string ?? ''
		}
	}
	return { topic, fields }
}

/**
 * Properties for modeling.updateProperties() that set the service task's
 * action and parameters. Other extension elements are kept; empty
 * parameters are left out.
 *
 * @param {object} moddle the bpmn-js moddle service
 * @param {object} businessObject bpmn:ServiceTask
 * @param {string} topic topic, '' for none
 * @param {Object<string, string>} fields parameter values
 * @return {object}
 */
export function buildServiceActionProperties(moddle, businessObject, topic, fields) {
	const values = (businessObject.extensionElements?.values || [])
		.filter(v => v.$type !== 'flowable:Field')
	if (topic) {
		for (const [name, value] of Object.entries(fields)) {
			if (value !== undefined && value !== null && String(value).trim() !== '') {
				values.push(moddle.create('flowable:Field', { name, stringValue: String(value) }))
			}
		}
	}
	return {
		'flowable:type': topic ? 'external-worker' : undefined,
		'flowable:topic': topic || undefined,
		extensionElements: values.length ? moddle.create('bpmn:ExtensionElements', { values }) : undefined,
	}
}

/**
 * Field types offered for user task forms: Flowable form property types,
 * plus "user" (an employee picker), stored as a string marked
 * fieldType="user".
 */
export const FIELD_TYPES = [
	{ id: 'string', label: 'Tekst' },
	{ id: 'long', label: 'Heltal' },
	{ id: 'date', label: 'Dato' },
	{ id: 'boolean', label: 'Ja/nej' },
	{ id: 'user', label: 'Medarbejder' },
]

const USER_FIELD_TYPE = 'user'

/** Variable names OpenCase sets itself, which fields must not overwrite. */
const RESERVED_VARIABLES = ['udfald', 'caseId', 'caseUuid', 'caseNumber', 'caseResponsible', 'orgUuid', 'kle', 'startedBy']

/**
 * Form fields of a user task: its flowable:formProperty elements other than
 * the outcome. The value entered is stored in a process variable named
 * after the field id.
 *
 * @param {object} businessObject bpmn:UserTask
 * @return {{id: string, name: string, type: string, required: boolean}[]}
 */
export function getFormFields(businessObject) {
	return (businessObject.extensionElements?.values || [])
		.filter(v => v.$type === 'flowable:FormProperty' && v.id !== OUTCOME_PROPERTY)
		.map(v => ({ id: v.id, name: v.name || v.id, type: v.fieldType === USER_FIELD_TYPE ? USER_FIELD_TYPE : (v.type || 'string'), required: !!v.required }))
}

/**
 * New extensionElements with these form fields, keeping the outcome and any
 * other extension elements. undefined when nothing is left.
 *
 * @param {object} moddle the bpmn-js moddle service
 * @param {object} businessObject bpmn:UserTask
 * @param {{id: string, name: string, type: string, required: boolean}[]} fields fields to store
 * @return {object|undefined}
 */
export function buildFieldExtensions(moddle, businessObject, fields) {
	const others = (businessObject.extensionElements?.values || [])
		.filter(v => !(v.$type === 'flowable:FormProperty' && v.id !== OUTCOME_PROPERTY))
	const values = [
		...fields.map(f => moddle.create('flowable:FormProperty', {
			id: f.id,
			name: f.name,
			type: f.type === USER_FIELD_TYPE ? 'string' : f.type,
			fieldType: f.type === USER_FIELD_TYPE ? USER_FIELD_TYPE : undefined,
			required: f.required || undefined,
			datePattern: f.type === 'date' ? 'yyyy-MM-dd' : undefined,
		})),
		...others,
	]
	return values.length ? moddle.create('bpmn:ExtensionElements', { values }) : undefined
}

/**
 * Form fields of all user tasks in the diagram, for actions that take a
 * value from a task ("Tildel sag", "Udfyld tillægsfelt").
 *
 * @param {object} elementRegistry the bpmn-js elementRegistry service
 * @return {{id: string, name: string, type: string, task: string}[]}
 */
export function getDiagramFormFields(elementRegistry) {
	const fields = []
	for (const element of elementRegistry.filter(e => e.type === 'bpmn:UserTask')) {
		const bo = element.businessObject
		for (const f of getFormFields(bo)) {
			if (!fields.some(x => x.id === f.id)) fields.push({ ...f, task: bo.name || bo.id })
		}
	}
	return fields
}

/**
 * A field id for this label that is a valid variable name and not already
 * used on the task or by OpenCase.
 *
 * @param {string} label field label
 * @param {string[]} taken ids already used on the task
 * @return {string}
 */
export function fieldIdFromLabel(label, taken) {
	const base = outcomeIdFromLabel(label)
	let id = base
	for (let i = 2; taken.includes(id) || RESERVED_VARIABLES.includes(id); i++) {
		id = `${base}_${i}`
	}
	return id
}

/**
 * Timer events (intermediate and boundary): when they fire, as the ISO 8601
 * values Flowable reads from timeDuration / timeCycle / timeDate.
 *
 * mode: duration ("after"), cycle ("repeat every", for non-interrupting
 * boundary timers), date (a fixed moment) or expression (advanced)
 */
export const TIMER_UNITS = [
	{ id: 'minutes', label: 'minutter' },
	{ id: 'hours', label: 'timer' },
	{ id: 'days', label: 'dage' },
	{ id: 'weeks', label: 'uger' },
	{ id: 'months', label: 'måneder' },
]

const UNIT_TO_ISO = { minutes: ['T', 'M'], hours: ['T', 'H'], days: ['', 'D'], weeks: ['', 'W'], months: ['', 'M'] }

/**
 * @param {number} amount number of units
 * @param {string} unit TIMER_UNITS id
 * @return {string|undefined} e.g. "P14D", "PT2H"
 */
function isoDuration(amount, unit) {
	const n = parseInt(amount, 10)
	const parts = UNIT_TO_ISO[unit]
	return n > 0 && parts ? `P${parts[0]}${n}${parts[1]}` : undefined
}

/**
 * @param {string} iso e.g. "P14D"
 * @return {{amount: number, unit: string}|null}
 */
function parseDuration(iso) {
	const m = (iso || '').match(/^P(T?)(\d+)([MHDW])$/)
	if (!m) return null
	const unit = m[1] === 'T'
		? { M: 'minutes', H: 'hours' }[m[3]]
		: { D: 'days', W: 'weeks', M: 'months' }[m[3]]
	return unit ? { amount: parseInt(m[2], 10), unit } : null
}

/**
 * The timer event definition of an event, if it has one.
 *
 * @param {object} businessObject bpmn event
 * @return {object|null}
 */
export function getTimerDefinition(businessObject) {
	return (businessObject.eventDefinitions || []).find(d => d.$type === 'bpmn:TimerEventDefinition') || null
}

/**
 * @param {object} timer bpmn:TimerEventDefinition
 * @return {{mode: string, amount: number, unit: string, date: string, value: string}}
 */
export function decodeTimer(timer) {
	// Empty until a time is chosen, so the form shows what is stored
	const form = { mode: 'duration', amount: '', unit: 'days', date: '', value: '' }
	const duration = timer.timeDuration?.body?.trim()
	const cycle = timer.timeCycle?.body?.trim()
	const date = timer.timeDate?.body?.trim()
	if (duration) {
		const parsed = parseDuration(duration)
		return parsed ? { ...form, ...parsed } : { ...form, mode: 'expression', value: duration }
	}
	if (cycle) {
		const parsed = cycle.startsWith('R/') ? parseDuration(cycle.substring(2)) : null
		return parsed ? { ...form, mode: 'cycle', ...parsed } : { ...form, mode: 'expression', value: cycle }
	}
	if (date) {
		const d = new Date(date)
		if (!isNaN(d)) {
			// value for <input type="datetime-local"> in local time
			const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().substring(0, 16)
			return { ...form, mode: 'date', date: local }
		}
		return { ...form, mode: 'expression', value: date }
	}
	return form
}

/**
 * The timeDuration / timeCycle / timeDate values for a timer form. An
 * expression is stored as a duration (Flowable evaluates it there).
 *
 * @param {{mode: string, amount: number, unit: string, date: string, value: string}} form timer form
 * @return {{timeDuration?: string, timeCycle?: string, timeDate?: string}}
 */
export function encodeTimer(form) {
	switch (form.mode) {
	case 'cycle': {
		const d = isoDuration(form.amount, form.unit)
		return d ? { timeCycle: `R/${d}` } : {}
	}
	case 'date':
		// The engine runs in UTC: store the moment with its offset
		return form.date ? { timeDate: new Date(form.date).toISOString() } : {}
	case 'expression':
		return (form.value || '').trim() ? { timeDuration: form.value.trim() } : {}
	default: {
		const d = isoDuration(form.amount, form.unit)
		return d ? { timeDuration: d } : {}
	}
	}
}
