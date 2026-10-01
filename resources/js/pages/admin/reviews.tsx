import { Head, Link } from '@inertiajs/react';
import AbuseReviewController from '@/actions/App/Http/Controllers/Admin/AbuseReviewController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type Review = {
    id: number;
    status: 'held' | 'approved' | 'taken_down';
    trigger: 'publish' | 'share' | 'recheck';
    score: number | null;
    reasons: { key: string; label: string; probability: number }[];
    evidence: {
        project_name: string;
        prompts: string[];
        page: {
            title: string;
            text: string;
            fields: string[];
            form_actions: string[];
        } | null;
    } | null;
    flagged_at: string | null;
    decided_at: string | null;
    decided_by: string | null;
    project: {
        id: number;
        name: string;
        publish_status: string | null;
        publish_visibility: string | null;
        published_url: string | null;
        share_url: string | null;
        organization: string | null;
    };
    owner: { id: number; name: string; email: string; url: string } | null;
};

const TRIGGERS: Record<Review['trigger'], string> = {
    publish: 'Publishing publicly',
    share: 'Sharing',
    recheck: 'Re-check after changes',
};

const percent = (probability: number) => `${Math.round(probability * 100)}%`;

const when = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString() : null;

/** Apps the hosted install's abuse check held, for platform admins to approve or take down (ADMIN-006). */
export default function AdminReviews({
    enabled,
    threshold,
    held,
    decided,
}: {
    enabled: boolean;
    threshold: number;
    held: Review[];
    decided: Review[];
}) {
    return (
        <>
            <Head title="Reviews" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Reviews"
                    description={`Before an app is published publicly or shared, Jev checks it for phishing, scams and malware, and other clear harm. Apps that look ${percent(threshold)} likely or more to be one wait here until you approve them or take them down.`}
                />

                {!enabled && (
                    <p
                        className="rounded-md bg-muted p-3 text-sm text-muted-foreground"
                        data-test="reviews-disabled"
                    >
                        Checks are off: set OPENROUTER_API_KEY to turn them on.
                    </p>
                )}

                {held.length === 0 ? (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="reviews-empty"
                    >
                        Nothing is waiting for a review.
                    </p>
                ) : (
                    <div className="space-y-4">
                        {held.map((review) => (
                            <ReviewCard key={review.id} review={review} />
                        ))}
                    </div>
                )}
            </div>

            {decided.length > 0 && (
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="Decided"
                        description="The latest apps you or another admin looked at. Approve a taken-down app to let it go public again."
                    />
                    {decided.map((review) => (
                        <ReviewCard key={review.id} review={review} />
                    ))}
                </div>
            )}
        </>
    );
}

function ReviewCard({ review }: { review: Review }) {
    const page = review.evidence?.page;

    return (
        <div
            className="space-y-3 rounded-xl border p-4 text-sm"
            data-test={`review-${review.id}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-1">
                    <p className="font-medium">{review.project.name}</p>
                    <p className="text-muted-foreground">
                        {review.owner ? (
                            <Link
                                href={review.owner.url}
                                className="underline-offset-4 hover:underline"
                            >
                                {review.owner.name} ({review.owner.email})
                            </Link>
                        ) : (
                            'Deleted user'
                        )}
                        {review.project.organization &&
                            ` · ${review.project.organization}`}
                        {` · ${TRIGGERS[review.trigger]}`}
                        {review.flagged_at && ` · ${when(review.flagged_at)}`}
                    </p>
                </div>
                {review.status === 'held' ? (
                    <Badge variant="secondary">Waiting</Badge>
                ) : (
                    <Badge
                        variant={
                            review.status === 'taken_down'
                                ? 'destructive'
                                : 'outline'
                        }
                    >
                        {review.status === 'taken_down'
                            ? 'Taken down'
                            : 'Approved'}
                        {review.decided_by && ` by ${review.decided_by}`}
                    </Badge>
                )}
            </div>

            <ul className="space-y-1">
                {review.reasons.map((reason) => (
                    <li key={reason.key} className="flex justify-between gap-4">
                        <span>{reason.label}</span>
                        <span className="text-muted-foreground tabular-nums">
                            {percent(reason.probability)}
                        </span>
                    </li>
                ))}
            </ul>

            {review.evidence && (
                <details className="rounded-md bg-muted/50 p-3">
                    <summary className="cursor-pointer text-muted-foreground">
                        What Jev saw
                    </summary>
                    <div className="mt-2 space-y-2 break-words">
                        <p>
                            <span className="text-muted-foreground">
                                Prompts:{' '}
                            </span>
                            {review.evidence.prompts.join(' / ') || 'None'}
                        </p>
                        {page ? (
                            <>
                                <p>
                                    <span className="text-muted-foreground">
                                        Page title:{' '}
                                    </span>
                                    {page.title || 'None'}
                                </p>
                                <p>
                                    <span className="text-muted-foreground">
                                        Text:{' '}
                                    </span>
                                    {page.text || 'None'}
                                </p>
                                {page.fields.length > 0 && (
                                    <p>
                                        <span className="text-muted-foreground">
                                            Fields:{' '}
                                        </span>
                                        {page.fields.join(', ')}
                                    </p>
                                )}
                                {page.form_actions.length > 0 && (
                                    <p>
                                        <span className="text-muted-foreground">
                                            Forms send to:{' '}
                                        </span>
                                        {page.form_actions.join(', ')}
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                The home page couldn't be read.
                            </p>
                        )}
                    </div>
                </details>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {review.status !== 'approved' && (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={AbuseReviewController.approve(review.id)}
                            as="button"
                            preserveScroll
                            data-test={`approve-${review.id}`}
                        >
                            Approve
                        </Link>
                    </Button>
                )}
                {review.status !== 'taken_down' && (
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                data-test={`take-down-${review.id}`}
                            >
                                Take down
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>
                                Take down {review.project.name}?
                            </DialogTitle>
                            <DialogDescription>
                                Its public app goes offline and its share page
                                is deleted. The owner can keep building and
                                publish privately, but not publicly or share it
                                until an admin approves it.
                            </DialogDescription>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <DialogClose asChild>
                                    <Button variant="destructive" asChild>
                                        <Link
                                            href={AbuseReviewController.takeDown(
                                                review.id,
                                            )}
                                            as="button"
                                            preserveScroll
                                            data-test={`take-down-${review.id}-confirm`}
                                        >
                                            Take down
                                        </Link>
                                    </Button>
                                </DialogClose>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                )}
            </div>
        </div>
    );
}
