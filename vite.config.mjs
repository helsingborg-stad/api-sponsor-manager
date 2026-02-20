import { createViteConfig } from 'vite-config-factory';

const entries = {
	'js/api-sponsor-manager': './source/js/api-sponsor-manager.ts',
	'css/api-sponsor-manager': './source/sass/api-sponsor-manager.scss',
};

export default createViteConfig(entries, {
	outDir: 'assets/dist',
	manifestFile: 'manifest.json',
});
