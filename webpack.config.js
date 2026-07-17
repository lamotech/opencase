const webpackConfig = require('@nextcloud/webpack-vue-config')
const path = require('path')

// Fix resolve.extensions if needed (workaround for wildcard bug)
if (webpackConfig.resolve && webpackConfig.resolve.extensions) {
	webpackConfig.resolve.extensions = webpackConfig.resolve.extensions.map(
		ext => ext === '*' ? '.*' : ext,
	)
}

// Talk integration plugin — separate entry so it is injected only on Talk pages.
// Produces js/opencase-talk.js via the default ${appName}-[name].js output template.
webpackConfig.entry.talk = { import: path.join(__dirname, 'src', 'talk-plugin.js') }

// Files integration plugin — separate entry so it is injected only on Files pages.
// Produces js/opencase-files.js.
webpackConfig.entry.files = { import: path.join(__dirname, 'src', 'files-plugin.js') }

// Admin settings page — produces js/opencase-admin-settings.js.
webpackConfig.entry['admin-settings'] = { import: path.join(__dirname, 'src', 'admin-settings.js') }

// Dashboard widget — produces js/opencase-widget.js.
webpackConfig.entry['widget'] = { import: path.join(__dirname, 'src', 'widget.js') }

module.exports = webpackConfig
