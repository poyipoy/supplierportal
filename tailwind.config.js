import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    prefix: 'tw-',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    corePlugins: {
        preflight: false,
    },

    theme: {
        extend: {
            screens: {
                shell: '993px',
            },
            colors: {
                background: 'var(--md-background)',
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
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
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
