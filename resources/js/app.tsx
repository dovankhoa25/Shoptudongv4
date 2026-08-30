import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ToastProvider } from './Components/ToastProvider';
import { configureEcho } from '@laravel/echo-react';

const ablyPublicKey = document
    .querySelector<HTMLMetaElement>('meta[name="ably-public-key"]')
    ?.getAttribute('content') ?? '';

configureEcho({
    broadcaster: 'ably',
    key: ablyPublicKey,
    authEndpoint: '/broadcasting/auth',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        // root.render(<App {...props} />);
        root.render(

            <ToastProvider>
                <App {...props} />
            </ToastProvider>

        );
    },
    progress: {
        color: '#4B5563',
    },
});
