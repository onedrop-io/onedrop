import { ExternalLink, GitPullRequestArrow } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import ProjectGitController from "@/actions/App/Http/Controllers/ProjectGitController";
import { Button } from "@/components/ui/button";
import {
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { DEFAULT_BRANCHES } from "@/components/workspace/git-state";
import { jsonRequest } from "@/lib/json-request";

type Draft = { title: string; body: string; commits: number; url: string };

/** The branch a pull request goes into by default: `main` or `master` when there is one. */
export function defaultBase(branches: string[]): string | null {
    return (
        DEFAULT_BRANCHES.find((branch) => branches.includes(branch)) ??
        branches[0] ??
        null
    );
}

/**
 * Open a pull request on GitHub (GIT-007): pick the base, check the title and description the project's AI wrote,
 * and GitHub's new pull request page opens filled in.
 */
export default function PullRequestDialog({
    projectId,
    branch,
    bases,
    onClose,
}: {
    projectId: number;
    branch: string;
    /** Branches the pull request can go into (every other branch). */
    bases: string[];
    onClose: () => void;
}) {
    const [base, setBase] = useState(() => defaultBase(bases) ?? "");
    const [draft, setDraft] = useState<Draft | null>(null);
    const [title, setTitle] = useState("");
    const [body, setBody] = useState("");
    const [error, setError] = useState<string | null>(null);
    const titleField = useRef<HTMLInputElement>(null);

    useEffect(() => {
        let cancelled = false;

        setDraft(null);
        setError(null);
        jsonRequest<Draft>(ProjectGitController.pullRequest.url(projectId), {
            base,
        })
            .then((written) => {
                if (cancelled) {
                    return;
                }

                setDraft(written);
                setTitle(written.title);
                setBody(written.body);
            })
            .catch((e: Error) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [projectId, base]);

    // Written: put the cursor in the title to check it.
    useEffect(() => {
        if (draft) {
            titleField.current?.focus();
        }
    }, [draft]);

    const open = (event: FormEvent) => {
        event.preventDefault();

        if (!draft || title.trim() === "") {
            return;
        }

        const query = new URLSearchParams({
            expand: "1",
            title: title.trim(),
            body: body.trim(),
        });

        window.open(`${draft.url}?${query}`, "_blank", "noopener");
        onClose();
    };

    return (
        <DialogContent className="sm:max-w-2xl" data-test="git-pr-dialog">
            <form onSubmit={open} className="grid min-w-0 gap-5">
                <DialogHeader>
                    <DialogTitle>Create pull request</DialogTitle>
                    <DialogDescription>
                        AI wrote the title and description from the branch's
                        commits. Change anything, then finish on GitHub.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <GitPullRequestArrow className="size-4 text-muted-foreground" />
                    <span
                        className="font-mono font-medium"
                        data-test="git-pr-branch"
                    >
                        {branch}
                    </span>
                    <span className="text-muted-foreground">into</span>
                    <Select value={base} onValueChange={setBase}>
                        <SelectTrigger
                            className="h-8 w-auto min-w-28 font-mono"
                            data-test="git-pr-base"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {bases.map((option) => (
                                <SelectItem
                                    key={option}
                                    value={option}
                                    className="font-mono"
                                >
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {draft && (
                        <span
                            className="text-muted-foreground"
                            data-test="git-pr-commits"
                        >
                            · {draft.commits}{" "}
                            {draft.commits === 1 ? "commit" : "commits"}
                        </span>
                    )}
                </div>

                {error ? (
                    <p
                        className="text-sm text-red-600"
                        data-test="git-pr-error"
                    >
                        {error}
                    </p>
                ) : !draft ? (
                    <div className="space-y-3" data-test="git-pr-writing">
                        <p className="text-sm text-muted-foreground">
                            Writing the pull request…
                        </p>
                        <Skeleton className="h-9 w-full animate-pulse" />
                        <Skeleton className="h-48 w-full animate-pulse" />
                    </div>
                ) : (
                    <div className="space-y-3">
                        <div className="space-y-2">
                            <label
                                htmlFor="git-pr-title"
                                className="text-sm font-medium"
                            >
                                Title
                            </label>
                            <Input
                                id="git-pr-title"
                                ref={titleField}
                                value={title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                                data-test="git-pr-title"
                            />
                        </div>
                        <div className="space-y-2">
                            <label
                                htmlFor="git-pr-body"
                                className="text-sm font-medium"
                            >
                                Description
                            </label>
                            <textarea
                                id="git-pr-body"
                                rows={10}
                                value={body}
                                onChange={(event) =>
                                    setBody(event.target.value)
                                }
                                className="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs leading-5 shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                data-test="git-pr-body"
                            />
                        </div>
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        disabled={!draft || title.trim() === ""}
                        data-test="git-pr-open"
                    >
                        <ExternalLink className="size-4" />
                        Open on GitHub
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
