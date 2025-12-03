import React, { useState } from 'react';
import { Dashicon } from '@wordpress/components';

interface PreloadSettings {
	preload_fonts: string[];
	preload_images: string[];
	dns_prefetch: string[];
	preconnect: string[];
	display_swap: boolean;
}

interface PreloadTabProps {
	settings: PreloadSettings;
	onChange: (key: keyof PreloadSettings, value: any) => void;
	disabled?: boolean;
}

export const PreloadTab: React.FC<PreloadTabProps> = ({ settings, onChange, disabled }) => {
	const [error, setError] = useState<string | null>(null);

	const updateSetting = (key: keyof PreloadSettings, value: string) => {
		try {
			setError(null);
			const lines = value.split('\n').map(l => l.trim()).filter(l => l);
			onChange(key, lines);
		} catch (err) {
			setError(err instanceof Error ? err.message : 'Failed to update setting');
		}
	};

	return (
		<div className="space-y-8">
			{error && (
				<div className="bg-red-50 border-2 border-red-200 rounded-lg p-4">
					<p className="text-red-800">{error}</p>
				</div>
			)}
			<div>
				<h2 className="text-3xl font-bold text-gray-900 mb-2">Preload & Resource Hints</h2>
				<p className="text-base text-gray-600">Optimize resource loading with preload, DNS prefetch, and preconnect</p>
			</div>

			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h3 className="text-2xl font-bold text-gray-900 mb-6">Font Preloading</h3>
				<p className="text-base text-gray-600 mb-4">
					Preload critical fonts to eliminate font loading delays and prevent FOUT (Flash of Unstyled Text)
				</p>
				<textarea
					rows={5}
					className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
					placeholder="/wp-content/themes/mytheme/fonts/font.woff2&#10;https://fonts.gstatic.com/s/roboto/v30/font.woff2"
					value={settings.preload_fonts.join('\n')}
					onChange={(e) => updateSetting('preload_fonts', e.target.value)}
				/>
				<p className="text-sm text-gray-600 mt-2">
					Enter full URLs to font files (one per line). Supports .woff, .woff2, .ttf, .otf
				</p>

				<div className="mt-6 flex items-center justify-between border-t border-gray-100 pt-4">
					<div>
						<h4 className="text-lg font-semibold text-gray-900">Google Fonts Optimization</h4>
						<p className="text-sm text-gray-600">Add <code>display:swap</code> to Google Fonts to improve text rendering speed</p>
					</div>
					<label className="relative inline-flex items-center cursor-pointer">
						<input
							type="checkbox"
							className="sr-only peer"
							checked={settings.display_swap}
							onChange={(e) => onChange('display_swap', e.target.checked)}
							disabled={disabled}
						/>
						<div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
					</label>
				</div>
			</div>

			<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
				<h3 className="text-2xl font-bold text-gray-900 mb-6">Critical Image Preloading</h3>
				<p className="text-base text-gray-600 mb-4">
					Preload above-the-fold images to improve Largest Contentful Paint (LCP)
				</p>
				<textarea
					rows={5}
					className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
					placeholder="/wp-content/uploads/2024/hero-image.jpg&#10;/wp-content/themes/mytheme/images/logo.png"
					value={settings.preload_images.join('\n')}
					onChange={(e) => updateSetting('preload_images', e.target.value)}
				/>
				<p className="text-sm text-gray-600 mt-2">
					Enter URLs to critical images (one per line). Typically 1-3 above-the-fold images
				</p>
			</div>

			<div className="grid grid-cols-1 md:grid-cols-2 gap-6">
				<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
					<div className="flex items-center gap-3 mb-4">
						<div className="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="networking" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-xl font-bold text-gray-900">DNS Prefetch</h3>
					</div>
					<p className="text-sm text-gray-600 mb-4">
						Resolve DNS for external domains before they're needed
					</p>
					<textarea
						rows={5}
						className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
						placeholder="fonts.googleapis.com&#10;cdn.example.com&#10;analytics.google.com"
						value={settings.dns_prefetch.join('\n')}
						onChange={(e) => updateSetting('dns_prefetch', e.target.value)}
					/>
					<p className="text-xs text-gray-500 mt-2">
						Domain names only (no https://)
					</p>
				</div>

				<div className="bg-white rounded-xl border-2 border-gray-200 p-8">
					<div className="flex items-center gap-3 mb-4">
						<div className="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center">
							<Dashicon icon="admin-links" style={{ fontSize: '24px', color: 'white' }} />
						</div>
						<h3 className="text-xl font-bold text-gray-900">Preconnect</h3>
					</div>
					<p className="text-sm text-gray-600 mb-4">
						Establish early connections to important third-party origins
					</p>
					<textarea
						rows={5}
						className="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-colors font-mono text-sm"
						placeholder="https://fonts.gstatic.com&#10;https://cdn.example.com"
						value={settings.preconnect.join('\n')}
						onChange={(e) => updateSetting('preconnect', e.target.value)}
					/>
					<p className="text-xs text-gray-500 mt-2">
						Full URLs with https:// (for critical resources only)
					</p>
				</div>
			</div>

			<div className="bg-gradient-to-r from-blue-50 to-purple-50 rounded-xl border-2 border-blue-200 p-8">
				<div className="flex items-start gap-4">
					<div className="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center flex-shrink-0">
						<Dashicon icon="lightbulb" style={{ fontSize: '24px', color: 'white' }} />
					</div>
					<div>
						<h3 className="text-xl font-bold text-gray-900 mb-2">Performance Tips</h3>
						<ul className="space-y-2 text-sm text-gray-700">
							<li className="flex items-start gap-2">
								<span className="text-blue-600 font-bold">•</span>
								<span><strong>Font Preload:</strong> Only preload fonts used above-the-fold (typically 1-2 fonts)</span>
							</li>
							<li className="flex items-start gap-2">
								<span className="text-blue-600 font-bold">•</span>
								<span><strong>Image Preload:</strong> Limit to 1-3 critical images (hero, logo) to avoid bandwidth waste</span>
							</li>
							<li className="flex items-start gap-2">
								<span className="text-blue-600 font-bold">•</span>
								<span><strong>DNS Prefetch:</strong> Use for domains you'll definitely need (fonts, analytics, CDN)</span>
							</li>
							<li className="flex items-start gap-2">
								<span className="text-blue-600 font-bold">•</span>
								<span><strong>Preconnect:</strong> Reserve for 2-3 most critical third-party domains only</span>
							</li>
						</ul>
					</div>
				</div>
			</div>

		</div>
	);
};
