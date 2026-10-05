import { useEffect, useState } from 'react';
import { DESKTOP_DOWNLOADS } from '@/lib/links';

export type DesktopSystem = 'mac' | 'windows' | 'linux';

const DOWNLOADS: Record<DesktopSystem, { label: string; href: string }> = {
    mac: { label: 'Download for Mac', href: DESKTOP_DOWNLOADS.macAppleSilicon },
    windows: { label: 'Download for Windows', href: DESKTOP_DOWNLOADS.windows },
    linux: {
        label: 'Download for Linux',
        href: DESKTOP_DOWNLOADS.linuxAppImage,
    },
};

/** The desktop app's download for the visitor's system, from the browser; Mac until it's known (pages render on the server too). */
export function useDesktopDownload(): {
    system: DesktopSystem;
    label: string;
    href: string;
} {
    const [system, setSystem] = useState<DesktopSystem>('mac');

    useEffect(() => {
        const platform =
            `${navigator.platform} ${navigator.userAgent}`.toLowerCase();

        if (/win(dows|32|64)/.test(platform)) {
            setSystem('windows');
        } else if (
            platform.includes('linux') &&
            !platform.includes('android')
        ) {
            setSystem('linux');
        }
    }, []);

    return { system, ...DOWNLOADS[system] };
}
