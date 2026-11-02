import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ToastProvider } from './Components/ToastProvider';
import { configureEcho } from '@laravel/echo-react';
import { buildEchoConfig } from './Realtime/echoConfig';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        let realtime = props.initialPage.props.realtime;
        configureEcho(buildEchoConfig(realtime, name => document
            .querySelector<HTMLMetaElement>(`meta[name="${name}"]`)
            ?.getAttribute('content'), () => realtime));
        router.on('navigate', event => {
            if (Object.prototype.hasOwnProperty.call(event.detail.page.props, 'realtime')) {
                realtime = event.detail.page.props.realtime;
            }
        });
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
