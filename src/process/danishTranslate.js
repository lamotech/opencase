/**
 * Danish texts for the bpmn-js process designer: palette, context pad,
 * replace/append menus, tools and messages. bpmn-js passes every UI text
 * through its "translate" service; this module replaces it.
 *
 * Usage: new Modeler({ additionalModules: [danishTranslateModule] })
 *
 * Texts missing here are shown in English. The terms follow the properties
 * panel (ProcessPropertiesPanel.vue).
 */

// Keys are the English texts, quoted alike for readability
/* eslint-disable quote-props */
const DA = {
	// Tools (palette)
	'Activate hand tool': 'Aktivér håndværktøjet',
	'Activate lasso tool': 'Aktivér lassoværktøjet',
	'Activate create/remove space tool': 'Aktivér værktøjet til at skabe/fjerne plads',
	'Activate global connect tool': 'Aktivér forbindelsesværktøjet',

	// Create (palette)
	'Create start event': 'Opret starthændelse',
	'Create intermediate/boundary event': 'Opret mellemliggende hændelse/grænsehændelse',
	'Create end event': 'Opret sluthændelse',
	'Create gateway': 'Opret gateway',
	'Create task': 'Opret opgave',
	'Create expanded sub-process': 'Opret udvidet underproces',
	'Create data object reference': 'Opret dataobjekt',
	'Create data store reference': 'Opret datalager',
	'Create pool/participant': 'Opret pool/deltager',
	'Create group': 'Opret gruppe',

	// Context pad
	'Append task': 'Tilføj opgave',
	'Append end event': 'Tilføj sluthændelse',
	'Append gateway': 'Tilføj gateway',
	'Append intermediate/boundary event': 'Tilføj mellemliggende hændelse/grænsehændelse',
	'Append receive task': 'Tilføj modtageopgave',
	'Append message intermediate catch event': 'Tilføj mellemliggende beskedhændelse (modtag)',
	'Append timer intermediate catch event': 'Tilføj mellemliggende timerhændelse',
	'Append conditional intermediate catch event': 'Tilføj mellemliggende betingelseshændelse',
	'Append signal intermediate catch event': 'Tilføj mellemliggende signalhændelse (modtag)',
	'Append compensation activity': 'Tilføj kompensationsaktivitet',
	'Add text annotation': 'Tilføj tekstnote',
	'Add lane above': 'Tilføj bane over',
	'Add lane below': 'Tilføj bane under',
	'Divide into two lanes': 'Opdel i to baner',
	'Divide into three lanes': 'Opdel i tre baner',
	'Change element': 'Skift elementtype',
	'Delete': 'Slet',
	'Connect to other element': 'Forbind til andet element',
	'Connect using association': 'Forbind med association',
	'Connect using data input association': 'Forbind med datainput-association',
	'Open {element}': 'Åbn {element}',

	// Alignment and search
	'Align elements': 'Justér elementer',
	'Align elements ': 'Justér elementer ',
	'Distribute elements horizontally': 'Fordel elementer vandret',
	'Distribute elements vertically': 'Fordel elementer lodret',
	'Search in diagram': 'Søg i diagrammet',

	// Tasks and activities
	'Task': 'Opgave',
	'User task': 'Brugeropgave',
	'Service task': 'Serviceopgave',
	'Send task': 'Sendeopgave',
	'Receive task': 'Modtageopgave',
	'Manual task': 'Manuel opgave',
	'Business rule task': 'Forretningsregelopgave',
	'Script task': 'Scriptopgave',
	'Call activity': 'Kaldaktivitet',
	'Sub-process (collapsed)': 'Underproces (sammenklappet)',
	'Sub-process (expanded)': 'Underproces (udvidet)',
	'Ad-hoc sub-process (collapsed)': 'Ad hoc-underproces (sammenklappet)',
	'Ad-hoc sub-process (expanded)': 'Ad hoc-underproces (udvidet)',
	'Event sub-process': 'Hændelsesunderproces',
	'Transaction': 'Transaktion',

	// Loop and multi-instance markers
	'Loop': 'Løkke',
	'Parallel multi-instance': 'Parallel flerinstans',
	'Sequential multi-instance': 'Sekventiel flerinstans',
	'Collection': 'Samling',
	'Participant multiplicity': 'Deltagermultiplicitet',
	'Toggle non-interrupting': 'Skift mellem afbrydende og ikke-afbrydende',

	// Gateways
	'Exclusive gateway': 'Eksklusiv gateway',
	'Parallel gateway': 'Parallel gateway',
	'Inclusive gateway': 'Inklusiv gateway',
	'Complex gateway': 'Kompleks gateway',
	'Event-based gateway': 'Hændelsesbaseret gateway',

	// Start events
	'Start event': 'Starthændelse',
	'Message start event': 'Beskedstarthændelse',
	'Message start event (non-interrupting)': 'Beskedstarthændelse (ikke-afbrydende)',
	'Timer start event': 'Timerstarthændelse',
	'Timer start event (non-interrupting)': 'Timerstarthændelse (ikke-afbrydende)',
	'Conditional start event': 'Betingelsesstarthændelse',
	'Conditional start event (non-interrupting)': 'Betingelsesstarthændelse (ikke-afbrydende)',
	'Signal start event': 'Signalstarthændelse',
	'Signal start event (non-interrupting)': 'Signalstarthændelse (ikke-afbrydende)',
	'Error start event': 'Fejlstarthændelse',
	'Escalation start event': 'Eskaleringsstarthændelse',
	'Escalation start event (non-interrupting)': 'Eskaleringsstarthændelse (ikke-afbrydende)',
	'Compensation start event': 'Kompensationsstarthændelse',

	// Intermediate events
	'Intermediate throw event': 'Mellemliggende hændelse (send)',
	'Message intermediate catch event': 'Mellemliggende beskedhændelse (modtag)',
	'Message intermediate throw event': 'Mellemliggende beskedhændelse (send)',
	'Timer intermediate catch event': 'Mellemliggende timerhændelse',
	'Conditional intermediate catch event': 'Mellemliggende betingelseshændelse',
	'Signal intermediate catch event': 'Mellemliggende signalhændelse (modtag)',
	'Signal intermediate throw event': 'Mellemliggende signalhændelse (send)',
	'Escalation intermediate throw event': 'Mellemliggende eskaleringshændelse (send)',
	'Compensation intermediate throw event': 'Mellemliggende kompensationshændelse (send)',
	'Link intermediate catch event': 'Mellemliggende linkhændelse (modtag)',
	'Link intermediate throw event': 'Mellemliggende linkhændelse (send)',

	// Boundary events
	'Boundary event': 'Grænsehændelse',
	'Message boundary event': 'Beskedgrænsehændelse',
	'Message boundary event (non-interrupting)': 'Beskedgrænsehændelse (ikke-afbrydende)',
	'Timer boundary event': 'Timergrænsehændelse',
	'Timer boundary event (non-interrupting)': 'Timergrænsehændelse (ikke-afbrydende)',
	'Conditional boundary event': 'Betingelsesgrænsehændelse',
	'Conditional boundary event (non-interrupting)': 'Betingelsesgrænsehændelse (ikke-afbrydende)',
	'Signal boundary event': 'Signalgrænsehændelse',
	'Signal boundary event (non-interrupting)': 'Signalgrænsehændelse (ikke-afbrydende)',
	'Escalation boundary event': 'Eskaleringsgrænsehændelse',
	'Escalation boundary event (non-interrupting)': 'Eskaleringsgrænsehændelse (ikke-afbrydende)',
	'Error boundary event': 'Fejlgrænsehændelse',
	'Cancel boundary event': 'Annulleringsgrænsehændelse',
	'Compensation boundary event': 'Kompensationsgrænsehændelse',

	// End events
	'End event': 'Sluthændelse',
	'Message end event': 'Beskedsluthændelse',
	'Escalation end event': 'Eskaleringssluthændelse',
	'Error end event': 'Fejlsluthændelse',
	'Cancel end event': 'Annulleringssluthændelse',
	'Compensation end event': 'Kompensationssluthændelse',
	'Signal end event': 'Signalsluthændelse',
	'Terminate end event': 'Termineringssluthændelse',

	// Data and pools
	'Data object reference': 'Dataobjekt',
	'Data store reference': 'Datalager',
	'Expanded pool/participant': 'Udvidet pool/deltager',
	'Empty pool/participant': 'Tom pool/deltager',

	// Messages
	'flow elements must be children of pools/participants': 'Procesflow-elementer skal ligge i en pool/deltager',
	'Data object must be placed within a pool/participant.': 'Dataobjekter skal placeres i en pool/deltager.',
}
/* eslint-enable quote-props */

/**
 * Same contract as diagram-js' default translate: look the template up and
 * fill in {placeholders}.
 *
 * @param {string} template English text
 * @param {Object<string, string>} [replacements] placeholder values
 * @return {string}
 */
export function danishTranslate(template, replacements) {
	replacements = replacements || {}
	const text = DA[template] ?? template
	return text.replace(/{([^}]+)}/g, (_, key) => replacements[key] ?? `{${key}}`)
}

export default {
	translate: ['value', danishTranslate],
}
