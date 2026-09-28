import type { ComponentProps, ElementType, HTMLAttributes } from 'react';
import ReactMarkdown from 'react-markdown';
import type { Components, ExtraProps } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import CodeHighlight from '@/components/code-highlight';
import { cn } from '@/lib/utils';

/**
 * A markdown element with our classes, minus react-markdown's `node` prop (not a DOM attribute).
 */
function styled(Tag: ElementType, base: string, extra: object = {}) {
    return function Styled({
        node, // eslint-disable-line no-unused-vars
        className,
        ...props
    }: HTMLAttributes<HTMLElement> & ExtraProps) {
        return <Tag className={cn(base, className)} {...extra} {...props} />;
    };
}

/**
 * Inline code, or a fenced block's code; blocks tagged with a language (```php) get highlighted.
 */
function Code({
    node, // eslint-disable-line no-unused-vars
    className,
    children,
    ...props
}: HTMLAttributes<HTMLElement> & ExtraProps) {
    const language = /language-([\w+#.-]+)/.exec(className ?? '')?.[1];

    return (
        <code
            className={cn(
                'rounded bg-muted px-1 py-0.5 font-mono text-[0.85em]',
                className,
            )}
            {...props}
        >
            {language && typeof children === 'string' ? (
                <CodeHighlight
                    code={children.replace(/\n$/, '')}
                    language={language}
                />
            ) : (
                children
            )}
        </code>
    );
}

const components: Components = {
    h1: styled('h1', 'mt-4 mb-2 text-lg font-semibold first:mt-0'),
    h2: styled('h2', 'mt-4 mb-2 text-base font-semibold first:mt-0'),
    h3: styled('h3', 'mt-3 mb-1 font-semibold first:mt-0'),
    h4: styled('h4', 'mt-3 mb-1 font-semibold first:mt-0'),
    p: styled('p', 'my-2 first:mt-0 last:mb-0'),
    a: styled(
        'a',
        'font-medium underline underline-offset-2 hover:text-foreground/80',
        { target: '_blank', rel: 'noreferrer' },
    ),
    ul: styled('ul', 'my-2 list-disc space-y-1 pl-5'),
    ol: styled('ol', 'my-2 list-decimal space-y-1 pl-5'),
    blockquote: styled(
        'blockquote',
        'my-2 border-l-2 pl-3 text-muted-foreground',
    ),
    hr: styled('hr', 'my-4 border-border'),
    pre: styled(
        'pre',
        'my-2 overflow-x-auto rounded-lg bg-muted p-3 text-xs leading-normal [&>code]:bg-transparent [&>code]:p-0',
    ),
    code: Code,
    table: styled('table', 'my-2 block w-full overflow-x-auto text-left'),
    th: styled('th', 'border-b px-2 py-1 font-semibold'),
    td: styled('td', 'border-b px-2 py-1'),
};

/**
 * Renders agent-written markdown (GitHub flavored). Raw HTML is shown as text, never rendered.
 */
export default function Markdown({
    content,
    className,
    ...props
}: { content: string } & ComponentProps<'div'>) {
    return (
        <div className={cn('min-w-0 break-words', className)} {...props}>
            <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
                {content}
            </ReactMarkdown>
        </div>
    );
}
