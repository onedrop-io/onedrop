<?php

namespace App\Sandbox;

use App\Models\Sandbox;

/**
 * Incremental reads of the app dev server's log inside a sandbox.
 */
class ServerLog
{
    public const PATH = '/tmp/zap-server.log';

    /** Bytes returned on first open, and the most returned per read. */
    public const MAX_BYTES = 64_000;

    public function __construct(protected SandboxProvider $provider) {}

    /**
     * Output after byte $offset (or the recent tail when null), with the offset to ask for next.
     *
     * @return array{content: string, offset: int}
     *
     * @throws SandboxException
     */
    public function read(Sandbox $sandbox, ?int $offset): array
    {
        if ($offset === null) {
            $result = $this->provider->exec($sandbox->external_id, [
                'sh', '-c', 'wc -c < '.self::PATH.' && tail -c '.self::MAX_BYTES.' '.self::PATH,
            ]);

            $this->ensure($result);
            [$size, $content] = array_pad(explode("\n", $result->output, 2), 2, '');

            return ['content' => $content, 'offset' => (int) trim($size)];
        }

        $result = $this->provider->exec($sandbox->external_id, [
            'tail', '--bytes', '+'.($offset + 1), self::PATH,
        ]);

        $this->ensure($result);
        $content = $result->output;

        // Keep the browser responsive after a burst of output: skip to the latest part.
        $skipped = max(0, strlen($content) - self::MAX_BYTES);

        return ['content' => substr($content, $skipped), 'offset' => $offset + strlen($content)];
    }

    protected function ensure(ExecResult $result): void
    {
        if (! $result->successful()) {
            throw new SandboxException("Couldn't read the app's log.");
        }
    }
}
