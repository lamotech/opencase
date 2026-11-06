<template>
	<aside class="process-props">
		<template v-if="!element">
			<h3>{{ t('opencase', 'Egenskaber') }}</h3>
			<p class="process-props__hint">
				{{ t('opencase', 'Vælg et element i diagrammet for at se og redigere dets egenskaber.') }}
			</p>
			<p class="process-props__hint">
				{{ t('opencase', 'Brugeropgaver tildeles sagsbehandlere og kan have en frist. Ved en eksklusiv gateway angives en betingelse på hver udgående pil, f.eks. ${udfald == \'godkendt\'}.') }}
			</p>
		</template>

		<template v-else>
			<h3>{{ typeLabel }}</h3>
			<div class="process-props__field">
				<label>{{ t('opencase', 'Id') }}</label>
				<code>{{ element.businessObject.id }}</code>
			</div>

			<div v-if="hasName" class="process-props__field">
				<label for="process-props-name">{{ t('opencase', 'Navn') }}</label>
				<input id="process-props-name"
					v-model="form.name"
					type="text"
					:disabled="readOnly"
					@change="updateName">
			</div>

			<!-- User task -->
			<template v-if="isType('bpmn:UserTask')">
				<h4>{{ t('opencase', 'Tildeling') }}</h4>
				<div class="process-props__field">
					<label for="process-props-assignee">{{ t('opencase', 'Tildel til') }}</label>
					<select id="process-props-assignee"
						v-model="form.assigneeMode"
						:disabled="readOnly"
						@change="updateAssignee">
						<option value="none">
							{{ t('opencase', 'Ingen – tages af en kandidat') }}
						</option>
						<option value="caseResponsible">
							{{ t('opencase', 'Sagens ansvarlige sagsbehandler') }}
						</option>
						<option value="user">
							{{ t('opencase', 'Bestemt medarbejder') }}
						</option>
						<option value="expression">
							{{ t('opencase', 'Udtryk (avanceret)') }}
						</option>
					</select>
				</div>
				<div v-if="form.assigneeMode === 'user'" class="process-props__field">
					<label>{{ t('opencase', 'Medarbejder') }}</label>
					<NcSelect v-model="userOption"
						:options="userOptions"
						:filterable="false"
						:loading="searchingUsers"
						:disabled="readOnly"
						:placeholder="t('opencase', 'Søg efter navn eller brugernavn')"
						@search="onUserSearch" />
				</div>
				<div v-if="form.assigneeMode === 'expression'" class="process-props__field">
					<label for="process-props-assignee-expr">{{ t('opencase', 'Udtryk') }}</label>
					<input id="process-props-assignee-expr"
						v-model="form.assigneeValue"
						type="text"
						:disabled="readOnly"
						placeholder="${variabel}"
						@change="updateAssignee">
				</div>

				<div class="process-props__field">
					<label>{{ t('opencase', 'Kandidater (organisationer)') }}</label>
					<NcSelect v-model="candidateOrgOptions"
						:options="orgOptions"
						:multiple="true"
						:disabled="readOnly"
						:placeholder="t('opencase', 'Medarbejdere i disse enheder kan tage opgaven')" />
				</div>

				<h4>{{ t('opencase', 'Frist') }}</h4>
				<div class="process-props__field">
					<select v-model="form.dueMode" :disabled="readOnly" @change="updateDueDate">
						<option value="none">
							{{ t('opencase', 'Ingen frist') }}
						</option>
						<option value="duration">
							{{ t('opencase', 'Tid efter opgaven er oprettet') }}
						</option>
						<option value="expression">
							{{ t('opencase', 'Udtryk (avanceret)') }}
						</option>
					</select>
				</div>
				<div v-if="form.dueMode === 'duration'" class="process-props__row">
					<input v-model.number="form.dueAmount"
						type="number"
						min="1"
						:disabled="readOnly"
						@change="updateDueDate">
					<select v-model="form.dueUnit" :disabled="readOnly" @change="updateDueDate">
						<option value="D">
							{{ t('opencase', 'dage') }}
						</option>
						<option value="W">
							{{ t('opencase', 'uger') }}
						</option>
						<option value="M">
							{{ t('opencase', 'måneder') }}
						</option>
					</select>
				</div>
				<div v-if="form.dueMode === 'expression'" class="process-props__field">
					<input v-model="form.dueValue"
						type="text"
						:disabled="readOnly"
						placeholder="${frist}"
						@change="updateDueDate">
				</div>
				<p v-if="form.dueMode === 'duration'" class="process-props__hint">
					{{ t('opencase', 'Fristen regnes i kalenderdage.') }}
				</p>

				<h4>{{ t('opencase', 'Udfald') }}</h4>
				<p class="process-props__hint">
					{{ t('opencase', 'Valg sagsbehandleren skal træffe, når opgaven udføres. Valget gemmes i variablen udfald og kan bruges i betingelser efter en gateway.') }}
				</p>
				<ul v-if="form.outcomes.length" class="process-props__outcomes">
					<li v-for="(o, index) in form.outcomes" :key="o.id">
						<span>{{ o.name }}</span>
						<code>{{ o.id }}</code>
						<NcButton v-if="!readOnly"
							type="tertiary"
							:aria-label="t('opencase', 'Fjern')"
							@click="removeOutcome(index)">
							<template #icon>
								<CloseIcon :size="16" />
							</template>
						</NcButton>
					</li>
				</ul>
				<div v-if="!readOnly" class="process-props__row">
					<input v-model="newOutcome"
						type="text"
						:placeholder="t('opencase', 'F.eks. Godkend')"
						@keyup.enter="addOutcome">
					<NcButton :disabled="!newOutcome.trim()" @click="addOutcome">
						{{ t('opencase', 'Tilføj') }}
					</NcButton>
				</div>

				<h4>{{ t('opencase', 'Felter') }}</h4>
				<p class="process-props__hint">
					{{ t('opencase', 'Oplysninger sagsbehandleren udfylder, når opgaven udføres. Værdien gemmes i en variabel med feltets tekniske navn og kan bruges i betingelser, f.eks. for feltet Beløb:') }}
					<!-- Kept out of t(), which would escape ">" -->
					<code>{{ '${beloeb > 10000}' }}</code>
				</p>
				<ul v-if="form.fields.length" class="process-props__fields">
					<li v-for="(f, index) in form.fields" :key="f.id">
						<div class="process-props__field-head">
							<span>{{ f.name }}</span>
							<code>{{ f.id }}</code>
							<NcButton v-if="!readOnly"
								type="tertiary"
								:aria-label="t('opencase', 'Fjern')"
								@click="removeField(index)">
								<template #icon>
									<CloseIcon :size="16" />
								</template>
							</NcButton>
						</div>
						<div class="process-props__row">
							<select :value="f.type" :disabled="readOnly" @change="changeField(index, { type: $event.target.value })">
								<option v-for="ft in fieldTypes" :key="ft.id" :value="ft.id">
									{{ ft.label }}
								</option>
							</select>
							<NcCheckboxRadioSwitch :model-value="f.required"
								:disabled="readOnly || f.type === 'boolean'"
								@update:model-value="changeField(index, { required: $event })">
								{{ t('opencase', 'Påkrævet') }}
							</NcCheckboxRadioSwitch>
						</div>
					</li>
				</ul>
				<div v-if="!readOnly" class="process-props__row">
					<input v-model="newField"
						type="text"
						:placeholder="t('opencase', 'F.eks. Beløb')"
						@keyup.enter="addField">
					<NcButton :disabled="!newField.trim()" @click="addField">
						{{ t('opencase', 'Tilføj') }}
					</NcButton>
				</div>
			</template>

			<!-- Service task: automatic action run by OpenCase -->
			<template v-else-if="isType('bpmn:ServiceTask')">
				<h4>{{ t('opencase', 'Automatisk handling') }}</h4>
				<div class="process-props__field">
					<select v-model="form.serviceTopic" :disabled="readOnly" @change="updateServiceAction">
						<option value="">
							{{ t('opencase', 'Vælg handling') }}
						</option>
						<option v-for="topic in topics" :key="topic.topic" :value="topic.topic">
							{{ topic.label }}
						</option>
					</select>
				</div>
				<p v-if="!form.serviceTopic" class="process-props__warning">
					{{ t('opencase', 'Vælg en handling. Processen kan ikke udgives, før alle serviceopgaver har en.') }}
				</p>
				<template v-if="selectedTopic">
					<p class="process-props__hint">
						{{ selectedTopic.description }}
					</p>
					<template v-if="selectedTopic.outcomes && selectedTopic.outcomes.length">
						<h4>{{ t('opencase', 'Udfald') }}</h4>
						<p class="process-props__hint">
							{{ t('opencase', 'Gemmes i variablen udfald. Sæt en eksklusiv gateway efter trinnet og vælg pilene ud af den – så kan betingelsen indsættes med et klik.') }}
						</p>
						<ul class="process-props__outcomes">
							<li v-for="o in selectedTopic.outcomes" :key="o.id">
								<span>{{ o.name }}</span>
								<code>{{ o.id }}</code>
							</li>
						</ul>
					</template>
					<div v-for="param in visibleParams" :key="param.name" class="process-props__field">
						<label :for="'process-props-param-' + param.name">
							{{ param.label }}<template v-if="param.required">
								*
							</template>
						</label>
						<textarea v-if="param.type === 'textarea'"
							:id="'process-props-param-' + param.name"
							v-model="form.serviceFields[param.name]"
							rows="3"
							:disabled="readOnly"
							@change="updateServiceAction" />
						<NcSelect v-else-if="param.type === 'user'"
							:model-value="userParamOption(param.name)"
							:options="userOptions"
							:filterable="false"
							:loading="searchingUsers"
							:disabled="readOnly"
							:placeholder="t('opencase', 'Søg efter navn eller brugernavn')"
							@search="onUserSearch"
							@update:model-value="v => { form.serviceFields[param.name] = v?.id || ''; updateServiceAction() }" />
						<select v-else-if="param.type === 'choice'"
							:id="'process-props-param-' + param.name"
							:value="form.serviceFields[param.name] || param.default"
							:disabled="readOnly"
							@change="form.serviceFields[param.name] = $event.target.value; updateServiceAction()">
							<option v-for="o in param.options" :key="o.id" :value="o.id">
								{{ o.name }}
							</option>
						</select>
						<select v-else-if="param.type === 'boolean'"
							:id="'process-props-param-' + param.name"
							:value="form.serviceFields[param.name] || param.default || 'false'"
							:disabled="readOnly"
							@change="form.serviceFields[param.name] = $event.target.value; updateServiceAction()">
							<option value="true">
								{{ t('opencase', 'Ja') }}
							</option>
							<option value="false">
								{{ t('opencase', 'Nej') }}
							</option>
						</select>
						<select v-else-if="['template', 'document_category', 'casetype', 'process', 'custom_field', 'form_field', 'user_field'].includes(param.type)"
							:id="'process-props-param-' + param.name"
							v-model="form.serviceFields[param.name]"
							:disabled="readOnly"
							@change="updateServiceAction">
							<option value="">
								{{ param.required ? t('opencase', 'Vælg') : t('opencase', 'Ingen') }}
							</option>
							<option v-for="o in paramOptions(param)" :key="o.id" :value="String(o.id)">
								{{ o.name }}
							</option>
						</select>
						<input v-else
							:id="'process-props-param-' + param.name"
							v-model="form.serviceFields[param.name]"
							:type="param.type === 'number' ? 'number' : 'text'"
							:min="param.type === 'number' ? 1 : undefined"
							:step="param.type === 'number' ? 1 : undefined"
							:disabled="readOnly"
							@change="updateServiceAction">
					</div>
				</template>
				<p class="process-props__hint">
					{{ t('opencase', 'Handlingen udføres som sagens ansvarlige sagsbehandler (ellers den, der startede processen), som skal have skriveadgang til sagen.') }}
				</p>
			</template>

			<!-- Timer events: when they fire -->
			<template v-else-if="timerDefinition">
				<h4>{{ t('opencase', 'Timer') }}</h4>
				<div class="process-props__field">
					<select v-model="form.timer.mode" :disabled="readOnly" @change="updateTimer">
						<option value="duration">
							{{ t('opencase', 'Efter') }}
						</option>
						<option v-if="isBoundary" value="cycle">
							{{ t('opencase', 'Gentages hver') }}
						</option>
						<option value="date">
							{{ t('opencase', 'På et bestemt tidspunkt') }}
						</option>
						<option value="expression">
							{{ t('opencase', 'Udtryk (avanceret)') }}
						</option>
					</select>
				</div>
				<div v-if="form.timer.mode === 'duration' || form.timer.mode === 'cycle'" class="process-props__row">
					<input v-model.number="form.timer.amount"
						type="number"
						min="1"
						:disabled="readOnly"
						@change="updateTimer">
					<select v-model="form.timer.unit" :disabled="readOnly" @change="updateTimer">
						<option v-for="u in timerUnits" :key="u.id" :value="u.id">
							{{ u.label }}
						</option>
					</select>
				</div>
				<div v-else-if="form.timer.mode === 'date'" class="process-props__field">
					<input v-model="form.timer.date"
						type="datetime-local"
						:disabled="readOnly"
						@change="updateTimer">
				</div>
				<div v-else class="process-props__field">
					<input v-model="form.timer.value"
						type="text"
						:disabled="readOnly"
						placeholder="P14D"
						@change="updateTimer">
				</div>
				<p class="process-props__hint">
					{{ timerHint }}
				</p>
				<NcCheckboxRadioSwitch v-if="isBoundary"
					:model-value="form.cancelActivity"
					:disabled="readOnly"
					@update:model-value="updateCancelActivity">
					{{ t('opencase', 'Afbryder opgaven, når timeren udløses') }}
				</NcCheckboxRadioSwitch>
			</template>
			<p v-else-if="isTriggeredStart" class="process-props__warning">
				{{ t('opencase', 'Processer startes fra en sag, så starthændelsen skal være almindelig (uden timer, besked eller signal). Skift elementtype til Starthændelse.') }}
			</p>
			<!-- Sequence flow out of a gateway -->
			<template v-else-if="isGatewayFlow">
				<h4>{{ t('opencase', 'Betingelse') }}</h4>
				<div class="process-props__field">
					<input v-model="form.condition"
						type="text"
						:disabled="readOnly || form.isDefault"
						placeholder="${udfald == 'godkendt'}"
						@change="updateCondition">
				</div>
				<div v-if="gatewayOutcomes.length && !readOnly && !form.isDefault" class="process-props__quick">
					<span class="process-props__hint">{{ t('opencase', 'Udfald:') }}</span>
					<NcButton v-for="o in gatewayOutcomes"
						:key="o.id"
						type="tertiary"
						@click="useOutcome(o)">
						{{ o.name }}
					</NcButton>
				</div>
				<NcCheckboxRadioSwitch :model-value="form.isDefault"
					:disabled="readOnly"
					@update:model-value="updateDefault">
					{{ t('opencase', 'Standardvej (bruges når ingen betingelse er opfyldt)') }}
				</NcCheckboxRadioSwitch>
			</template>
		</template>
	</aside>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { is } from 'bpmn-js/lib/util/ModelUtil.js'
