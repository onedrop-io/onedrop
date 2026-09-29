import { cn } from "@/lib/utils";
import type { SidebarProject } from "@/types";

type AvatarState = "working" | "live" | "failed" | null;

const badges: Record<
    Exclude<AvatarState, null>,
    { className: string; label: string }
> = {
    working: { className: "bg-amber-500", label: "Agent working" },
    live: { className: "bg-emerald-500", label: "Published" },
    failed: { className: "bg-red-500", label: "Sandbox failed" },
};

/** What the project's badge shows: a failure beats work in progress, which beats being live. */
export function avatarState(project: SidebarProject): AvatarState {
    if (project.failed) {
        return "failed";
    }

    if (project.working) {
        return "working";
    }

    return project.published_url ? "live" : null;
}

/**
 * The project's icon (or, until it has one, a colored tile with its initial) with a status badge in its corner.
 * The tile's color comes from the project's id, so a project keeps its color when it's renamed.
 */
export function ProjectAvatar({
    project,
    className,
}: {
    project: SidebarProject;
    className?: string;
}) {
    const state = avatarState(project);
    const hue = Math.round((project.id * 137.508) % 360);

    return (
        <span
            className={cn("relative flex size-5 shrink-0", className)}
            data-test="sidebar-project-avatar"
        >
            {project.icon_url ? (
                <img
                    src={project.icon_url}
                    alt=""
                    className="size-full rounded-md object-contain"
                    data-test="sidebar-project-icon"
                />
            ) : (
                <span
                    className={cn(
                        "flex size-full items-center justify-center rounded-md text-[11px] font-semibold text-white uppercase shadow-xs",
                        project.drawing_icon && "animate-pulse",
                    )}
                    style={{
                        background: `linear-gradient(135deg, oklch(0.68 0.15 ${hue}), oklch(0.55 0.17 ${(hue + 40) % 360}))`,
                    }}
                    aria-hidden
                >
                    {project.name.trim().charAt(0) || "?"}
                </span>
            )}
            {state && (
                <span
                    className="absolute -right-0.5 -bottom-0.5 flex size-2.5"
                    role="img"
                    aria-label={badges[state].label}
                    data-test={`sidebar-project-${state}`}
                >
                    {state === "working" && (
                        <span
                            className={cn(
                                "absolute inline-flex size-full animate-ping rounded-full opacity-75",
                                badges[state].className,
                            )}
                        />
                    )}
                    <span
                        className={cn(
                            "relative inline-flex size-full rounded-full ring-2 ring-sidebar",
                            badges[state].className,
                        )}
                    />
                </span>
            )}
        </span>
    );
}
