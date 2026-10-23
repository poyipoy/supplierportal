import forms from '@tailwindcss/forms';

// Preserve the v3 palette shades already used by operational screens.
const legacyColors = {
    "emerald": {
        "100": "#d1fae5",
        "300": "#6ee7b7",
        "400": "#34d399",
        "500": "#10b981",
        "600": "#059669",
        "700": "#047857",
        "800": "#065f46",
        "950": "#022c22"
    },
    "amber": {
        "50": "#fffbeb",
        "100": "#fef3c7",
        "300": "#fcd34d",
        "400": "#fbbf24",
        "500": "#f59e0b",
        "600": "#d97706",
        "700": "#b45309",
        "800": "#92400e",
        "950": "#451a03"
    },
    "rose": {
        "100": "#ffe4e6",
        "800": "#9f1239"
    },
    "slate": {
        "100": "#f1f5f9",
        "800": "#1e293b"
    },
    "teal": {
        "600": "#0d9488"
    }
};

/** @type {import('tailwindcss').Config} */
export default {
    // Loaded explicitly by app.css. Sources and prefix are configured there;
    // token values remain shared with Bootstrap and the existing design system.
    theme: {
        extend: {
            screens: {
                shell: '993px',
            },
            colors: {
                ...legacyColors,
                background: 'var(--md-background)',
                'on-primary': 'var(--md-on-primary)',
                'on-primary-container': 'var(--md-on-primary-container)',
                'on-secondary': 'var(--md-on-secondary)',
                'on-secondary-container': 'var(--md-on-secondary-container)',
                'on-error': 'var(--md-on-error)',
                'on-error-container': 'var(--md-on-error-container)',
                'on-success': 'var(--md-on-success)',
                'on-success-container': 'var(--md-on-success-container)',
                'on-warning': 'var(--md-on-warning)',
                'on-warning-container': 'var(--md-on-warning-container)',
                'on-info': 'var(--md-on-info)',
                'on-info-container': 'var(--md-on-info-container)',

                primary: {
                    DEFAULT: 'var(--md-primary)',
                    foreground: 'var(--md-on-primary)',
                    container: 'var(--md-primary-container)',
                    'container-foreground': 'var(--md-on-primary-container)',
                },
                secondary: {
                    DEFAULT: 'var(--md-secondary)',
                    foreground: 'var(--md-on-secondary)',
                    container: 'var(--md-secondary-container)',
                    'container-foreground': 'var(--md-on-secondary-container)',
                },
                error: {
                    DEFAULT: 'var(--md-error)',
                    foreground: 'var(--md-on-error)',
                    container: 'var(--md-error-container)',
                    'container-foreground': 'var(--md-on-error-container)',
                },
                info: {
                    DEFAULT: 'var(--md-info)',
                    foreground: 'var(--md-on-info)',
                    container: 'var(--md-info-container)',
                    'container-foreground': 'var(--md-on-info-container)',
                },
                success: {
                    DEFAULT: 'var(--md-success)',
                    foreground: 'var(--md-on-success)',
                    container: 'var(--md-success-container)',
                    'container-foreground': 'var(--md-on-success-container)',
                },
                warning: {
                    DEFAULT: 'var(--md-warning)',
                    foreground: 'var(--md-on-warning)',
                    container: 'var(--md-warning-container)',
                    'container-foreground': 'var(--md-on-warning-container)',
                },
                surface: {
                    DEFAULT: 'var(--md-surface)',
                    low: 'var(--md-surface-container-low)',
                    container: 'var(--md-surface-container)',
                    high: 'var(--md-surface-container-high)',
                    // Material names used across the views; they map to the same variables.
                    'container-lowest': 'var(--md-surface-container-lowest, var(--md-surface))',
                    'container-low': 'var(--md-surface-container-low)',
                    'container-high': 'var(--md-surface-container-high)',
                    'container-highest': 'var(--md-surface-container-highest)',
                },
                outline: {
                    DEFAULT: 'var(--md-outline)',
                    strong: 'var(--md-outline-strong)',
                    variant: 'var(--md-outline-variant)',
                },
                'on-surface': 'var(--md-on-surface)',
                'on-surface-variant': 'var(--md-on-surface-variant)',
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif', '"Apple Color Emoji"', '"Segoe UI Emoji"', '"Segoe UI Symbol"', '"Noto Color Emoji"'],
            },
            fontSize: {
                'ui-xs': ['var(--ui-font-size-xs)', { lineHeight: 'var(--ui-line-height-normal)' }],
                'ui-sm': ['var(--ui-font-size-sm)', { lineHeight: 'var(--ui-line-height-normal)' }],
                'ui-base': ['var(--ui-font-size-base)', { lineHeight: 'var(--ui-line-height-normal)' }],
                'ui-lg': ['var(--ui-font-size-lg)', { lineHeight: 'var(--ui-line-height-tight)' }],
                'ui-xl': ['var(--ui-font-size-xl)', { lineHeight: 'var(--ui-line-height-tight)' }],
                'ui-2xl': ['var(--ui-font-size-2xl)', { lineHeight: 'var(--ui-line-height-tight)' }],
            },
            spacing: {
                'ui-1': 'var(--ui-space-1)',
                'ui-2': 'var(--ui-space-2)',
                'ui-3': 'var(--ui-space-3)',
                'ui-4': 'var(--ui-space-4)',
                'ui-5': 'var(--ui-space-5)',
                'ui-6': 'var(--ui-space-6)',
                'ui-8': 'var(--ui-space-8)',
            },
            borderRadius: {
                full: '9999px',
                'ui-xs': 'var(--md-shape-xs)',
                'ui-sm': 'var(--md-shape-sm)',
                'ui-md': 'var(--md-shape-md)',
                'ui-lg': 'var(--md-shape-lg)',
                'ui-full': 'var(--md-shape-full)',
            },
            boxShadow: {
                'ui-1': 'var(--ui-shadow-1)',
                'ui-2': 'var(--ui-shadow-2)',
            },
            transitionDuration: {
                fast: 'var(--ui-motion-fast)',
                standard: 'var(--ui-motion-standard)',
                slow: 'var(--ui-motion-slow)',
            },
            transitionTimingFunction: {
                standard: 'var(--ui-easing-standard)',
                emphasized: 'var(--ui-easing-emphasized)',
            },
            zIndex: {
                dropdown: 'var(--ui-z-dropdown)',
                sticky: 'var(--ui-z-sticky)',
                drawer: 'var(--ui-z-drawer)',
                modal: 'var(--ui-z-modal)',
                toast: 'var(--ui-z-toast)',
            },
        },
    },

    plugins: [forms({ strategy: 'class' })],
};
