<template>
	<div ref="wrap" class="oct">
		<!-- Hidden strip used only for measuring natural tab widths -->
		<div ref="measure" class="oct__measure" aria-hidden="true">
			<button v-for="tab in tabs" :key="'m' + tab.id" class="oct__tab">
				<span class="oct__tab-top">
					<component :is="tab.icon" v-if="tab.icon" :size="20" class="oct__tab-icon" />
					<span v-if="tab.count != null" class="oct__count">{{ tab.count }}</span>
				</span>
				<span class="oct__tab-label">{{ tab.label }}</span>
			</button>
			<button class="oct__tab oct__more-btn">{{ t('opencase', 'Mere') }} ▾</button>
		</div>

		<!-- Visible tabs -->
		<button
			v-for="tab in visibleTabs"
			:key="tab.id"
			class="oct__tab"
			:class="{ 'oct__tab--active': value === tab.id }"
			@click="$emit('input', tab.id)">
			<span class="oct__tab-top">
				<component :is="tab.icon" v-if="tab.icon" :size="20" class="oct__tab-icon" />
				<span v-if="tab.count != null" class="oct__count">{{ tab.count }}</span>
			</span>
			<span class="oct__tab-label">{{ tab.label }}</span>
		</button>

		<!-- "More" overflow button + dropdown -->
		<div v-if="overflowTabs.length" ref="more" class="oct__overflow">
			<button
				ref="moreBtn"
				class="oct__tab"
				:class="isActiveInOverflow ? 'oct__tab--active' : 'oct__more-btn'"
				@click="toggleMenu">
				<template v-if="isActiveInOverflow">
					<span class="oct__tab-top">
						<component :is="activeOverflowTab.icon" v-if="activeOverflowTab && activeOverflowTab.icon" :size="20" class="oct__tab-icon" />
						<span v-if="activeOverflowTab && activeOverflowTab.count != null" class="oct__count">{{ activeOverflowTab.count }}</span>
					</span>
					<span class="oct__tab-label">{{ activeOverflowTab.label }}</span>
				</template>
				<template v-else>
					<span class="oct__tab-label">{{ t('opencase', 'Mere') }}</span>
					▾
				</template>
			</button>
			<div v-if="menuOpen" class="oct__dropdown" :style="dropdownStyle">
				<button
					v-for="tab in overflowTabs"
					:key="'o' + tab.id"
					class="oct__dropdown-item"
					:class="{ 'oct__dropdown-item--active': value === tab.id }"
					@click="select(tab.id)">
					<component :is="tab.icon" v-if="tab.icon" :size="18" />
					{{ tab.label }}
					<span v-if="tab.count != null" class="oct__count">{{ tab.count }}</span>
				</button>
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'OverflowTabs',

	props: {
		tabs: { type: Array, required: true },
		value: { type: String, required: true },
	},

	data() {
		return {
			visibleCount: this.tabs.length,
			menuOpen: false,
			dropdownStyle: {},
		}
	},

	computed: {
		visibleTabs() {
			return this.tabs.slice(0, this.visibleCount)
		},
		overflowTabs() {
			return this.tabs.slice(this.visibleCount)
		},
		isActiveInOverflow() {
			return this.overflowTabs.some(t => t.id === this.value)
		},
		activeOverflowTab() {
			return this.overflowTabs.find(t => t.id === this.value)
		},
	},

	watch: {
		tabs() {
			this.$nextTick(this.recalculate)
		},
	},

	mounted() {
		this.$nextTick(this.recalculate)
		this._ro = new ResizeObserver(this.recalculate)
		this._ro.observe(this.$refs.wrap.parentElement)
		document.addEventListener('click', this.onClickOutside, true)
	},

	beforeUnmount() {
		this._ro && this._ro.disconnect()
		document.removeEventListener('click', this.onClickOutside, true)
	},

	methods: {
		recalculate() {
			const wrap = this.$refs.wrap
			const measure = this.$refs.measure
			if (!wrap || !measure || !wrap.parentElement) return

			// Measure the parent's width, not wrap's own — wrap is an inline-flex
			// box that shrinks to fit its currently visible tabs, so reading its
			// own offsetWidth here would create a feedback loop: fewer visible
			// tabs shrinks wrap, which the ResizeObserver sees and reacts to by
			// showing even fewer tabs, spiraling down to a single tab.
			//
			// clientWidth (not offsetWidth) excludes the parent's border, and we
			// then subtract its padding too — children are laid out in the
			// content box only, so any padding on the parent (e.g. the 20px page
			// padding on CaseDetailView) is not usable space for wrap.
			const parent = wrap.parentElement
			const parentStyle = getComputedStyle(parent)
			const parentPadding = parseFloat(parentStyle.paddingLeft) + parseFloat(parentStyle.paddingRight)
			const containerWidth = parent.clientWidth - parentPadding

			// wrap (.oct) has its own padding/border and a flex gap between
			// items — all invisible to offsetWidth comparisons below. Without
			// subtracting them, "fits" is computed a few px too generously and
			// the last item (often the more button) gets clipped by .oct's
			// overflow:hidden edge.
			const wrapStyle = getComputedStyle(wrap)
			const chrome = parseFloat(wrapStyle.paddingLeft) + parseFloat(wrapStyle.paddingRight)
				+ parseFloat(wrapStyle.borderLeftWidth) + parseFloat(wrapStyle.borderRightWidth)
			const gap = parseFloat(wrapStyle.columnGap) || 0
			const availableWidth = containerWidth - chrome

			const measureBtns = Array.from(measure.querySelectorAll('.oct__tab:not(.oct__more-btn)'))
			const moreBtn = measure.querySelector('.oct__more-btn')

			const tabWidths = measureBtns.map(b => b.offsetWidth)
			const moreBtnWidth = moreBtn ? moreBtn.offsetWidth : 0
			const totalWidth = tabWidths.reduce((a, b) => a + b, 0) + gap * Math.max(tabWidths.length - 1, 0)

			if (totalWidth <= availableWidth) {
				this.visibleCount = this.tabs.length
				return
			}

			let sum = moreBtnWidth
			let count = 0
			for (const w of tabWidths) {
				const next = sum + gap + w
				if (next <= availableWidth) {
					sum = next
					count++
				} else {
					break
				}
			}
			this.visibleCount = count
		},

		toggleMenu() {
			this.menuOpen = !this.menuOpen
			if (this.menuOpen) {
				this.$nextTick(this.positionDropdown)
			}
		},

		positionDropdown() {
			const btn = this.$refs.moreBtn
			if (!btn) return
			const rect = btn.getBoundingClientRect()
			this.dropdownStyle = {
				position: 'fixed',
				top: (rect.bottom + 4) + 'px',
				right: (window.innerWidth - rect.right) + 'px',
				zIndex: 9999,
			}
		},

		select(id) {
			this.$emit('input', id)
			this.menuOpen = false
		},

		onClickOutside(e) {
			if (this.$refs.more && !this.$refs.more.contains(e.target)) {
				this.menuOpen = false
			}
		},
	},
}
</script>

