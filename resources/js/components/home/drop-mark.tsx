import type { CSSProperties } from 'react';

/** The OneDrop droplet logo. */
export function DropMark({
    className,
    style,
}: {
    className?: string;
    style?: CSSProperties;
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
            className={className}
            style={style}
        >
            <path
                d="M12 2.5c-.4.5-7 8.3-7 13a7 7 0 0 0 14 0c0-4.7-6.6-12.5-7-13Z"
                fill="currentColor"
            />
            <path
                d="M9.2 15.6a3 3 0 0 0 2.6 2.9"
                stroke="white"
                strokeWidth="1.6"
                strokeLinecap="round"
                fill="none"
                opacity="0.85"
            />
        </svg>
    );
}
