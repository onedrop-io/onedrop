import type { WaitingFor } from '@/types';

/** What the sidebar and the board show under a chat whose agent is waiting for the user (PRJ-011). */
export const WAITING_LABELS: Record<WaitingFor, string> = {
    question: 'Needs your answer',
    needs_input: 'Needs something from you',
    blocked: 'Stuck',
};

/** The desktop notification's text when a project's agent is done (NOTIF-001) or waiting for the user (PRJ-011). */
export function readyNotificationBody(waitingFor: WaitingFor | null): string {
    switch (waitingFor) {
        case 'question':
            return 'Needs your answer';
        case 'needs_input':
            return 'Needs something from you';
        case 'blocked':
            return 'Got stuck and needs your help';
        default:
            return 'Ready for your review';
    }
}
