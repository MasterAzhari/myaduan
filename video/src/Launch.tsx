import React from 'react';
import {
  AbsoluteFill,
  Audio,
  Easing,
  Img,
  interpolate,
  random,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';

const CRIMSON = '#b3122b';
const GOLD = '#d4a017';
const GOLD_LIGHT = '#f6d572';
const CREAM = '#fbf3dc';
const FONT = 'Inter, "Inter Display", system-ui, sans-serif';

const BOOM = 30; // 1.0s – logo slam
const TEXT = 66; // 2.2s – wordmark
const STAMP = 114; // 3.8s – "dilancarkan"

const clamp = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;

const Background: React.FC<{f: number}> = ({f}) => {
  const lift = interpolate(f, [BOOM - 2, BOOM + 10], [0, 1], clamp);
  const inner = `rgba(${Math.round(90 + 60 * lift)}, ${Math.round(10 + 8 * lift)}, ${Math.round(24 + 10 * lift)}, 1)`;
  return (
    <AbsoluteFill
      style={{background: `radial-gradient(circle at 50% 45%, ${inner} 0%, #3a0610 45%, #12030a 100%)`}}
    />
  );
};

// Gold sparks pulled into the centre before the boom.
const Converge: React.FC<{f: number}> = ({f}) => {
  if (f >= BOOM + 2) return null;
  return (
    <AbsoluteFill>
      {Array.from({length: 90}).map((_, i) => {
        const delay = random(`cd${i}`) * 12;
        const p = interpolate(f, [delay, BOOM], [0, 1], {...clamp, easing: Easing.in(Easing.cubic)});
        const ang = random(`ca${i}`) * Math.PI * 2;
        const r0 = 700 + random(`cr${i}`) * 700;
        const r = r0 * (1 - p);
        const len = 20 + 120 * p;
        const x = 960 + Math.cos(ang) * r;
        const y = 540 + Math.sin(ang) * r;
        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: x,
              top: y,
              width: len,
              height: 3,
              borderRadius: 3,
              background: `linear-gradient(90deg, ${GOLD_LIGHT}, rgba(212,160,23,0))`,
              transform: `rotate(${(ang * 180) / Math.PI}deg)`,
              transformOrigin: '0 50%',
              opacity: p > 0 ? Math.min(1, p * 3) : 0,
              boxShadow: `0 0 8px ${GOLD}`,
            }}
          />
        );
      })}
    </AbsoluteFill>
  );
};

// Touch ripples + glowing core during the charge-up.
const Core: React.FC<{f: number}> = ({f}) => {
  if (f >= BOOM + 1) return null;
  const size = interpolate(f, [0, BOOM], [30, 220], {...clamp, easing: Easing.in(Easing.quad)});
  const pulse = 1 + 0.08 * Math.sin(f * 1.3);
  return (
    <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
      {[0, 6, 12].map((d) => {
        const p = interpolate(f, [d, d + 18], [0, 1], clamp);
        return (
          <div
            key={d}
            style={{
              position: 'absolute',
              width: 80 + p * 520,
              height: 80 + p * 520,
              borderRadius: '50%',
              border: `4px solid ${GOLD_LIGHT}`,
              opacity: p > 0 && p < 1 ? 1 - p : 0,
            }}
          />
        );
      })}
      <div
        style={{
          width: size * pulse,
          height: size * pulse,
          borderRadius: '50%',
          background: `radial-gradient(circle, #fff 0%, ${GOLD_LIGHT} 35%, rgba(212,160,23,0) 70%)`,
          filter: 'blur(2px)',
        }}
      />
    </AbsoluteFill>
  );
};

const Flash: React.FC<{f: number; at: number; peak?: number}> = ({f, at, peak = 1}) => {
  const o = interpolate(f, [at - 1, at, at + 10], [0, peak, 0], clamp);
  return <AbsoluteFill style={{background: '#fff8e6', opacity: o}} />;
};

const Rays: React.FC<{f: number; cy: number; scale: number}> = ({f, cy, scale}) => {
  const o = interpolate(f, [BOOM, BOOM + 8], [0, 0.55], clamp);
  return (
    <div
      style={{
        position: 'absolute',
        left: 960 - 1100,
        top: cy - 1100,
        width: 2200,
        height: 2200,
        opacity: o,
        transform: `rotate(${f * 0.5}deg) scale(${scale})`,
        background: `repeating-conic-gradient(rgba(246,213,114,0.38) 0deg 5deg, rgba(246,213,114,0) 5deg 15deg)`,
        WebkitMaskImage: 'radial-gradient(circle, #000 8%, rgba(0,0,0,0) 55%)',
        maskImage: 'radial-gradient(circle, #000 8%, rgba(0,0,0,0) 55%)',
      }}
    />
  );
};

