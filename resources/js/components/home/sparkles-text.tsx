import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Sparkle = {
    id: number;
    left: number;
    top: number;
    color: string;
    scale: number;
    duration: number;
    delay: number;
};

const SPARKLE_COLORS = ['#FF9A5C', '#FFD7BD', '#FF4D1C'];

let nextSparkleId = 0;

function makeSparkle(): Sparkle {
    return {
        id: nextSparkleId++,
        left: Math.random() * 100,
        top: Math.random() * 100,
        color: SPARKLE_COLORS[
            Math.floor(Math.random() * SPARKLE_COLORS.length)
        ],
        scale: 0.35 + Math.random() * 0.5,
        duration: 3 + Math.random() * 2.5,
        delay: Math.random() * 5,
    };
}

/**
 * Text with four-point stars twinkling over it, after Magic UI's Sparkles
 * Text (https://magicui.design/docs/components/sparkles-text), in plain CSS.
 * Each star moves somewhere new once it fades out.
 */
export function SparklesText({
    children,
    className,
    sparklesCount = 9,
}: {
    children: ReactNode;
    className?: string;
    sparklesCount?: number;
}) {
    const [sparkles, setSparkles] = useState<Sparkle[]>([]);

    useEffect(() => {
        setSparkles(Array.from({ length: sparklesCount }, makeSparkle));
    }, [sparklesCount]);

    const replace = (id: number) =>
        setSparkles((current) =>
            current.map((sparkle) =>
                sparkle.id === id
                    ? { ...makeSparkle(), delay: 0.5 + Math.random() * 2.5 }
                    : sparkle,
            ),
        );

    return (
        <span className={cn('relative inline-block', className)}>
            {children}
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 opacity-50 motion-reduce:hidden"
            >
                {sparkles.map((sparkle) => (
                    <svg
                        key={sparkle.id}
                        viewBox="0 0 21 21"
                        className="absolute size-[0.3em] -translate-1/2 animate-sparkle"
                        style={{
                            left: `${sparkle.left}%`,
                            top: `${sparkle.top}%`,
                            color: sparkle.color,
                            filter: `drop-shadow(0 0 4px ${sparkle.color})`,
                            animationDuration: `${sparkle.duration}s`,
                            animationDelay: `${sparkle.delay}s`,
                            ['--sparkle-scale' as string]: sparkle.scale,
                        }}
                        onAnimationEnd={() => replace(sparkle.id)}
                    >
                        <path
                            d="M9.82531 0.843845C10.0553 0.215178 10.9446 0.215178 11.1746 0.843845L11.8618 2.72026C12.4006 4.19229 13.3916 6.39157 14.5 7.5C15.6084 8.60843 17.8077 9.59935 19.2797 10.1382L21.1561 10.8254C21.7848 11.0554 21.7848 11.9446 21.1561 12.1746L19.2797 12.8618C17.8077 13.4006 15.6084 14.3916 14.5 15.5C13.3916 16.6084 12.4006 18.8077 11.8618 20.2797L11.1746 22.1561C10.9446 22.7848 10.0553 22.7848 9.82531 22.1561L9.13819 20.2797C8.59925 18.8077 7.60833 16.6084 6.5 15.5C5.39157 14.3916 3.19229 13.4006 1.72026 12.8618L-0.156148 12.1746C-0.784815 11.9446 -0.784815 11.0554 -0.156148 10.8254L1.72026 10.1382C3.19229 9.59935 5.39157 8.60843 6.5 7.5C7.60833 6.39157 8.59925 4.19229 9.13819 2.72026L9.82531 0.843845Z"
                            fill="currentColor"
                        />
                    </svg>
                ))}
            </span>
        </span>
    );
}
