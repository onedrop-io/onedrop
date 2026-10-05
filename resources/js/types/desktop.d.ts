/**
 * What the desktop app (DESK-006..011) offers the web pages it runs, as `window.onedropDesktop`. Undefined in a
 * browser: check for it before showing desktop-only controls. See docs/development/desktop-link.mdx.
 */

export type DesktopForward = {
    projectId: number;
    /** The sandbox's port. */
    remotePort: number;
    /** The port on this computer (127.0.0.1). */
    localPort: number;
    state: 'connecting' | 'listening' | 'error';
    error: string | null;
};

export type DesktopNetworkStatus = {
    /** Whether this computer shares its network with the project. */
    sharing: boolean;
    state: 'off' | 'connecting' | 'connected' | 'error';
    error: string | null;
};

export type DesktopDockerStatus = {
    state: 'checking' | 'missing' | 'stopped' | 'ready' | 'pulling';
    /** e.g. "Docker Desktop 4.44" or what's wrong. */
    detail: string | null;
    /** 0..1 while the sandbox image downloads. */
    progress: number | null;
    /** Whether the image is already on this computer. */
    hasImage: boolean;
};

export type DesktopDeviceStatus = {
    /** Whether this install can run projects on computers (its gateway is on Cloudflare). */
    available: boolean;
    state: 'off' | 'connecting' | 'connected' | 'error';
    error: string | null;
};

export type DesktopEditor = 'vscode' | 'cursor';

export interface OnedropDesktop {
    version: string;
    /** This computer: its sign-in's id (the device id) and name. */
    device: { id: number; name: string };

    forwards: {
        list(projectId?: number): Promise<DesktopForward[]>;
        /** Start (or keep) forwarding a sandbox port; the local port defaults to the same number, or a free one. */
        start(
            projectId: number,
            remotePort: number,
            localPort?: number,
        ): Promise<DesktopForward>;
        stop(projectId: number, remotePort: number): Promise<void>;
    };

    editor: {
        /** Whether ~/.ssh/config already includes the app's SSH config. */
        configured(): Promise<boolean>;
        /** Set up the key and config (asking the user first when ~/.ssh/config needs the include), then open the editor. */
        open(
            projectId: number,
            alias: string,
            editor: DesktopEditor,
        ): Promise<void>;
    };

    network: {
        status(projectId: number): Promise<DesktopNetworkStatus>;
        setSharing(
            projectId: number,
            sharing: boolean,
        ): Promise<DesktopNetworkStatus>;
    };

    docker: {
        status(): Promise<DesktopDockerStatus>;
        /** Download the sandbox image (resolves when it's there). */
        prepare(): Promise<DesktopDockerStatus>;
    };

    devices: {
        status(): Promise<DesktopDeviceStatus>;
    };

    /** Called whenever anything above changes; returns a function that stops listening. */
    onChange(listener: () => void): () => void;
}

declare global {
    interface Window {
        onedropDesktop?: OnedropDesktop;
    }
}
