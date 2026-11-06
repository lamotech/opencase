<template>
	<div class="process-diagram">
		<div ref="canvas" class="process-diagram__canvas" />
		<div class="process-diagram__legend">
			<span class="process-diagram__legend-item process-diagram__legend-item--active">{{ t('opencase', 'Aktiv') }}</span>
			<span class="process-diagram__legend-item process-diagram__legend-item--done">{{ t('opencase', 'Gennemført') }}</span>
		</div>
	</div>
</template>

<script>
import { markRaw } from 'vue'
import NavigatedViewer from 'bpmn-js/lib/NavigatedViewer.js'
import 'bpmn-js/dist/assets/diagram-js.css'
import 'bpmn-js/dist/assets/bpmn-js.css'
import 'bpmn-js/dist/assets/bpmn-font/css/bpmn-embedded.css'
import flowableModdle from '../process/flowableModdle.json'

/**
 * Read-only BPMN diagram of a process on a case, with the elements the
 * process is at and has passed highlighted.
 */
export default {
	name: 'ProcessDiagramViewer',

	props: {
		xml: { type: String, required: true },
		activeActivities: { type: Array, default: () => [] },
		completedActivities: { type: Array, default: () => [] },
	},

	data() {
		return {
			viewer: null,
		}
	},

	watch: {
		xml() {
			this.render()
		},
		activeActivities() {
			this.applyMarkers()
		},
		completedActivities() {
			this.applyMarkers()
		},
	},

	mounted() {
		this.viewer = markRaw(new NavigatedViewer({
			container: this.$refs.canvas,
			moddleExtensions: { flowable: flowableModdle },
		}))
		this.render()
	},

	beforeUnmount() {
		this.viewer?.destroy()
	},

	methods: {
		async render() {
			try {
				await this.viewer.importXML(this.xml)
				this.viewer.get('canvas').zoom('fit-viewport', 'auto')
				this.applyMarkers()
			} catch (e) {
				console.error('Could not render process diagram', e)
			}
		},
		applyMarkers() {
			const canvas = this.viewer.get('canvas')
			const registry = this.viewer.get('elementRegistry')
			registry.forEach(element => {
				canvas.removeMarker(element, 'oc-process-active')
				canvas.removeMarker(element, 'oc-process-done')
			})
			for (const id of this.completedActivities) {
				if (registry.get(id)) canvas.addMarker(id, 'oc-process-done')
			}
			for (const id of this.activeActivities) {
				if (registry.get(id)) canvas.addMarker(id, 'oc-process-active')
			}
		},
	},
}
</script>

<style scoped>
.process-diagram {
	position: relative;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.process-diagram__canvas {
	height: 320px;
	/* bpmn-js draws dark lines; keep the canvas light also in dark mode */
	background: #fff;
	color: #000;
}

.process-diagram__legend {
	position: absolute;
	top: 8px;
	left: 8px;
	display: flex;
	gap: 12px;
	font-size: 0.85em;
	color: #333;
}

.process-diagram__legend-item::before {
	content: '';
	display: inline-block;
	width: 10px;
	height: 10px;
	margin-right: 4px;
	border-radius: 2px;
	vertical-align: middle;
}

.process-diagram__legend-item--active::before {
	background: #ffe082;
	border: 2px solid #f57c00;
}

.process-diagram__legend-item--done::before {
	background: #c8e6c9;
	border: 2px solid #2e7d32;
}
</style>

<style>
/* Markers are set on elements rendered by bpmn-js, outside this component's scope */
.process-diagram .oc-process-active:not(.djs-connection) .djs-visual > :nth-child(1) {
	fill: #ffe082 !important;
	stroke: #f57c00 !important;
	stroke-width: 3px !important;
}

.process-diagram .oc-process-done:not(.djs-connection) .djs-visual > :nth-child(1) {
	fill: #c8e6c9 !important;
	stroke: #2e7d32 !important;
}

.process-diagram .oc-process-done.djs-connection .djs-visual > path {
	stroke: #2e7d32 !important;
	stroke-width: 2.5px !important;
}
</style>
