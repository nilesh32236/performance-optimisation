/**
 * Type definitions for Performance Optimisation Admin
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

export interface PluginConfig {
  caching: CachingConfig;
  minification: MinificationConfig;
  images: ImagesConfig;
  preloading: PreloadingConfig;
  database: DatabaseConfig;
  advanced: AdvancedConfig;
}

export interface CachingConfig {
  page_cache_enabled: boolean;
  cache_ttl: number;
  cache_exclusions: string[];
  object_cache_enabled: boolean;
  fragment_cache_enabled: boolean;
}

export interface MinificationConfig {
  minify_css: boolean;
  minify_js: boolean;
  minify_html: boolean;
  combine_css: boolean;
  combine_js: boolean;
  inline_critical_css: boolean;
}

export interface ImagesConfig {
  convert_to_webp: boolean;
  convert_to_avif: boolean;
  lazy_loading: boolean;
  compression_quality: number;
  resize_large_images: boolean;
  max_image_width: number;
  max_image_height: number;
}

export interface PreloadingConfig {
  dns_prefetch: string[];
  preconnect: string[];
  preload_fonts: string[];
  preload_critical_css: boolean;
}

export interface DatabaseConfig {
  cleanup_revisions: boolean;
  cleanup_spam: boolean;
  cleanup_trash: boolean;
  optimize_tables: boolean;
}

export interface AdvancedConfig {
  disable_emojis: boolean;
  disable_embeds: boolean;
  remove_query_strings: boolean;
  defer_js: boolean;
  async_js: boolean;
}

export interface PerformanceMetrics {
  page_load_time: number;
  first_contentful_paint: number;
  largest_contentful_paint: number;
  cumulative_layout_shift: number;
  first_input_delay: number;
  time_to_interactive: number;
}

export interface OptimizationStats {
  cache: {
    hits: number;
    misses: number;
    size: number;
  };
  assets: {
    css_files_minified: number;
    js_files_minified: number;
    bytes_saved: number;
  };
  images: {
    images_optimized: number;
    webp_conversions: number;
    bytes_saved: number;
  };
}

export interface ApiResponse<T = any> {
  success: boolean;
  data?: T;
  message?: string;
  error?: string;
}

export interface WizardStep {
  id: string;
  title: string;
  description: string;
  component: React.ComponentType<any>;
  isComplete: boolean;
  isOptional?: boolean;
}

export interface NotificationProps {
  type: 'success' | 'error' | 'warning' | 'info';
  message: string;
  dismissible?: boolean;
  onDismiss?: () => void;
}

export interface ButtonProps {
  variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
  size?: 'small' | 'medium' | 'large';
  disabled?: boolean;
  loading?: boolean;
  onClick?: () => void;
  children: React.ReactNode;
  className?: string;
}

export interface CardProps {
  title?: string;
  description?: string;
  children: React.ReactNode;
  className?: string;
  actions?: React.ReactNode;
}

export interface ToggleProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label?: string;
  description?: string;
  disabled?: boolean;
}

export interface SliderProps {
  value: number;
  onChange: (value: number) => void;
  min?: number;
  max?: number;
  step?: number;
  label?: string;
  description?: string;
  disabled?: boolean;
}

export interface SelectProps {
  value: string | number;
  onChange: (value: string | number) => void;
  options: Array<{
    label: string;
    value: string | number;
  }>;
  label?: string;
  description?: string;
  disabled?: boolean;
}

export interface TextInputProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  label?: string;
  description?: string;
  disabled?: boolean;
  type?: 'text' | 'email' | 'url' | 'number';
}

export interface ChartData {
  name: string;
  value: number;
  color?: string;
}

export interface LineChartData {
  name: string;
  [key: string]: string | number;
}

export interface ProgressBarProps {
  value: number;
  max?: number;
  label?: string;
  showPercentage?: boolean;
  color?: string;
}

export interface TabsProps {
  tabs: Array<{
    id: string;
    label: string;
    content: React.ReactNode;
  }>;
  activeTab?: string;
  onTabChange?: (tabId: string) => void;
}

export interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title?: string;
  children: React.ReactNode;
  size?: 'small' | 'medium' | 'large';
}

export interface LoadingSpinnerProps {
  size?: 'small' | 'medium' | 'large';
  color?: string;
}

export interface IconProps {
  name: string;
  size?: number;
  color?: string;
  className?: string;
}

// WordPress specific types
export interface WPRestApiResponse<T = any> {
  data?: T;
  message?: string;
  code?: string;
}

export interface WPNonce {
  nonce: string;
  action: string;
}

// Global window extensions
declare global {
  interface Window {
    wppoAdmin: {
      apiUrl: string;
      nonce: string;
      currentUser: {
        id: number;
        name: string;
        email: string;
      };
      config: PluginConfig;
      metrics: PerformanceMetrics;
      stats: OptimizationStats;
    };
  }
}