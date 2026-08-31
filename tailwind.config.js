/** @type {import('tailwindcss').Config} */
module.exports = {
	content: [ './src/**/*.{js,jsx,ts,tsx}' ],
	// Avoid colliding with existing .wppo-* and WP admin .wrap
	prefix: 'tw-',
	corePlugins: {
		preflight: false,
	},
	theme: {
		extend: {
			colors: {
				wppo: {
					primary: 'var(--wppo-primary)',
					'primary-hover': 'var(--wppo-primary-hover)',
					'primary-soft': 'var(--wppo-primary-soft)',
					bg: 'var(--wppo-bg-app)',
					'bg-card': 'var(--wppo-bg-card)',
					'bg-surface': 'var(--wppo-bg-card-surface)',
					border: 'var(--wppo-border)',
					'border-hover': 'var(--wppo-border-hover)',
					text: 'var(--wppo-text-main)',
					muted: 'var(--wppo-text-muted)',
					light: 'var(--wppo-text-light)',
					success: 'var(--wppo-success)',
					'success-bg': 'var(--wppo-success-bg)',
					'success-border': 'var(--wppo-success-border)',
					error: 'var(--wppo-error)',
					'error-bg': 'var(--wppo-error-bg)',
					'error-border': 'var(--wppo-error-border)',
					warning: 'var(--wppo-warning)',
					'warning-bg': 'var(--wppo-warning-bg)',
					'warning-border': 'var(--wppo-warning-border)',
					info: 'var(--wppo-info)',
					'info-bg': 'var(--wppo-info-bg)',
					'info-border': 'var(--wppo-info-border)',
					danger: 'var(--wppo-danger)',
				},
			},
			borderRadius: {
				wppo: 'var(--wppo-radius)',
				'wppo-sm': 'var(--wppo-radius-sm)',
				'wppo-xs': 'var(--wppo-radius-xs)',
			},
			boxShadow: {
				'wppo-sm': 'var(--wppo-shadow-sm)',
				wppo: 'var(--wppo-shadow)',
				'wppo-lg': 'var(--wppo-shadow-lg)',
				'wppo-card': 'var(--wppo-shadow-card)',
				'wppo-card-hover': 'var(--wppo-shadow-card-hover)',
			},
			maxWidth: {
				wppo: 'var(--wppo-max-width)',
			},
			// Preserve SCSS breakpoints (max-width) but Tailwind defaults are min-width.
			// Use max-* variants explicitly; keep screens as min-width for future hybrid.
			screens: {
				xs: '400px',
				sm: '640px',
				md: '768px',
				lg: '992px',
				xl: '1200px',
			},
			fontFamily: {
				wppo: [
					'-apple-system',
					'BlinkMacSystemFont',
					'Segoe UI',
					'Roboto',
					'Helvetica Neue',
					'sans-serif',
				],
			},
		},
	},
	plugins: [],
};
