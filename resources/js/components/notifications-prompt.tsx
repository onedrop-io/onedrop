import { Bell } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useDesktopNotifications } from "@/hooks/use-desktop-notifications";

/** Offers desktop notifications while the agent works, until they're on or the user says "Not now". */
export default function NotificationsPrompt() {
    const { status, dismissed, enable, dismissPrompt } =
        useDesktopNotifications();

    if (status !== "off" || dismissed) {
        return null;
    }

    return (
        <div
            className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2 text-sm"
            data-test="notifications-prompt"
        >
            <Bell className="size-4 shrink-0 text-muted-foreground" />
            <span className="flex-1">
                Get a desktop notification when it’s ready?
            </span>
            <div className="flex gap-1">
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={dismissPrompt}
                    data-test="notifications-prompt-dismiss"
                >
                    Not now
                </Button>
                <Button
                    size="sm"
                    onClick={() => void enable()}
                    data-test="notifications-prompt-enable"
                >
                    Turn on
                </Button>
            </div>
        </div>
    );
}