const Shockwaves: React.FC<{f: number}> = ({f}) => (
  <AbsoluteFill>
    {[0, 4, 9].map((d, i) => {
      const p = interpolate(f, [BOOM + d, BOOM + d + 26], [0, 1], {...clamp, easing: Easing.out(Easing.cubic)});
      const s = p * 2400;
      return (
        <div
          key={d}
          style={{
            position: 'absolute',
            left: 960 - s / 2,
            top: 540 - s / 2,
            width: s,
            height: s,
            borderRadius: '50%',
            border: `${10 - i * 3}px solid ${i === 1 ? CRIMSON : GOLD_LIGHT}`,
            opacity: p > 0 && p < 1 ? (1 - p) * 0.9 : 0,
            boxShadow: `0 0 40px ${GOLD}`,
          }}
        />
      );
    })}
  </AbsoluteFill>
);

// Radial particle burst; used for the boom and for fireworks.
const Burst: React.FC<{
  f: number;
  at: number;
  x: number;
  y: number;
  n: number;
  speed: number;
  seed: string;
  colors: string[];
  life?: number;
  gravity?: number;
}> = ({f, at, x, y, n, speed, seed, colors, life = 40, gravity = 0}) => {
  const t = f - at;
  if (t < 0 || t > life) return null;
  return (
    <>
      {Array.from({length: n}).map((_, i) => {
        const ang = random(`${seed}a${i}`) * Math.PI * 2;
        const v = speed * (0.4 + random(`${seed}v${i}`) * 0.6);
        const dist = v * (1 - Math.exp(-t / 9)) * 9;
        const px = x + Math.cos(ang) * dist;
        const py = y + Math.sin(ang) * dist + gravity * t * t;
        const o = interpolate(t, [0, life * 0.6, life], [1, 0.9, 0], clamp);
        const sz = 4 + random(`${seed}s${i}`) * 6;
        const c = colors[Math.floor(random(`${seed}c${i}`) * colors.length)];
        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: px - sz / 2,
              top: py - sz / 2,
              width: sz,
              height: sz,
              borderRadius: '50%',
              background: c,
              opacity: o * (0.7 + 0.3 * Math.sin(t * 1.7 + i)),
              boxShadow: `0 0 ${sz * 2}px ${c}`,
            }}
          />
        );
      })}
    </>
  );
};

const Logo: React.FC<{f: number; fps: number}> = ({f, fps}) => {
  const pop = spring({frame: f - BOOM, fps, config: {damping: 9, stiffness: 140, mass: 0.8}});
  const move = interpolate(f, [TEXT - 6, TEXT + 10], [0, 1], {...clamp, easing: Easing.inOut(Easing.cubic)});
  const size = 400;
  const scale = pop * (1 - 0.3 * move);
  const cy = 540 - 205 * move;
  const glow = 30 + 20 * Math.sin(f / 6);
  if (f < BOOM) return null;
  return (
    <>
      <Rays f={f} cy={cy} scale={1 - 0.25 * move} />
      <div
        style={{
          position: 'absolute',
          left: 960 - size / 2,
          top: cy - size / 2,
          width: size,
          height: size,
          transform: `scale(${scale}) rotate(${(1 - pop) * -40}deg)`,
          borderRadius: '50%',
          background: '#fff',
          boxShadow: `0 0 ${glow}px ${GOLD_LIGHT}, 0 0 ${glow * 2.5}px rgba(212,160,23,0.6), 0 0 0 8px rgba(246,213,114,0.9)`,
        }}
      >
        <Img src={staticFile('logo.png')} style={{width: size, height: size}} />
      </div>
    </>
  );
};

const Wordmark: React.FC<{f: number; fps: number}> = ({f, fps}) => {
  const letters = 'MyAduan'.split('');
  const subIn = interpolate(f, [TEXT + 14, TEXT + 26], [0, 1], {...clamp, easing: Easing.out(Easing.cubic)});
  return (
    <div style={{position: 'absolute', top: 535, width: 1920, textAlign: 'center', fontFamily: FONT}}>
      <div
        style={{
          display: 'inline-block',
          position: 'relative',
          fontSize: 170,
          fontWeight: 800,
          letterSpacing: -4,
          lineHeight: 1,
        }}
      >
        {letters.map((ch, i) => {
          const s = spring({frame: f - TEXT - i * 2, fps, config: {damping: 12, stiffness: 180}});
          return (
            <span
              key={i}
              style={{
                display: 'inline-block',
                color: i < 2 ? '#ffffff' : GOLD_LIGHT,
                opacity: s,
                transform: `translateY(${(1 - s) * 80}px) scale(${0.6 + 0.4 * s})`,
                filter: `blur(${(1 - s) * 10}px)`,
                textShadow: '0 6px 30px rgba(0,0,0,0.45)',
              }}
            >
              {ch}
            </span>
          );
        })}
      </div>
      <div
        style={{
          marginTop: 22,
          fontSize: 40,
          fontWeight: 600,
          letterSpacing: 6,
          color: CREAM,
          textTransform: 'uppercase',
          opacity: subIn,
          transform: `translateY(${(1 - subIn) * 30}px)`,
        }}
      >
        Sistem Aduan Rakyat · Parlimen Gombak
      </div>
    </div>
  );
};

