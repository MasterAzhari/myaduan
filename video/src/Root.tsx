import React from 'react';
import {Composition, Series} from 'remotion';
import {SceneComp} from './Explainer';
import scenes from './scenes.json';

const FPS = 30;

const Full: React.FC = () => (
  <Series>
    {scenes.map((s) => (
      <Series.Sequence key={s.id} durationInFrames={s.dur * FPS}>
        <SceneComp id={s.id} />
      </Series.Sequence>
    ))}
  </Series>
);

export const Root: React.FC = () => (
  <>
    {scenes.map((s) => (
      <Composition key={s.id} id={s.id} component={SceneComp} defaultProps={{id: s.id}} durationInFrames={s.dur * FPS} fps={FPS} width={1920} height={1080} />
    ))}
    <Composition id="Penuh" component={Full} durationInFrames={scenes.reduce((a, s) => a + s.dur, 0) * FPS} fps={FPS} width={1920} height={1080} />
  </>
);