import api from '../services/api.js'
import {
	decodeAssignee,
	encodeAssignee,
	decodeCandidateOrgs,
	encodeCandidateOrgs,
	decodeDueDate,
	encodeDueDate,
	getOutcomes,
	buildOutcomeExtensions,
	outcomeIdFromLabel,
	getServiceAction,
	getTimerDefinition,
	decodeTimer,
	encodeTimer,
	TIMER_UNITS,
	getFormFields,
	getDiagramFormFields,
	buildFieldExtensions,
	fieldIdFromLabel,
	FIELD_TYPES,
	buildServiceActionProperties,
	OUTCOME_PROPERTY,
} from '../process/taskProperties.js'

const TYPE_LABELS = {
	'bpmn:UserTask': 'Brugeropgave',
	'bpmn:ServiceTask': 'Serviceopgave',
	'bpmn:Task': 'Opgave',
	'bpmn:StartEvent': 'Starthændelse',
	'bpmn:EndEvent': 'Sluthændelse',
	'bpmn:IntermediateCatchEvent': 'Mellemliggende hændelse',
	'bpmn:IntermediateThrowEvent': 'Mellemliggende hændelse',
	'bpmn:BoundaryEvent': 'Grænsehændelse',
	'bpmn:ExclusiveGateway': 'Eksklusiv gateway',
	'bpmn:ParallelGateway': 'Parallel gateway',
	'bpmn:InclusiveGateway': 'Inklusiv gateway',
	'bpmn:SequenceFlow': 'Sekvensflow',
	'bpmn:SubProcess': 'Underproces',
	'bpmn:Participant': 'Pool',
	'bpmn:Lane': 'Bane',
	'bpmn:TextAnnotation': 'Tekstnote',
}

