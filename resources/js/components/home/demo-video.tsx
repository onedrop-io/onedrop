import { Play } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { DEMO_VIDEO_URL } from '@/lib/links';

/** A hero button that opens the product demo video in a dialog; it starts playing on open and stops when closed. */
export function DemoVideo({ brand }: { brand: string }) {
    return (
        <Dialog>
            <DialogTrigger
                data-test="watch-demo-video"
                className="inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3.5 font-semibold text-[#F5EFEA] ring-1 ring-[#3A302B] hover:bg-[#151110] focus-visible:ring-2 focus-visible:ring-[#FF9A5C] focus-visible:outline-none"
            >
                <Play className="size-4 fill-current" aria-hidden="true" />
                Watch the demo
            </DialogTrigger>
            <DialogContent className="gap-0 overflow-hidden border-[#2A2320] bg-black p-0 text-[#F5EFEA] sm:max-w-5xl [&>button]:top-3 [&>button]:right-3 [&>button]:z-10 [&>button]:rounded-full [&>button]:bg-black/60 [&>button]:p-1.5">
                <DialogTitle className="sr-only">{brand} demo</DialogTitle>
                <DialogDescription className="sr-only">
                    A video walkthrough of building and publishing an app with{' '}
                    {brand}.
                </DialogDescription>
                <video
                    data-test="demo-video"
                    src={DEMO_VIDEO_URL}
                    controls
                    autoPlay
                    playsInline
                    preload="metadata"
                    className="aspect-video w-full bg-black"
                />
            </DialogContent>
        </Dialog>
    );
}
