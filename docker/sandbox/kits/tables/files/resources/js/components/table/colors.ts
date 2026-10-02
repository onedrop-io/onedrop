import type { ChoiceColor } from './types';

/** Colors for select options, in the order new options take them. */
export const CHOICE_COLORS: ChoiceColor[] = [
    'blue',
    'green',
    'amber',
    'red',
    'violet',
    'cyan',
    'pink',
    'orange',
    'teal',
    'indigo',
    'lime',
    'purple',
    'yellow',
    'gray',
];

/** Pill classes per color (spelled out so Tailwind finds them). */
export const CHOICE_CLASSES: Record<ChoiceColor, string> = {
    gray: 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200',
    red: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    orange: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-200',
    lime: 'bg-lime-100 text-lime-800 dark:bg-lime-950 dark:text-lime-200',
    green: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    teal: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-200',
    cyan: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950 dark:text-cyan-200',
    blue: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200',
    violet: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
    purple: 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-200',
    pink: 'bg-pink-100 text-pink-800 dark:bg-pink-950 dark:text-pink-200',
};

/** A solid swatch per color, for color pickers and calendar chips. */
export const CHOICE_SWATCHES: Record<ChoiceColor, string> = {
    gray: 'bg-zinc-400',
    red: 'bg-red-500',
    orange: 'bg-orange-500',
    amber: 'bg-amber-500',
    yellow: 'bg-yellow-400',
    lime: 'bg-lime-500',
    green: 'bg-green-500',
    teal: 'bg-teal-500',
    cyan: 'bg-cyan-500',
    blue: 'bg-blue-500',
    indigo: 'bg-indigo-500',
    violet: 'bg-violet-500',
    purple: 'bg-purple-500',
    pink: 'bg-pink-500',
};

/** The next color for a new option: the first one the field doesn't use yet. */
export function nextChoiceColor(used: ChoiceColor[]): ChoiceColor {
    return (
        CHOICE_COLORS.find((color) => !used.includes(color)) ??
        CHOICE_COLORS[used.length % CHOICE_COLORS.length]
    );
}
