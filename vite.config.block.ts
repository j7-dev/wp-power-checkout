import react from '@vitejs/plugin-react'
import tsconfigPaths from 'vite-tsconfig-paths'
import alias from '@rollup/plugin-alias'
import path from 'path'
import { defineConfig } from 'vite'
import optimizer from 'vite-plugin-optimizer'
import { terser } from 'rollup-plugin-terser'
import { glob } from 'glob'
// import liveReload from 'vite-plugin-live-reload'


export default defineConfig({
	server: {
		port: 5181,
		cors: {
			origin: '*',
		},
		fs: {
			allow: ['./', '../../packages'],
		},
	},
	build: {
		emptyOutDir: true,
		minify: true,
		outDir: path.resolve(__dirname, 'inc/assets/dist/blocks'),
		watch: {
		  include: 'inc/assets/blocks/*.tsx',
		},
		rollupOptions: {
			input: Object.fromEntries(
				glob.sync('inc/assets/blocks/*.tsx').map(file => [
					// 取得檔名（不含副檔名）作為入口點名稱
					path.basename(file, '.tsx'),
					// 完整路徑作為入口點
					path.resolve(__dirname, file)
				])
			),
			output: {
				assetFileNames: '[ext]/[name].[ext]',
				entryFileNames: '[name].js', // 保持原檔名，但副檔名改為 .js
			},
		},
	},
	plugins: [
		alias(),
		react(),
		tsconfigPaths(),

		// liveReload(__dirname + '/**/*.php'), // Optional, if you want to reload page on php changed

		optimizer({
			jquery: 'const $ = window.jQuery; export { $ as default }',
			"@woocommerce/blocks-registry": 'const { registerPaymentMethod } = window.wc.wcBlocksRegistry; export { registerPaymentMethod }; export default window.wc.wcBlocksRegistry',
			"@woocommerce/settings": 'const { getSetting } = window.wc.wcSettings; export { getSetting }; export default window.wc.wcSettings',
			"@wordpress/element": 'const { createElement } = window.wp.element; export { createElement }; export default window.wp.element',
			"@wordpress/html-entities": 'const { decodeEntities } = window.wp.htmlEntities; export { decodeEntities }; export default window.wp.htmlEntities',
			"@wordpress/i18n": 'const { __ } = window.wp.i18n; export { __ }; export default window.wp.i18n',
		}),
		terser({
			mangle: {
				reserved: ['$'], // 指定 $ 不被改變
			},
		}),
	],

	// build: {
	// 	rollupOptions: {
	// 		output: {
	// 			// 修改入口檔案名稱
	// 			entryFileNames: 'index.js',

	// 			// 修改代碼分割後的檔案名稱
	// 			chunkFileNames: '[name]-[hash].js',

	// 			// 修改資源檔案名稱
	// 			assetFileNames: '[name]-[hash].[ext]',
	// 		},
	// 	},
	// },
	resolve: {
		extensions: ['.jsx', '.js', '.ts', '.tsx'],
		alias: {
			'lodash-es': 'lodash'
		},
	},
})
