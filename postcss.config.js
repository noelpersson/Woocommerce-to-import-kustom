module.exports = {
	plugins: {
		tailwindcss: {},
		autoprefixer: {},
		'postcss-prefix-selector': {
			prefix: '#woo-kustom-export-root',
			transform(prefix, selector, prefixedSelector) {
				if (selector.startsWith('html') || selector.startsWith('body')) {
					return selector.replace(/^html|^body/, prefix);
				}
				if (selector.startsWith(':root')) {
					return selector.replace(/^:root/, prefix);
				}
				if (selector === prefix || selector.startsWith(prefix)) {
					return selector;
				}
				if (selector === '.woo-kustom-wrap') {
					return prefix;
				}
				return prefixedSelector;
			},
		},
	},
};
