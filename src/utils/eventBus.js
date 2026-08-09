/**
 * Minimal pub/sub event bus.
 *
 * Vue 3 dropped the built-in `$on`/`$off`/`$emit` instance API that this app
 * previously used via `this.$root` for cross-component AI-side-panel
 * notifications (e.g. "contacts changed", "case moved"). This is a drop-in
 * replacement with the same on/off/emit shape.
 */
const listeners = new Map()

export default {
	on(event, handler) {
		if (!listeners.has(event)) listeners.set(event, new Set())
		listeners.get(event).add(handler)
	},

	off(event, handler) {
		listeners.get(event)?.delete(handler)
	},

	emit(event, ...args) {
		listeners.get(event)?.forEach(handler => handler(...args))
	},
}