const Stamp: React.FC<{f: number; fps: number}> = ({f, fps}) => {
  if (f < STAMP) return null;
  const s = spring({frame: f - STAMP, fps, config: {damping: 10, stiffness: 200}});
  const scale = 2.6 - 1.6 * s;
  const shine = interpolate(f, [STAMP + 8, STAMP + 30], [-20, 120], clamp);
  return (
    <div style={{position: 'absolute', top: 840, width: 1920, textAlign: 'center', fontFamily: FONT}}>
      <div
        style={{
          display: 'inline-block',
          padding: '22px 64px',
          borderRadius: 999,
          background: `linear-gradient(105deg, ${GOLD} 0%, ${GOLD_LIGHT} ${shine - 8}%, #fffbe9 ${shine}%, ${GOLD_LIGHT} ${shine + 8}%, ${GOLD} 100%)`,
          color: '#5a0614',
          fontSize: 56,
          fontWeight: 900,
          letterSpacing: 8,
          transform: `scale(${scale})`,
          opacity: Math.min(1, s * 2),
          boxShadow: '0 12px 50px rgba(0,0,0,0.5), 0 0 60px rgba(246,213,114,0.55)',
        }}
      >
        DILANCARKAN SECARA RASMI
      </div>
    </div>
  );
};

const Confetti: React.FC<{f: number}> = ({f}) => {
  const t = f - STAMP;
  if (t < 0) return null;
  const colors = [GOLD_LIGHT, GOLD, '#ffffff', CRIMSON, '#e83a52'];
  return (
    <AbsoluteFill>
      {Array.from({length: 140}).map((_, i) => {
        const x0 = random(`kx${i}`) * 1920;
        const delay = random(`kd${i}`) * 20;
        const tt = t - delay;
        if (tt < 0) return null;
        const y = -40 + tt * (9 + random(`kv${i}`) * 8);
        const x = x0 + Math.sin(tt / 6 + i) * 40;
        const rot = tt * (6 + random(`kr${i}`) * 10);
        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: x,
              top: y,
              width: 12,
              height: 22,
              background: colors[i % colors.length],
              transform: `rotate(${rot}deg) rotateX(${rot * 2}deg)`,
              opacity: 0.95,
            }}
          />
        );
      })}
    </AbsoluteFill>
  );
};

const Url: React.FC<{f: number}> = ({f}) => {
  const o = interpolate(f, [STAMP + 22, STAMP + 34], [0, 1], clamp);
  return (
    <div
      style={{
        position: 'absolute',
        top: 990,
        width: 1920,
        textAlign: 'center',
        fontFamily: FONT,
        fontSize: 34,
        fontWeight: 700,
        letterSpacing: 4,
        color: CREAM,
        opacity: o,
      }}
    >
      myaduan.my
    </div>
  );
};

export const Launch: React.FC = () => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  // Camera shake on the two big hits.
  const shake = (at: number, amp: number) => {
    const t = f - at;
    if (t < 0 || t > 12) return [0, 0];
    const k = amp * (1 - t / 12);
    return [Math.sin(t * 9.1) * k, Math.cos(t * 7.3) * k];
  };
  const [ax, ay] = shake(BOOM, 18);
  const [bx, by] = shake(STAMP, 10);
  const fireColors = [GOLD_LIGHT, '#ffffff', '#ff5a6e', GOLD];
  return (
    <AbsoluteFill style={{overflow: 'hidden', backgroundColor: '#12030a'}}>
      <Audio src={staticFile('sfx.wav')} />
      <AbsoluteFill style={{transform: `translate(${ax + bx}px, ${ay + by}px)`}}>
        <Background f={f} />
        <Converge f={f} />
        <Core f={f} />
        <Shockwaves f={f} />
        <AbsoluteFill>
          <Burst f={f} at={BOOM} x={960} y={540} n={140} speed={70} seed="boom" colors={[GOLD_LIGHT, '#fff', GOLD]} life={45} />
          <Burst f={f} at={STAMP + 6} x={360} y={300} n={70} speed={38} seed="fw1" colors={fireColors} life={40} gravity={0.15} />
          <Burst f={f} at={STAMP + 19} x={1560} y={260} n={70} speed={38} seed="fw2" colors={fireColors} life={40} gravity={0.15} />
          <Burst f={f} at={STAMP + 33} x={500} y={720} n={60} speed={32} seed="fw3" colors={fireColors} life={33} gravity={0.15} />
          <Burst f={f} at={STAMP + 40} x={1440} y={700} n={60} speed={32} seed="fw4" colors={fireColors} life={26} gravity={0.15} />
        </AbsoluteFill>
        <Logo f={f} fps={fps} />
        <Wordmark f={f} fps={fps} />
        <Confetti f={f} />
        <Stamp f={f} fps={fps} />
        <Url f={f} />
      </AbsoluteFill>
      <Flash f={f} at={BOOM} />
      <Flash f={f} at={STAMP} peak={0.5} />
    </AbsoluteFill>
  );
};
