import type { AgentHarness } from '@/types/agents';

/** Each agent's color in usage charts (USAGE-001). */
export const HARNESS_COLORS: Record<AgentHarness, string> = {
    claude_code: 'var(--viz-2)',
    opencode: 'var(--viz-1)',
    codex: 'var(--viz-3)',
};

export const HARNESS_LABELS: Record<AgentHarness, string> = {
    claude_code: 'Claude Code',
    opencode: 'OpenCode',
    codex: 'Codex',
};

export function formatCost(value: number): string {
    if (value > 0 && value < 0.01) {
        return '<$0.01';
    }

    return value.toLocaleString(undefined, {
        style: 'currency',
        currency: 'USD',
    });
}

export function formatTokens(value: number): string {
    return value.toLocaleString(undefined, {
        notation: 'compact',
        maximumFractionDigits: value >= 1000 ? 2 : 0,
    });
}
