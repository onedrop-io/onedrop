import { Button } from '@/components/ui/button';
import { openInBrowser } from '../lib/native';
import type { Me } from '../lib/types';
import Logo from './logo';
import TitleBar from './title-bar';

/** Signed in, but without an AI to build with yet: that's set up on the web (AI-001), then the app carries on. */
export default function NeedsAi({
    me,
    onDone,
    onSignOut,
}: {
    me: Me;
    onDone: () => void;
    onSignOut: () => void;
}) {
    return (
        <div className="flex h-screen flex-col">
            <TitleBar />
            <div className="flex flex-1 flex-col items-center justify-center gap-6 p-8 text-center">
                <Logo logo={me.app.logo} className="size-10" />
                <div className="max-w-sm space-y-2">
                    <h1 className="font-display text-2xl font-semibold">
                        Connect an AI first
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Your agent runs on your own AI account: a Claude or
                        ChatGPT plan, or an API key. Set it up in your browser,
                        then come back here.
                    </p>
                </div>
                <div className="flex flex-col gap-2">
                    <Button
                        onClick={() =>
                            void openInBrowser(
                                new URL(
                                    '/onboarding/ai',
                                    me.app.url,
                                ).toString(),
                            )
                        }
                    >
                        Set up AI in the browser
                    </Button>
                    <Button
                        variant="outline"
                        onClick={onDone}
                        data-test="ai-done"
                    >
                        I've set it up
                    </Button>
                    <Button variant="ghost" onClick={onSignOut}>
                        Sign out ({me.user.email})
                    </Button>
                </div>
            </div>
        </div>
    );
}
