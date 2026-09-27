import type { WorkspaceFile } from '@/types';

export default function FileViewer({
    file,
    error,
}: {
    file: WorkspaceFile | null;
    error: string | null;
}) {
    if (error) {
        return <p className="p-4 text-sm text-red-600">{error}</p>;
    }

    if (!file) {
        return <p className="p-4 text-sm text-muted-foreground">Loading…</p>;
    }

    if (file.notice) {
        return (
            <p
                className="p-4 text-sm text-muted-foreground"
                data-test="file-notice"
            >
                {file.notice}
            </p>
        );
    }

    const lines = (file.content ?? '').replace(/\n$/, '').split('\n');

    return (
        <div className="flex-1 overflow-auto" data-test="file-viewer">
            <table className="font-mono text-xs leading-5">
                <tbody>
                    {lines.map((line, index) => (
                        <tr key={index}>
                            <td className="sticky left-0 bg-background px-3 text-right text-muted-foreground/60 select-none">
                                {index + 1}
                            </td>
                            <td className="pr-4 whitespace-pre">
                                {line || ' '}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
