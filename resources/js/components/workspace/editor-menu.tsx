import { ChevronDown, SquareCode } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { DesktopEditor } from '@/types/desktop';

const EDITORS: { id: DesktopEditor; label: string }[] = [
    { id: 'vscode', label: 'VS Code' },
    { id: 'cursor', label: 'Cursor' },
];

/**
 * "Open in editor" in the project's header, in the desktop app, for its owner (DESK-008): the app sets up SSH and
 * opens the editor on the sandbox. Nothing in a browser, which can't.
 */
export default function EditorMenu({
    projectId,
    alias,
    running,
}: {
    projectId: number;
    /** The project's SSH name; null when the user isn't its owner. */
    alias: string | null;
    running: boolean;
}) {
    const desktop =
        typeof window === 'undefined' ? null : window.onedropDesktop;

    if (!desktop || !alias) {
        return null;
    }

    const open = (editor: DesktopEditor) =>
        desktop.editor
            .open(projectId, alias, editor)
            .catch((error: Error) => toast.error(error.message));

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={!running}
                    data-test="editor-menu"
                >
                    <SquareCode /> Open in editor
                    <ChevronDown className="text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {EDITORS.map((editor) => (
                    <DropdownMenuItem
                        key={editor.id}
                        onSelect={() => void open(editor.id)}
                        data-test={`editor-menu-${editor.id}`}
                    >
                        {editor.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