/**
 * Properties of the element selected in the process designer. Changes go
 * straight to the diagram through bpmn-js' modeling API, so they are part
 * of undo/redo and are saved with the diagram.
 */
export default {
	name: 'ProcessPropertiesPanel',

	components: { NcButton, NcSelect, NcCheckboxRadioSwitch, CloseIcon },

	props: {
		// Selected diagram element (markRaw'ed by the parent), or null
		element: { type: Object, default: null },
		// Bumped by the parent whenever the element changes in the diagram
		revision: { type: Number, default: 0 },
		modeler: { type: Object, default: null },
		readOnly: { type: Boolean, default: false },
		orgUnits: { type: Array, default: () => [] },
		// Automatic actions for service tasks (GET /admin/process-topics)
		topics: { type: Array, default: () => [] },
		templates: { type: Array, default: () => [] },
		documentCategories: { type: Array, default: () => [] },
		caseTypes: { type: Array, default: () => [] },
		// Other processes, for "Opret undersag" ({id, name})
		processes: { type: Array, default: () => [] },
		// Custom field definitions, for "Udfyld tillægsfelt" ({field_key, label})
		customFields: { type: Array, default: () => [] },
		currentProcessId: { type: Number, default: null },
	},

	data() {
		return {
			form: this.emptyForm(),
			newOutcome: '',
			newField: '',
			timerUnits: TIMER_UNITS.map(u => ({ ...u, label: t('opencase', u.label) })),
			fieldTypes: FIELD_TYPES.map(ft => ({ ...ft, label: t('opencase', ft.label) })),
			userOptions: [],
			searchingUsers: false,
			searchTimer: null,
		}
	},

	computed: {
		typeLabel() {
			const type = this.element?.businessObject?.$type
			return t('opencase', TYPE_LABELS[type] || type?.replace('bpmn:', '') || '')
		},
		hasName() {
			return this.element && !is(this.element, 'bpmn:Process') && this.element.type !== 'label'
		},
		isGatewayFlow() {
			return this.isType('bpmn:SequenceFlow')
				&& (is(this.element.source, 'bpmn:ExclusiveGateway') || is(this.element.source, 'bpmn:InclusiveGateway'))
		},
		/**
		 * Outcomes of the user tasks, and of automatic actions with outcomes
		 * (Send til godkendelse), leading into the gateway this flow leaves,
		 * offered as ready-made conditions.
		 */
		gatewayOutcomes() {
			if (!this.isGatewayFlow) return []
			const outcomes = []
			for (const incoming of this.element.source.incoming || []) {
				let found = []
				if (is(incoming.source, 'bpmn:UserTask')) {
					found = getOutcomes(incoming.source.businessObject)
				} else if (is(incoming.source, 'bpmn:ServiceTask')) {
					const topic = getServiceAction(incoming.source.businessObject).topic
					found = this.topics.find(tp => tp.topic === topic)?.outcomes || []
				}
				for (const o of found) {
					if (!outcomes.some(x => x.id === o.id)) outcomes.push(o)
				}
			}
			return outcomes
		},
		timerDefinition() {
			if (!this.element || is(this.element, 'bpmn:StartEvent')) return null
			return getTimerDefinition(this.element.businessObject)
		},
		isBoundary() {
			return this.isType('bpmn:BoundaryEvent')
		},
		isTriggeredStart() {
			return this.isType('bpmn:StartEvent')
				&& (this.element.businessObject.eventDefinitions || []).length > 0
				&& is(this.element.businessObject.$parent, 'bpmn:Process')
		},
		timerHint() {
			if (this.form.timer.mode === 'cycle') {
				return t('opencase', 'Udløses igen og igen med det interval, så længe opgaven er åben – f.eks. en påmindelse hver 3. dag. Brug det med en timer, der ikke afbryder opgaven.')
			}
			if (this.isBoundary) {
				return t('opencase', 'Regnes fra opgaven blev oprettet. En afbrydende timer lukker opgaven og fortsætter ad timerens pil; en ikke-afbrydende lader opgaven være åben.')
			}
			return t('opencase', 'Processen venter her, til timeren udløses.')
		},
		selectedTopic() {
			return this.topics.find(t => t.topic === this.form.serviceTopic) || null
		},
		/**
		 * The chosen action's parameters that apply, given the values of the
		 * others ("when" in ProcessTopics).
		 */
		visibleParams() {
			const params = this.selectedTopic?.params || []
			return params.filter(param => Object.entries(param.when || {}).every(([other, value]) => {
				const otherParam = params.find(p => p.name === other)
				return (this.form.serviceFields[other] || otherParam?.default) === value
			}))
		},
		orgOptions() {
			return this.orgUnits.map(o => ({ id: o.org_uuid, label: o.org_name }))
		},
		candidateOrgOptions: {
			get() {
				return this.form.candidateOrgs.map(uuid => this.orgOptions.find(o => o.id === uuid) || { id: uuid, label: uuid })
			},
			set(options) {
				this.form.candidateOrgs = (options || []).map(o => o.id)
				const bo = this.element.businessObject
				this.update(this.element, { 'flowable:candidateGroups': encodeCandidateOrgs(bo.get('flowable:candidateGroups'), this.form.candidateOrgs) })
			},
		},
		userOption: {
			get() {
				if (!this.form.assigneeValue) return null
				return this.userOptions.find(o => o.id === this.form.assigneeValue)
					|| { id: this.form.assigneeValue, label: this.form.assigneeValue }
			},
			set(option) {
				this.form.assigneeValue = option?.id || ''
				this.updateAssignee()
			},
		},
	},

	watch: {
		element() {
			this.load()
		},
		revision() {
			this.load()
		},
	},

	created() {
		this.load()
	},

	methods: {
		emptyForm() {
			return {
				name: '',
				assigneeMode: 'none',
				assigneeValue: '',
				candidateOrgs: [],
				dueMode: 'none',
				dueAmount: 5,
				dueUnit: 'D',
				dueValue: '',
				condition: '',
				isDefault: false,
				outcomes: [],
				fields: [],
				timer: { mode: 'duration', amount: 14, unit: 'days', date: '', value: '' },
				cancelActivity: true,
				serviceTopic: '',
				serviceFields: {},
			}
		},
		isType(type) {
			return !!this.element && is(this.element, type)
		},
		load() {
			const form = this.emptyForm()
			const bo = this.element?.businessObject
			if (bo) {
				form.name = bo.name || ''
				if (is(this.element, 'bpmn:UserTask')) {
					const assignee = decodeAssignee(bo.get('flowable:assignee'))
					form.assigneeMode = assignee.mode
					form.assigneeValue = assignee.value
					form.candidateOrgs = decodeCandidateOrgs(bo.get('flowable:candidateGroups'))
					const due = decodeDueDate(bo.get('flowable:dueDate'))
					form.dueMode = due.mode
					form.dueAmount = due.amount
					form.dueUnit = due.unit
					form.dueValue = due.value
					form.outcomes = getOutcomes(bo)
					form.fields = getFormFields(bo)
				}
				const timer = getTimerDefinition(bo)
				if (timer) {
					form.timer = decodeTimer(timer)
					form.cancelActivity = bo.cancelActivity !== false
				}
				if (is(this.element, 'bpmn:ServiceTask')) {
					const action = getServiceAction(bo)
					form.serviceTopic = action.topic
					form.serviceFields = action.fields
				}
				if (is(this.element, 'bpmn:SequenceFlow')) {
					form.condition = bo.conditionExpression?.body || ''
					form.isDefault = this.element.source?.businessObject?.default === bo
				}
			}
			this.form = form
		},
		update(element, properties) {
			if (this.readOnly || !this.modeler) return
			this.modeler.get('modeling').updateProperties(element, properties)
		},
		updateName() {
			if (this.readOnly || !this.modeler) return
			this.modeler.get('modeling').updateLabel(this.element, this.form.name.trim() || null)
		},
		updateAssignee() {
			this.update(this.element, { 'flowable:assignee': encodeAssignee(this.form.assigneeMode, this.form.assigneeValue) })
		},
		userParamOption(name) {
			const uid = this.form.serviceFields[name]
			if (!uid) return null
			return this.userOptions.find(o => o.id === uid) || { id: uid, label: uid }
		},
		paramOptions(param) {
			return {
				template: this.templates,
				document_category: this.documentCategories,
				casetype: this.caseTypes,
				// Not the process itself: it would start on sub-cases without end
				process: this.processes.filter(p => p.id !== this.currentProcessId),
				custom_field: this.customFields.map(f => ({ id: f.field_key, name: f.label })),
				form_field: this.diagramFieldOptions(param.name, false),
				user_field: this.diagramFieldOptions(param.name, true),
			}[param.type] || []
		},
		/**
		 * Form fields of the diagram's user tasks, as "Field (Task)". A stored
		 * field that no longer exists stays listed so it isn't lost silently.
		 *
		 * @param {string} paramName the parameter holding the field id
		 * @param {boolean} usersOnly only "Medarbejder" fields
		 */
		diagramFieldOptions(paramName, usersOnly) {
			if (!this.modeler) return []
			const options = getDiagramFormFields(this.modeler.get('elementRegistry'))
				.filter(f => !usersOnly || f.type === 'user')
				.map(f => ({ id: f.id, name: `${f.name} (${f.task})` }))
			const stored = this.form.serviceFields[paramName]
			if (stored && !options.some(o => o.id === stored)) {
				options.push({ id: stored, name: t('opencase', '{field} (findes ikke)', { field: stored }) })
			}
			return options
		},
		updateServiceAction() {
			// Keep only the parameters of the chosen action
			const names = (this.selectedTopic?.params || []).map(p => p.name)
			const fields = Object.fromEntries(Object.entries(this.form.serviceFields).filter(([name]) => names.includes(name)))
			this.update(this.element, buildServiceActionProperties(
				this.modeler.get('moddle'), this.element.businessObject, this.form.serviceTopic, fields,
			))
		},
		addOutcome() {
			const name = this.newOutcome.trim()
			if (!name) return
			const id = outcomeIdFromLabel(name)
			if (this.form.outcomes.some(o => o.id === id)) {
				this.newOutcome = ''
				return
			}
			this.saveOutcomes([...this.form.outcomes, { id, name }])
			this.newOutcome = ''
		},
		removeOutcome(index) {
			this.saveOutcomes(this.form.outcomes.filter((_, i) => i !== index))
		},
		updateTimer() {
			const values = encodeTimer(this.form.timer)
			const moddle = this.modeler.get('moddle')
			const expr = v => v ? moddle.create('bpmn:FormalExpression', { body: v }) : undefined
			if (this.readOnly || !this.modeler) return
			this.modeler.get('modeling').updateModdleProperties(this.element, this.timerDefinition, {
				timeDuration: expr(values.timeDuration),
				timeCycle: expr(values.timeCycle),
				timeDate: expr(values.timeDate),
			})
		},
		updateCancelActivity(value) {
			this.form.cancelActivity = value
			this.update(this.element, { cancelActivity: value })
		},
		addField() {
			const name = this.newField.trim()
			if (!name) return
			const id = fieldIdFromLabel(name, this.form.fields.map(f => f.id))
			this.saveFields([...this.form.fields, { id, name, type: 'string', required: false }])
			this.newField = ''
		},
		removeField(index) {
			this.saveFields(this.form.fields.filter((_, i) => i !== index))
		},
		changeField(index, changes) {
			const fields = this.form.fields.map((f, i) => i === index ? { ...f, ...changes } : f)
			if (fields[index].type === 'boolean') {
				// A yes/no field always has a value
				fields[index].required = false
			}
			this.saveFields(fields)
		},
		saveFields(fields) {
			this.form.fields = fields
			this.update(this.element, {
				extensionElements: buildFieldExtensions(this.modeler.get('moddle'), this.element.businessObject, fields),
			})
		},
		saveOutcomes(outcomes) {
			this.form.outcomes = outcomes
			const moddle = this.modeler.get('moddle')
			this.update(this.element, {
				extensionElements: buildOutcomeExtensions(moddle, this.element.businessObject, outcomes),
			})
		},
		useOutcome(outcome) {
			this.form.condition = '${' + OUTCOME_PROPERTY + " == '" + outcome.id + "'}"
			this.updateCondition()
		},
		updateDueDate() {
			this.update(this.element, {
				'flowable:dueDate': encodeDueDate(this.form.dueMode, this.form.dueAmount, this.form.dueUnit, this.form.dueValue),
			})
		},
		updateCondition() {
			const body = this.form.condition.trim()
			const moddle = this.modeler.get('moddle')
			this.update(this.element, {
				conditionExpression: body ? moddle.create('bpmn:FormalExpression', { body }) : undefined,
			})
		},
		updateDefault(isDefault) {
			const source = this.element.source
			if (isDefault) {
				// A default flow has no condition
				this.update(this.element, { conditionExpression: undefined })
				this.update(source, { default: this.element.businessObject })
			} else if (source.businessObject.default === this.element.businessObject) {
				this.update(source, { default: undefined })
			}
		},
		onUserSearch(query) {
			clearTimeout(this.searchTimer)
			if (!query || query.trim().length < 2) return
			this.searchTimer = setTimeout(async () => {
				this.searchingUsers = true
				try {
					const employees = await api.searchEmployees({ q: query.trim() })
					this.userOptions = employees
						.filter(e => e.user_id)
						.map(e => ({ id: e.user_id, label: `${e.personname || e.username} (${e.user_id})` }))
				} catch (e) {
					this.userOptions = []
				} finally {
					this.searchingUsers = false
				}
			}, 300)
		},
	},
}
</script>

