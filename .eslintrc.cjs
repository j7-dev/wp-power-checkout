/**
 * eslint-plugin-vue@10 ships its legacy (eslintrc) presets as ESM-wrapped CJS
 * modules whose real config sits behind a `default` getter, and whose `extends`
 * point at sibling file paths. ESLint 8's eslintrc loader `require()`s those
 * paths and chokes on the stray top-level `default` property
 * ("Unexpected top-level property \"default\""), so `extends: 'plugin:vue/...'`
 * is unusable here.
 *
 * We resolve the preset ourselves: unwrap `.default`, walk the file-path
 * `extends` chain, and flatten everything into one plain config object that
 * ESLint 8 can consume. This is the v8/v9 equivalent of
 * `plugin:vue/vue3-recommended` (renamed to `plugin:vue/recommended` in v10).
 */
const flattenVueConfig = () => {
	const unwrap = (mod) => (mod && mod.default ? mod.default : mod)
	const merge = (cfg) => {
		cfg = unwrap(cfg)
		let rules = {}
		let overrides = []
		let parserOptions = {}
		let plugins = []
		if (cfg.extends) {
			const parents = Array.isArray(cfg.extends)
				? cfg.extends
				: [cfg.extends]
			for (const parent of parents) {
				// eslint-disable-next-line import/no-dynamic-require, global-require
				const sub = merge(require(parent))
				rules = { ...rules, ...sub.rules }
				overrides = [...overrides, ...sub.overrides]
				parserOptions = { ...parserOptions, ...sub.parserOptions }
				plugins = [...new Set([...plugins, ...sub.plugins])]
			}
		}
		rules = { ...rules, ...(cfg.rules || {}) }
		if (cfg.overrides) overrides = [...overrides, ...cfg.overrides]
		if (cfg.parserOptions) {
			parserOptions = { ...parserOptions, ...cfg.parserOptions }
		}
		if (cfg.plugins) plugins = [...new Set([...plugins, ...cfg.plugins])]
		return { rules, overrides, parserOptions, plugins }
	}
	// eslint-disable-next-line global-require
	return merge(require('eslint-plugin-vue').configs.recommended)
}

const vueConfig = flattenVueConfig()

/**
 * Vue 3 recommended (strongly-recommended tier) bundles many *stylistic*
 * template rules (html-indent, max-attributes-per-line, html-closing-bracket-*,
 * etc.). This project delegates all formatting to Prettier (tabs, see the
 * `prettier/prettier` rule below), so those vue stylistic rules conflict with
 * Prettier output and produce pure formatting noise.
 *
 * `@vue/eslint-config-prettier` (already a devDependency) publishes the
 * authoritative list of vue formatting rules that must be turned off for
 * Prettier compatibility. We extract that list and switch each off, keeping the
 * vue *correctness* rules (no-mutating-props, require-v-for-key, valid-*, etc.)
 * intact. Trade-off: template formatting is enforced by Prettier only, not by
 * eslint-plugin-vue.
 */
const vuePrettierOffRules = () => {
	// eslint-disable-next-line global-require
	const config = require('@vue/eslint-config-prettier')
	const layers = Array.isArray(config) ? config : [config]
	const off = {}
	for (const layer of layers) {
		if (!layer || !layer.rules) continue
		for (const name of Object.keys(layer.rules)) {
			if (name.startsWith('vue/')) off[name] = 'off'
		}
	}
	return off
}

