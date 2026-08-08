// @nextcloud/eslint-config's default vue ruleset extends `plugin:vue/recommended`,
// which eslint-plugin-vue resolves to its Vue 2 rules. Since `extends` nested inside
// `overrides` doesn't win the cascade over the package's own override, pull in the
// vue3 rule sets directly and apply them as plain rules so they take precedence.
const vue3Rules = Object.assign(
	{},
	require('eslint-plugin-vue/lib/configs/vue3-essential.js').rules,
	require('eslint-plugin-vue/lib/configs/vue3-strongly-recommended.js').rules,
	require('eslint-plugin-vue/lib/configs/vue3-recommended.js').rules,
)

module.exports = {
	extends: [
		'@nextcloud',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
	overrides: [
		{
			files: ['**/*.vue'],
			rules: {
				...vue3Rules,
				// Vue 2-only rules carried over from @nextcloud/eslint-config that
				// conflict with valid Vue 3 syntax (v-model:arg, multi-root templates, etc).
				'vue/no-custom-modifiers-on-v-model': 'off',
				'vue/no-multiple-template-root': 'off',
				'vue/no-v-for-template-key': 'off',
				'vue/no-v-model-argument': 'off',
				'vue/valid-model-definition': 'off',
				'vue/valid-v-bind-sync': 'off',
			},
		},
	],
}
