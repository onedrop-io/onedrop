<?php

namespace App\Sandbox;

/**
 * Freezing and thawing a sandbox's app while an update copies its files (SBX-002): only start.sh and what it started
 * (the app, the Shell, sshd, the proxy), never every process the sandbox user has. A provider's own agent can run as
 * that user, and freezing it stops every later command, the thaw included (Runtime: every exec refused as busy).
 */
final class AppProcesses
{
    /** Sets $tree to start.sh and all its descendants. The [/] keeps the script from finding itself. */
    protected const TREE = 'root=$(pgrep -o -f "[/]opt/onedrop/start.sh"); [ -n "$root" ] || exit 0; '
        .'tree=$root; next=$root; '
        .'while [ -n "$next" ]; do kids=""; for p in $next; do kids="$kids $(pgrep -P "$p" | tr "\n" " ")"; done; next=$(echo $kids); tree="$tree $next"; done; ';

    /** Stop the app's processes (not this script). */
    public const FREEZE = self::TREE.'for pid in $tree; do [ "$pid" = $$ ] || kill -STOP "$pid" 2>/dev/null; done; exit 0';

    /** Let them carry on. */
    public const THAW = self::TREE.'for pid in $tree; do kill -CONT "$pid" 2>/dev/null; done; exit 0';
}
