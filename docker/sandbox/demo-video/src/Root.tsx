import { Composition } from 'remotion';
import { Demo, FPS, totalFrames } from './Demo';
import type { DemoProps } from './Demo';

const placeholder: DemoProps = {
    title: 'My app',
    tagline: '',
    accent: '#6366f1',
    address: null,
    icon: null,
    base: '',
    scenes: [],
};

export function Root() {
    return (
        <Composition
            id="Demo"
            component={Demo}
            fps={FPS}
            width={1920}
            height={1080}
            durationInFrames={totalFrames(placeholder)}
            defaultProps={placeholder}
            calculateMetadata={({ props }) => ({
                durationInFrames: totalFrames(props),
            })}
        />
    );
}
