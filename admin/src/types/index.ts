export interface PluginConfig {
    version: string;
    // Add other config properties as needed based on backend localization
    [key: string]: any;
}

declare global {
    interface Window {
        wppoAdmin: PluginConfig;
    }
}