<style scoped>
.oct {
	position: relative;
	display: inline-flex;
	align-items: center;
	gap: 2px;
	border: 1px solid var(--color-border);
	border-radius: 50px;
	padding: 4px;
	background: var(--color-main-background);
	margin-bottom: 20px;
	max-width: 100%;
	box-sizing: border-box;
	overflow: hidden;
}

/* Hidden measurement strip */
.oct__measure {
	position: absolute;
	top: 0;
	left: 0;
	visibility: hidden;
	pointer-events: none;
	display: flex;
	white-space: nowrap;
}

.oct__tab {
	background: none;
	border: none;
	padding: 8px 16px;
	cursor: pointer;
	font-size: 0.88em;
	color: var(--color-main-text);
	border-radius: 44px;
	transition: background 0.15s, color 0.15s;
	white-space: nowrap;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
	line-height: 1.2;
}

.oct__tab:hover {
	background: var(--color-background-hover);
}

.oct__tab--active {
	background: var(--color-primary-element-light, rgba(0, 100, 220, 0.1));
	color: var(--color-primary-element);
}

.oct__tab--active .oct__tab-icon {
	color: var(--color-primary-element);
}

.oct__tab-top {
	display: flex;
	align-items: center;
	gap: 4px;
}

.oct__tab-icon {
	color: var(--color-main-text);
	display: flex;
}

.oct__tab-label {
	font-weight: 500;
}

.oct__tab--active .oct__tab-label {
	font-weight: 700;
}

.oct__count {
	background: var(--color-background-dark);
	padding: 1px 6px;
	border-radius: 10px;
	font-size: 0.8em;
	align-self: center;
}

/* "More" button */
.oct__overflow {
	position: relative;
	flex-shrink: 0;
}

.oct__more-btn {
	flex-direction: row;
	gap: 4px;
}

/* Dropdown — position set via JS (fixed) to escape overflow:hidden pill */
.oct__dropdown {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 10px;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
	min-width: 180px;
	padding: 4px 0;
}

.oct__dropdown-item {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 8px 16px;
	background: none;
	border: none;
	cursor: pointer;
	font-size: 0.95em;
	color: var(--color-main-text);
	text-align: left;
	white-space: nowrap;
}

.oct__dropdown-item:hover {
	background: var(--color-background-hover);
}

.oct__dropdown-item--active {
	color: var(--color-primary-element);
	font-weight: 600;
}
</style>
