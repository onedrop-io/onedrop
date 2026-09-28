import { ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import FileIcon from '@/components/workspace/file-icon';
import { cn } from '@/lib/utils';
import type { WorkspaceEntry } from '@/types';

/** Folders the server lists but never expands. */
const COLLAPSED = new Set(['node_modules', '.git', 'vendor', '.cache']);

type Node = {
    name: string;
    path: string;
    type: 'file' | 'dir';
    children: Node[];
};

function buildTree(entries: WorkspaceEntry[]): Node[] {
    const root: Node = { name: '', path: '', type: 'dir', children: [] };
    const dirs = new Map<string, Node>([['', root]]);

    for (const entry of entries) {
        const slash = entry.path.lastIndexOf('/');
        const parentPath = slash === -1 ? '' : entry.path.slice(0, slash);
        const node: Node = {
            name: entry.path.slice(slash + 1),
            path: entry.path,
            type: entry.type,
            children: [],
        };

        (dirs.get(parentPath) ?? root).children.push(node);

        if (entry.type === 'dir') {
            dirs.set(entry.path, node);
        }
    }

    const sort = (nodes: Node[]) => {
        nodes.sort((a, b) =>
            a.type === b.type
                ? a.name.localeCompare(b.name, undefined, { numeric: true })
                : a.type === 'dir'
                  ? -1
                  : 1,
        );
        nodes.forEach((node) => sort(node.children));
    };
    sort(root.children);

    return root.children;
}

export default function FileTree({
    entries,
    selected,
    onSelect,
}: {
    entries: WorkspaceEntry[];
    selected: string | null;
    onSelect: (path: string) => void;
}) {
    const tree = useMemo(() => buildTree(entries), [entries]);
    const [open, setOpen] = useState<Set<string>>(new Set());

    const toggle = (path: string) =>
        setOpen((current) => {
            const next = new Set(current);

            if (next.has(path)) {
                next.delete(path);
            } else {
                next.add(path);
            }

            return next;
        });

    const render = (nodes: Node[], depth: number): React.ReactNode =>
        nodes.map((node) => {
            const isDir = node.type === 'dir';
            const isOpen = open.has(node.path);
            const collapsed = isDir && COLLAPSED.has(node.name);

            return (
                <li key={node.path}>
                    <button
                        type="button"
                        onClick={() =>
                            isDir
                                ? !collapsed && toggle(node.path)
                                : onSelect(node.path)
                        }
                        className={cn(
                            'flex w-full items-center gap-1.5 rounded px-2 py-1 text-left text-sm hover:bg-muted',
                            selected === node.path && 'bg-muted font-medium',
                            collapsed && 'cursor-default text-muted-foreground',
                        )}
                        style={{ paddingLeft: `${depth * 12 + 8}px` }}
                        title={node.path}
                        data-test={`file-${node.path}`}
                    >
                        <ChevronRight
                            className={cn(
                                'size-3 shrink-0 text-muted-foreground transition-transform',
                                (!isDir || collapsed) && 'invisible',
                                isOpen && 'rotate-90',
                            )}
                        />
                        <FileIcon
                            name={node.name}
                            isDir={isDir}
                            isOpen={isOpen}
                            className={cn(collapsed && 'opacity-60')}
                        />
                        <span className="truncate">{node.name}</span>
                    </button>
                    {isDir && isOpen && node.children.length > 0 && (
                        <ul>{render(node.children, depth + 1)}</ul>
                    )}
                </li>
            );
        });

    return <ul className="py-1">{render(tree, 0)}</ul>;
}
