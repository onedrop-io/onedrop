import { useId } from 'react';
import type { SVGAttributes } from 'react';
import { cn } from '@/lib/utils';
import { useBlobUrl } from '../lib/hooks';

/** The droplet, or the logo the install's admin uploaded (ADMIN-001). */
export default function Logo({
    logo = null,
    className,
    ...props
}: SVGAttributes<SVGElement> & { logo?: string | null }) {
    const highlight = useId();
    const custom = useBlobUrl(logo);

    if (custom) {
        return (
            <img
                src={custom}
                alt=""
                className={cn('object-contain', className)}
            />
        );
    }

    return (
        <svg
            {...props}
            className={cn('fill-current', className)}
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
        >
            <mask id={highlight}>
                <rect width="24" height="24" fill="white" />
                <path
                    d="M9.2 15.6a3 3 0 0 0 2.6 2.9"
                    stroke="black"
                    strokeWidth="1.6"
                    strokeLinecap="round"
                    fill="none"
                />
            </mask>
            <path
                mask={`url(#${highlight})`}
                d="M12 2.5c-.4.5-7 8.3-7 13a7 7 0 0 0 14 0c0-4.7-6.6-12.5-7-13Z"
            />
        </svg>
    );
}
