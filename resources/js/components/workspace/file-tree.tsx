import { ChevronRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import FileEntryMenu, {
    DeleteEntryDialog,
    RenameEntryDialog,
} from '@/components/workspace/file-entry-menu';
import type { FileEntryAction } from '@/components/workspace/file-entry-menu';
import FileIcon from '@/components/workspace/file-icon';
import { NewEntryDialog } from '@/components/workspace/files-menu';
import { useClipboard } from '@/hooks/use-clipboard';
import { downloadWorkspace } from '@/hooks/use-workspace-files';
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

/** Whether `path` is `folder` or inside it. */
export function isWithin(path: string, folder: string): boolean {
    return path === folder || path.startsWith(`${folder}/`);
}

/**
 * The entries inside `folder` (all of them for '') whose name has `query` in it, with the folders above them
 * so the tree can show where each one is.
 */
export function searchEntries(
    entries: WorkspaceEntry[],
    folder: string,
    query: string,
): WorkspaceEntry[] {
    const needle = query.trim().toLowerCase();
    const matches = new Set<string>();

    for (const entry of entries) {
        const name = entry.path.slice(entry.path.lastIndexOf('/') + 1);

        if (
            (folder === '' || entry.path.startsWith(`${folder}/`)) &&
            name.toLowerCase().includes(needle)
        ) {
            matches.add(entry.path);

            for (
                let slash = entry.path.lastIndexOf('/');
                slash !== -1;
                slash = entry.path.lastIndexOf('/', slash - 1)
            ) {
                matches.add(entry.path.slice(0, slash));
            }
        }
    }

    return entries.filter((entry) => matches.has(entry.path));
}

export default function FileTree({
    projectId,
    entries,
    selected,
    onSelect,
    expandAll = false,
    onChanged,
    onRenamed,
    onDeleted,
    onSearch,
    onOpenShell,
}: {
    projectId: number;
    entries: WorkspaceEntry[];
    selected: string | null;
    onSelect: (path: string) => void;
    /** Show every folder open (while searching). */
    expandAll?: boolean;
    /** Files changed; refresh the tree. */
    onChanged: () => void;
    onRenamed: (from: string, to: string) => void;
    onDeleted: (path: string) => void;
    /** Search file names inside a folder. */
    onSearch: (folder: string) => void;
    /** Open the Shell in a folder; missing when there's no shell to open. */
    onOpenShell?: (folder: string) => void;
}) {
    const tree = useMemo(() => buildTree(entries), [entries]);
    const [open, setOpen] = useState<Set<string>>(new Set());
    const [menuFor, setMenuFor] = useState<string | null>(null);
    const [creating, setCreating] = useState<{
        type: WorkspaceEntry['type'];
        parent: string;
    } | null>(null);
    const [renaming, setRenaming] = useState<WorkspaceEntry | null>(null);
    const [deleting, setDeleting] = useState<WorkspaceEntry | null>(null);
    const [, copy] = useClipboard();

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

    const copyText = (text: string, what: string) =>
        void copy(text).then((copied) =>
            copied
                ? toast.success(`Copied the ${what}.`)
                : toast.error(`Couldn't copy the ${what}.`),
        );

    const run = (entry: WorkspaceEntry, action: FileEntryAction) => {
        const parent = entry.path.includes('/')
            ? entry.path.slice(0, entry.path.lastIndexOf('/'))
            : '';

        switch (action) {
            case 'rename':
                return setRenaming(entry);
            case 'search':
                return onSearch(entry.path);
            case 'new-file':
            case 'new-folder':
                return setCreating({
                    type: action === 'new-file' ? 'file' : 'dir',
                    parent: entry.path,
                });
            case 'collapse':
                return setOpen(
                    (current) =>
                        new Set(
                            [...current].filter(
                                (path) => !path.startsWith(`${entry.path}/`),
                            ),
                        ),
                );
            case 'shell':
                return onOpenShell?.(
                    entry.type === 'dir' ? entry.path : parent,
                );
            case 'copy-path':
                return copyText(entry.path, 'path');
            case 'copy-link': {
                const url = new URL(window.location.href);
                url.searchParams.delete('tool');
                url.searchParams.set('tab', 'file');
                url.searchParams.set('file', entry.path);

                return copyText(url.href, 'link');
            }
            case 'download': {
                const id = toast.loading(
                    entry.type === 'dir'
                        ? `Zipping ${entry.path}…`
                        : `Downloading ${entry.path}…`,
                );

                return void downloadWorkspace(projectId, entry.path)
                    .then(() => toast.dismiss(id))
                    .catch((e: Error) => toast.error(e.message, { id }));
            }
            case 'delete':
                return setDeleting(entry);
        }
    };

    const render = (nodes: Node[], depth: number): React.ReactNode =>
        nodes.map((node) => {
            const isDir = node.type === 'dir';
            const collapsed = isDir && COLLAPSED.has(node.name);
            const isOpen = !collapsed && (expandAll || open.has(node.path));

            return (
                <li key={node.path}>
                    <div
                        className="group relative"
                        onContextMenu={(event) => {
                            event.preventDefault();
                            setMenuFor(node.path);
                        }}
                    >
                        <button
                            type="button"
                            onClick={() =>
                                isDir
                                    ? !collapsed && toggle(node.path)
                                    : onSelect(node.path)
                            }
                            className={cn(
                                'flex w-full items-center gap-1.5 rounded py-1 pr-6 pl-2 text-left text-sm group-hover:bg-muted',
                                selected === node.path &&
                                    'bg-muted font-medium',
                                collapsed &&
                                    'cursor-default text-muted-foreground',
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
                        <FileEntryMenu
                            entry={node}
                            open={menuFor === node.path}
                            onOpenChange={(isMenuOpen) =>
                                setMenuFor(isMenuOpen ? node.path : null)
                            }
                            canOpenShell={onOpenShell !== undefined}
                            onAction={(action) => run(node, action)}
                        />
                    </div>
                    {isDir && isOpen && node.children.length > 0 && (
                        <ul>{render(node.children, depth + 1)}</ul>
                    )}
                </li>
            );
        });

    return (
        <>
            <ul className="py-1">{render(tree, 0)}</ul>

            <NewEntryDialog
                projectId={projectId}
                type={creating?.type ?? null}
                parent={creating?.parent}
                onDone={(path) => {
                    const made = creating;
                    setCreating(null);

                    if (path && made) {
                        setOpen((current) => new Set(current).add(made.parent));
                        onChanged();

                        if (made.type === 'file') {
                            onSelect(path);
                        }
                    }
                }}
            />
            <RenameEntryDialog
                projectId={projectId}
                entry={renaming}
                onDone={(path) => {
                    const from = renaming?.path;
                    setRenaming(null);

                    if (path && from) {
                        setOpen(
                            (current) =>
                                new Set(
                                    [...current].map((open) =>
                                        isWithin(open, from)
                                            ? path + open.slice(from.length)
                                            : open,
                                    ),
                                ),
                        );
                        onRenamed(from, path);
                        onChanged();
                    }
                }}
            />
            <DeleteEntryDialog
                projectId={projectId}
                entry={deleting}
                onDone={(path) => {
                    setDeleting(null);

                    if (path) {
                        onDeleted(path);
                        onChanged();
                    }
                }}
            />
        </>
    );
}
