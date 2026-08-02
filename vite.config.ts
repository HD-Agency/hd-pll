import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
	build: {
		outDir: 'assets',
		emptyOutDir: false,
		rollupOptions: {
			input: {
				'auto-translate': resolve(__dirname, 'resources/auto-translate.js'),
				'options-translate': resolve(__dirname, 'resources/options-translate.js'),
			},
			output: {
				entryFileNames: '[name].js',
				chunkFileNames: 'chunk/[name].js',
				assetFileNames: '[name].[ext]',
			},
		},
		minify: 'terser',
	},
});
