import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const allPages = {
            ...import.meta.glob('./Pages/**/*.tsx'),
            ...import.meta.glob('../../packages/workdo/*/src/Resources/js/Pages/**/*.tsx'),
        };

        // Core page: "Dashboard" -> resources/js/Pages/Dashboard.tsx
        const corePath = `./Pages/${name}.tsx`;
        if (allPages[corePath]) {
            return resolvePageComponent(corePath, allPages);
        }

        // Module page: "Pos/PosOrder/Index" -> packages/workdo/Pos/src/Resources/js/Pages/PosOrder/Index.tsx
        const [packageName, ...pagePath] = name.split('/');
        const packagePath = `../../packages/workdo/${packageName}/src/Resources/js/Pages/${pagePath.join('/')}.tsx`;

        return resolvePageComponent(packagePath, allPages);
    },
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
