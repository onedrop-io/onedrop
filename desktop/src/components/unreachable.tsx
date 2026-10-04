import { Button } from '@/components/ui/button';

/** The server couldn't be reached (offline, or it's down): try again, or sign out to pick another. */
export default function Unreachable({
    server,
    message,
    onRetry,
    onSignOut,
}: {
    server: string;
    message: string;
    onRetry: () => void;
    onSignOut: () => void;
}) {
    return (
        <div className="flex h-screen flex-col items-center justify-center gap-4 p-8 text-center">
            <div className="max-w-sm space-y-1">
                <h1 className="font-medium">
                    Can't reach {new URL(server).host}
                </h1>
                <p
                    className="text-sm text-muted-foreground"
                    data-test="unreachable"
                >
                    {message}
                </p>
            </div>
            <div className="flex gap-2">
                <Button onClick={onRetry}>Try again</Button>
                <Button variant="ghost" onClick={onSignOut}>
                    Sign out
                </Button>
            </div>
        </div>
    );
}
