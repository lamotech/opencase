<template>
	<button class="favorite-btn"
		:class="{ 'favorite-btn--active': isFavorite }"
		:title="isFavorite ? t('opencase', 'Fjern fra favoritter') : t('opencase', 'Tilføj til favoritter')"
		:aria-pressed="isFavorite"
		:disabled="busy"
		@click.stop="toggle">
		<StarIcon v-if="isFavorite" :size="22" />
		<StarOutlineIcon v-else :size="22" />
	</button>
</template>

<script>
import StarIcon from 'vue-material-design-icons/Star.vue'
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'FavoriteButton',

	components: { StarIcon, StarOutlineIcon },

	props: {
		entity: {
			type: String,
			required: true,
			validator: v => ['case', 'document'].includes(v),
		},
		entityKey: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		return { busy: false }
	},

	computed: {
		isFavorite() {
			const key = Number(this.entityKey)
			return this.$store.state.favorites.some(
				f => f.entity === this.entity && f.key === key
			)
		},
	},

	methods: {
		async toggle() {
			if (this.busy) return
			this.busy = true
			const key = Number(this.entityKey)
			try {
				if (this.isFavorite) {
					await this.$store.dispatch('removeFavorite', { entity: this.entity, key })
				} else {
					await this.$store.dispatch('addFavorite', { entity: this.entity, key })
				}
			} catch (e) {
				showError(t('opencase', 'Kunne ikke opdatere favoritter'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.favorite-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border: none;
	background: none;
	border-radius: 50%;
	cursor: pointer;
	color: var(--color-text-lighter);
	transition: color 0.15s, background 0.15s;
	flex-shrink: 0;
	padding: 0;
}

.favorite-btn:hover:not(:disabled) {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.favorite-btn--active {
	color: #f6a623;
}

.favorite-btn--active:hover:not(:disabled) {
	color: #d4881a;
}

.favorite-btn:disabled {
	opacity: 0.5;
	cursor: default;
}
</style>
