import { useId } from 'react';
import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const highlight = useId();

    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
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