<style scoped>
.process-props {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px;
	overflow-y: auto;
}

.process-props h3 {
	font-size: 1.1em;
	font-weight: 600;
	margin: 0;
}

.process-props h4 {
	font-size: 0.95em;
	font-weight: 600;
	margin: 8px 0 0;
}

.process-props__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.process-props__field label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.process-props__field input,
.process-props__field select {
	width: 100%;
	box-sizing: border-box;
}

.process-props__row {
	display: flex;
	gap: 8px;
}

.process-props__row input {
	width: 80px;
}

.process-props__hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.process-props__outcomes {
	margin: 0;
	padding: 0;
	list-style: none;
}

.process-props__outcomes li {
	display: flex;
	align-items: center;
	gap: 8px;
}

.process-props__outcomes li span {
	flex: 1;
}

.process-props__outcomes code {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.process-props__row input[type='text'] {
	flex: 1;
	width: auto;
}

.process-props__fields {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.process-props__field-head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.process-props__field-head span {
	flex: 1;
	font-weight: 600;
}

.process-props__field-head code {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.process-props__quick {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
}

.process-props__warning {
	font-size: 0.9em;
	margin: 0;
	padding: 8px;
	border-left: 3px solid var(--color-warning);
	background: var(--color-background-hover);
}
</style>