/** @type {import("eslint").Linter.Config} */
module.exports = {
	root: true,
	env: {
		node: true,
		browser: true,
		es2021: true,
		commonjs: true,
	},
	parser: '@typescript-eslint/parser',
	extends: [
		'plugin:@typescript-eslint/recommended',
		'plugin:@wordpress/eslint-plugin/custom',
		'plugin:@wordpress/eslint-plugin/esnext',
		'plugin:@wordpress/eslint-plugin/jsdoc',
		'plugin:import/recommended',
		'plugin:import/typescript',
		'eslint-config-prettier',
		'prettier',
		'plugin:prettier/recommended',
	],
	parserOptions: {
		ecmaVersion: 'latest',
		sourceType: 'module',
		project: './tsconfig.json',
	},
	plugins: [
		'vue',
		'@typescript-eslint',
		'import',
		'prettier',
	],
	rules: {
		// Vue 3 recommended rules, flattened from eslint-plugin-vue@10 (see above).
		// Spread first so project-specific overrides below take precedence.
		...vueConfig.rules,
		// Turn off vue stylistic rules that conflict with Prettier (see above).
		...vuePrettierOffRules(),
		// Every settings page in this project is named `index.vue` (one per
		// provider, e.g. Payments/SLP/index.vue). This is an established
		// convention, so the multi-word component-name rule is not applicable.
		'vue/multi-word-component-names': 'off',
		'quote-props': 'off',
		'jsdoc/check-param-names': 'off',
		'jsdoc/require-param': 'off',
		'jsdoc/require-param-type': 'off',
		'jsdoc/require-param-name': 'off',
		'jsdoc/require-param-description': 'off',
		'jsdoc/valid-types': 'off',
		// JSDoc type/return annotations are redundant in TypeScript (the type
		// signature is authoritative). Param-type rules are already off above;
		// turn off the return-type/return-check rules for the same reason so
		// `@return <desc>` (no `{type}`) is allowed.
		'jsdoc/require-returns-type': 'off',
		'jsdoc/require-returns-check': 'off',
		'@typescript-eslint/no-explicit-any': 'warn',
		'@wordpress/no-unused-vars-before-return': 'off',
		'@typescript-eslint/ban-types': 'off',
		'@typescript-eslint/interface-name-prefix': 'off',
		'@typescript-eslint/explicit-function-return-type': 'off',
		'@typescript-eslint/no-shadow': 'error',
		'@typescript-eslint/ban-ts-comment': 'off',
		'@typescript-eslint/no-unused-vars': [
			'warn',
			{
				argsIgnorePattern: '^_',
				varsIgnorePattern: '^_',
			},
		],
		'import/order': [
			'error',
			{
				groups: [
					'builtin',
					'external',
					'internal',
					'parent',
					'sibling',
					'index',
				],
				'newlines-between': 'always',
				alphabetize: {
					order: 'asc',
					caseInsensitive: true,
				},
			},
		],
		'import/no-unresolved': 'off',
		'import/extensions': [
			'error',
			'ignorePackages',
			{
				js: 'never',
				jsx: 'never',
				ts: 'never',
				tsx: 'never',
				vue: 'always',
			},
		],
		semi: ['error', 'never'],
		quotes: ['error', 'single'],
		'no-console': ['warn'],
		'no-debugger': 'error',
		// Enforce strict equality everywhere except `== null` / `!= null`, the
		// idiomatic single check for both null and undefined (used in SDK
		// callbacks where an empty-string errMsg is not an error).
		eqeqeq: ['error', 'always', { null: 'ignore' }],
		'array-callback-return': 'off',
		'no-duplicate-imports': 'error',
		'linebreak-style': 'off',
		'no-unused-vars': 'off',
		// Base `no-shadow` false-positives on TS enums/namespaces; the
		// type-aware `@typescript-eslint/no-shadow` above handles those
		// correctly. See typescript-eslint docs for `no-shadow`.
		'no-shadow': 'off',
		camelcase: 'off',
		'prefer-const': 'error',
		'no-var': 'error',
		// Use the TS-aware variant: the core `lines-around-comment` does not
		// understand TS type/interface/enum bodies, so a section comment at the
		// start of a `type {`/`interface {`/`enum {` block is flagged, yet
		// Prettier forbids the blank line the core rule wants — an unfixable
		// conflict. The @typescript-eslint version adds allow*Start options for
		// those TS constructs (the project consistently uses such comments).
		'lines-around-comment': 'off',
		'@typescript-eslint/lines-around-comment': [
			'error',
			{
				beforeBlockComment: true,
				afterBlockComment: false,
				beforeLineComment: true,
				afterLineComment: false,
				allowBlockStart: true,
				allowBlockEnd: true,
				allowObjectStart: true,
				allowObjectEnd: true,
				allowArrayStart: true,
				allowArrayEnd: true,
				allowInterfaceStart: true,
				allowTypeStart: true,
				allowEnumStart: true,
				allowModuleStart: true,
			},
		],
		'prettier/prettier': [
			'error',
			{
				endOfLine: 'auto',
				useTabs: true,
				tabWidth: 2,
				semi: false,
				singleQuote: true,
				trailingComma: 'es5',
				'prettier-multiline-arrays-set-threshold': 1,
			},
		],
	},
	overrides: [
		// Vue base overrides (sets vue-eslint-parser for *.vue), from
		// eslint-plugin-vue@10 recommended preset flattened above.
		...vueConfig.overrides,
		{
			files: ['*.d.ts'],
			rules: {
				'no-undef': 'off',
				'no-var': 'off',
			},
		},
		{
			// `no-undef` is redundant and false-positives in TypeScript: TS
			// itself reports undefined references, and `no-undef` does not see
			// ambient global types declared in *.d.ts (e.g. TEcpgSettings from
			// inc/assets/blocks/types/types.d.ts). typescript-eslint recommends
			// disabling it for TS files.
			files: ['*.ts', '*.tsx'],
			rules: {
				'no-undef': 'off',
			},
		},
		{
			files: ['*.js', '*.jsx'],
			rules: {
				'@typescript-eslint/no-var-requires': 'off',
			},
		},
		{
			files: ['*.vue'],
			// vue-eslint-parser parses the SFC; delegate <script lang="ts">
			// blocks to @typescript-eslint/parser. The flattened vue base
			// override above already sets `parser: vue-eslint-parser`, but we
			// re-declare it here together with the inner parser so TS syntax in
			// `<script setup lang="ts">` is understood.
			parser: 'vue-eslint-parser',
			parserOptions: {
				parser: '@typescript-eslint/parser',
				ecmaVersion: 'latest',
				sourceType: 'module',
				extraFileExtensions: ['.vue'],
				// Disable type-aware linting for .vue files: tsconfig.json does
				// not (and cannot, without vue-tsc) include SFCs, so leaving
				// `project` set throws "TSConfig does not include this file".
				// The type-aware rules are already turned off for *.vue below.
				project: null,
			},
			rules: {
				'@typescript-eslint/no-unsafe-assignment': 'off',
				'@typescript-eslint/no-unsafe-return': 'off',
				'@typescript-eslint/restrict-template-expressions': 'off',
				'@typescript-eslint/no-unsafe-call': 'off',
				'@typescript-eslint/no-unsafe-member-access': 'off',
			},
		},
	],
	globals: {
		window: 'readonly',
		document: 'readonly',
		wpApiSettings: 'readonly',
		process: 'readonly',
		__dirname: 'readonly',
		__filename: 'readonly',
	},
	settings: {
		'import/resolver': {
			typescript: {
				alwaysTryTypes: true,
				project: './tsconfig.json',
			},
			node: {
				extensions: ['.js', '.jsx', '.ts', '.tsx', '.vue'],
			},
		},
	},
}
