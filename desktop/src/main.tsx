import { createRoot } from 'react-dom/client';
import { StrictMode } from 'react';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { forgetCatalog } from '@/lib/client';
import App from './app';
import Toaster from './components/toaster';
import './app.css';

initializeTheme();

// The shared model picker's catalog: an AI connected or removed on the web shows up once the user comes back.
window.addEventListener('focus', forgetCatalog);

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <TooltipProvider delayDuration={0}>
            <App />
            <Toaster />
        </TooltipProvider>
    </StrictMode>,
);
