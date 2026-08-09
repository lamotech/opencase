const webpackConfig = require('@nextcloud/webpack-vue-config')
const TerserPlugin = require('terser-webpack-plugin')
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

// Limit Terser to a single worker so minification doesn't OOM on low-RAM servers.
// The default (cpu_count - 1 workers) exhausts ~2 GB RAM on 4-core machines.
// Full 'source-map' devtool also roughly doubles Terser's peak memory (it has to
// remap output positions back to source); drop to a lighter map in production too.
webpackConfig.devtool = process.env.NODE_ENV === 'development' ? webpackConfig.devtool : 'nosources-source-map'
webpackConfig.optimization = {
	...webpackConfig.optimization,
	minimizer: [new TerserPlugin({ parallel: 1 })],
}

module.exports = webpackConfig
