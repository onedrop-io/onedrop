import { createRoot } from 'react-dom/client';
import { StrictMode } from 'react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { configureAgentModels, forgetCatalog } from '@/lib/agent-models';
import type { Catalog } from '@/lib/agent-models';
import App from './app';
import Toaster from './components/toaster';
import { api } from './lib/api';
import './app.css';

initializeTheme();

// The web app's model picker (AGT-002), over the API. An AI connected or removed on the web shows up once the user
// comes back to the app.
configureAgentModels({
    load: () => api<Catalog>('agent-models'),
    favorite: (change) => api('agent-models/favorites', change, 'PUT'),
});
window.addEventListener('focus', forgetCatalog);

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <TooltipProvider delayDuration={0}>
            <App />
            <Toaster />
        </TooltipProvider>
    </StrictMode>,
);
