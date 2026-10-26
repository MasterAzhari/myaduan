import React from 'react';
import {
  AbsoluteFill,
  Easing,
  Freeze,
  Img,
  interpolate,
  spring,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';
import {Launch} from './Launch';
import scenes from './scenes.json';

export const CRIMSON = '#b3122b';
export const DEEP = '#8c0e21';
export const GOLD = '#d4a017';
export const INK = '#1d1d1f';
export const NAVY = '#13263f';
export const GREEN = '#137333';
const FONT = 'Inter, "Inter Display", system-ui, sans-serif';
const clamp = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;

type Scene = (typeof scenes)[number];

const useT = () => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  return {f, fps, s: f / fps};
};

// 0..1 spring that starts at `at` seconds.
const useIn = (at: number, damping = 14) => {
  const {f, fps} = useT();
  return spring({frame: f - at * fps, fps, config: {damping, stiffness: 120}});
};

const fade = (s: number, a: number, b: number) =>
  interpolate(s, [a, b], [0, 1], {...clamp, easing: Easing.out(Easing.cubic)});

// ---------- shared building blocks ----------

const Bg: React.FC<{dark?: boolean}> = ({dark}) =>
  dark ? (
    <AbsoluteFill style={{background: 'radial-gradient(circle at 50% 40%, #5a0a16 0%, #2a0510 55%, #12030a 100%)'}} />
  ) : (
    <AbsoluteFill style={{background: 'linear-gradient(135deg, #fffaf2 0%, #fdf1f2 60%, #fbe9ec 100%)'}}>
      <div style={{position: 'absolute', right: -220, top: -260, width: 760, height: 760, borderRadius: '50%', background: 'rgba(212,160,23,0.10)'}} />
      <div style={{position: 'absolute', left: -180, bottom: -300, width: 640, height: 640, borderRadius: '50%', background: 'rgba(179,18,43,0.06)'}} />
    </AbsoluteFill>
  );

const Brand: React.FC<{dark?: boolean}> = ({dark}) => (
  <div style={{position: 'absolute', left: 72, top: 52, display: 'flex', alignItems: 'center', gap: 16, fontFamily: FONT}}>
    <Img src={staticFile('logo.png')} style={{width: 64, height: 64, borderRadius: '50%', background: '#fff'}} />
    <div>
      <div style={{fontSize: 30, fontWeight: 800, color: dark ? '#fff' : DEEP, lineHeight: 1}}>MyAduan</div>
      <div style={{fontSize: 18, fontWeight: 500, color: dark ? '#f3dfe2' : '#5f6368', marginTop: 4}}>Parlimen Gombak</div>
    </div>
  </div>
);

const StepTag: React.FC<{text: string; dark?: boolean}> = ({text, dark}) => {
  const p = useIn(0.2);
  return (
    <div
      style={{
        position: 'absolute', right: 72, top: 64, fontFamily: FONT, fontSize: 22, fontWeight: 700, letterSpacing: 3,
        textTransform: 'uppercase', color: dark ? '#f6d572' : CRIMSON, padding: '10px 22px', borderRadius: 999,
        border: `2px solid ${dark ? '#f6d572' : CRIMSON}`, opacity: p, transform: `translateY(${(1 - p) * -20}px)`,
      }}
    >
      {text}
    </div>
  );
};

const Caption: React.FC<{scene: Scene}> = ({scene}) => {
  const {s} = useT();
  const half = scene.dur / 2;
  const text = s < half ? scene.captions[0] : scene.captions[1];
  if (!text) return null;
  return (
    <div style={{position: 'absolute', bottom: 40, width: '100%', display: 'flex', justifyContent: 'center', fontFamily: FONT}}>
      <div style={{background: 'rgba(20,6,10,0.78)', color: '#fff', fontSize: 34, fontWeight: 600, padding: '14px 32px', borderRadius: 14, maxWidth: 1500, textAlign: 'center'}}>
        {text}
      </div>
    </div>
  );
};

const Heading: React.FC<{kicker?: string; title: string; sub?: string; x?: number; y?: number; w?: number; at?: number; dark?: boolean; size?: number}> = ({
  kicker, title, sub, x = 120, y = 250, w = 760, at = 0.1, dark, size = 76,
}) => {
  const p = useIn(at);
  const q = useIn(at + 0.35);
  return (
    <div style={{position: 'absolute', left: x, top: y, width: w, fontFamily: FONT, textAlign: x === 0 ? 'center' : 'left'}}>
      {kicker && (
        <div style={{fontSize: 24, fontWeight: 800, letterSpacing: 4, color: dark ? '#f6d572' : CRIMSON, textTransform: 'uppercase', opacity: p, marginBottom: 18}}>{kicker}</div>
      )}
      <div style={{fontSize: size, fontWeight: 800, lineHeight: 1.06, letterSpacing: -1.5, color: dark ? '#fff' : INK, opacity: p, transform: `translateY(${(1 - p) * 40}px)`}}>{title}</div>
      {sub && (
        <div style={{fontSize: 32, lineHeight: 1.4, color: dark ? '#f3dfe2' : '#4a4a4f', marginTop: 24, opacity: q, transform: `translateY(${(1 - q) * 20}px)`}}>{sub}</div>
      )}
    </div>
  );
};

const Chip: React.FC<{text: string; at: number; icon?: string; color?: string; big?: boolean}> = ({text, at, icon = '✓', color = GREEN, big}) => {
  const p = useIn(at, 11);
  return (
    <div
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 14, background: '#fff', borderRadius: 999, padding: big ? '16px 30px 16px 16px' : '12px 26px 12px 12px',
        boxShadow: '0 10px 30px rgba(140,14,33,0.12)', fontFamily: FONT, fontSize: big ? 32 : 28, fontWeight: 700, color: INK,
        opacity: p, transform: `scale(${0.6 + 0.4 * p})`, transformOrigin: 'left center', marginBottom: 18,
      }}
    >
      <span style={{width: big ? 50 : 44, height: big ? 50 : 44, borderRadius: '50%', background: color, color: '#fff', display: 'grid', placeItems: 'center', fontSize: big ? 26 : 22}}>{icon}</span>
      {text}
    </div>
  );
};

// Phone mockup whose screen scrolls through a tall screenshot.
const Phone: React.FC<{src: string; x: number; y: number; w?: number; at?: number; scroll?: [number, number, number, number]; imgRatio: number; children?: React.ReactNode; from?: 'right' | 'bottom'}> = ({
  src, x, y, w = 400, at = 0, scroll, imgRatio, children, from = 'bottom',
}) => {
  const {s} = useT();
  const p = useIn(at, 16);
  const sw = w - 28;
  const sh = sw * 2.08;
  const ih = sw * imgRatio;
  const k = scroll ? interpolate(s, [scroll[0], scroll[1]], [scroll[2], scroll[3]], {...clamp, easing: Easing.inOut(Easing.cubic)}) : 0;
  const ty = -Math.max(0, ih - sh) * k;
  const off = from === 'right' ? `translateX(${(1 - p) * 500}px)` : `translateY(${(1 - p) * 600}px)`;
  return (
    <div style={{position: 'absolute', left: x, top: y, width: w, height: sh + 28, borderRadius: 54, background: '#111', padding: 14, boxShadow: '0 40px 80px rgba(40,5,12,0.35)', transform: off, opacity: Math.min(1, p * 2)}}>
      <div style={{position: 'relative', width: sw, height: sh, borderRadius: 42, overflow: 'hidden', background: '#fff'}}>
        <Img src={staticFile(src)} style={{width: sw, transform: `translateY(${ty}px)`}} />
        <div style={{position: 'absolute', top: 10, left: sw / 2 - 60, width: 120, height: 30, borderRadius: 20, background: '#111'}} />
        {children}
      </div>
    </div>
  );
};

const Laptop: React.FC<{src: string; x: number; y: number; w?: number; at?: number; imgRatio: number; scroll?: [number, number, number, number]; children?: React.ReactNode}> = ({
  src, x, y, w = 1080, at = 0, imgRatio, scroll, children,
}) => {
  const {s} = useT();
  const p = useIn(at, 18);
  const sw = w - 40;
  const sh = sw * 0.625;
  const ih = sw * imgRatio;
  const k = scroll ? interpolate(s, [scroll[0], scroll[1]], [scroll[2], scroll[3]], {...clamp, easing: Easing.inOut(Easing.cubic)}) : 0;
  return (
    <div style={{position: 'absolute', left: x, top: y, width: w, opacity: Math.min(1, p * 2), transform: `translateY(${(1 - p) * 120}px) scale(${0.92 + 0.08 * p})`}}>
      <div style={{background: '#1b1b1d', borderRadius: '26px 26px 0 0', padding: 20, boxShadow: '0 40px 80px rgba(40,5,12,0.28)'}}>
        <div style={{position: 'relative', width: sw, height: sh, overflow: 'hidden', borderRadius: 6, background: '#fff'}}>
          <Img src={staticFile(src)} style={{width: sw, transform: `translateY(${-Math.max(0, ih - sh) * k}px)`}} />
          {children}
        </div>
      </div>
      <div style={{height: 26, background: 'linear-gradient(#d7d7da,#a9a9ad)', borderRadius: '0 0 30px 30px', margin: '0 -50px'}} />
    </div>
  );
};

const Browser: React.FC<{src: string; x: number; y: number; w: number; at?: number; imgRatio: number; crop?: number; children?: React.ReactNode}> = ({src, x, y, w, at = 0, imgRatio, crop, children}) => {
  const p = useIn(at, 16);
  const h = crop ?? w * imgRatio;
  return (
    <div style={{position: 'absolute', left: x, top: y, width: w, borderRadius: 18, overflow: 'hidden', background: '#fff', boxShadow: '0 40px 80px rgba(40,5,12,0.28)', opacity: Math.min(1, p * 2), transform: `translateY(${(1 - p) * 120}px)`}}>
      <div style={{height: 46, background: '#f1f1f3', display: 'flex', alignItems: 'center', gap: 10, padding: '0 18px'}}>
        {['#ff5f57', '#febc2e', '#28c840'].map((c) => <span key={c} style={{width: 14, height: 14, borderRadius: '50%', background: c}} />)}
        <div style={{marginLeft: 18, flex: 1, height: 28, borderRadius: 8, background: '#fff', fontFamily: FONT, fontSize: 17, color: '#5f6368', display: 'flex', alignItems: 'center', paddingLeft: 14}}>🔒 myaduan.my</div>
      </div>
      <div style={{position: 'relative', width: w, height: h, overflow: 'hidden'}}>
        <Img src={staticFile(src)} style={{width: w}} />
        {children}
      </div>
    </div>
  );
};

// Pulsing highlight box over part of a screenshot.
const Highlight: React.FC<{x: number; y: number; w: number; h: number; at: number; color?: string}> = ({x, y, w, h, at, color = GOLD}) => {
  const {s} = useT();
  const p = fade(s, at, at + 0.4);
  const pulse = 1 + 0.03 * Math.sin((s - at) * 6);
  return <div style={{position: 'absolute', left: x, top: y, width: w, height: h, borderRadius: 14, border: `5px solid ${color}`, boxShadow: `0 0 0 9999px rgba(0,0,0,${0.35 * p}), 0 0 30px ${color}`, opacity: p, transform: `scale(${pulse})`}} />;
};

const Tap: React.FC<{x: number; y: number; at: number}> = ({x, y, at}) => {
  const {s} = useT();
  const t = s - at;
  if (t < -0.6) return null;
  const finger = interpolate(t, [-0.6, 0], [120, 0], clamp);
  const ring = interpolate(t, [0, 0.6], [0, 1], clamp);
  return (
    <>
      {t > 0 && <div style={{position: 'absolute', left: x - 30 - ring * 50, top: y - 30 - ring * 50, width: 60 + ring * 100, height: 60 + ring * 100, borderRadius: '50%', border: `5px solid ${GOLD}`, opacity: 1 - ring}} />}
      <div style={{position: 'absolute', left: x - 8, top: y - 6 + finger, fontSize: 70, opacity: interpolate(t, [-0.6, -0.3, 1.2, 1.6], [0, 1, 1, 0], clamp), transform: 'rotate(-15deg)'}}>👆</div>
    </>
  );
};

const Card: React.FC<{x: number; y: number; w: number; h?: number; at: number; children: React.ReactNode; style?: React.CSSProperties}> = ({x, y, w, h, at, children, style}) => {
  const p = useIn(at, 13);
  return (
    <div style={{position: 'absolute', left: x, top: y, width: w, height: h, background: '#fff', borderRadius: 26, boxShadow: '0 16px 44px rgba(140,14,33,0.12)', fontFamily: FONT, opacity: p, transform: `translateY(${(1 - p) * 50}px) scale(${0.9 + 0.1 * p})`, ...style}}>
      {children}
    </div>
  );
};

// ---------- scenes ----------

// Launch gimmick (6 s), then hold its last frame.
const S01: React.FC = () => {
  const {f} = useT();
  return f < 179 ? <Launch /> : <Freeze frame={179}><Launch /></Freeze>;
};

const S02: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 9, 10);
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Selamat datang ke" title="Parlimen Gombak" sub="Tiga kawasan DUN, satu saluran aduan." x={0} w={1920} y={300} size={110} />
        <div style={{position: 'absolute', top: 640, width: 1920, display: 'flex', justifyContent: 'center', gap: 36}}>
          {['Sungai Tua', 'Gombak Setia', 'Hulu Kelang'].map((n, i) => (
            <Chip key={n} text={`DUN ${n}`} at={1.5 + i * 0.6} icon="📍" color={CRIMSON} big />
          ))}
        </div>
      </div>
      {s > 9 && (
        <>
          <Heading kicker="Inisiatif" title="Suara rakyat didengar dan diambil tindakan" x={120} y={220} w={1680} at={9.3} size={64} />
          <Card x={180} y={420} w={1560} at={9.8} style={{overflow: 'hidden', padding: 0}}>
            <Img src={staticFile('shots/inisiatif.png')} style={{width: 1560, display: 'block'}} />
          </Card>
        </>
      )}
    </AbsoluteFill>
  );
};

const ICONS = [
  {e: '💬', t: 'WhatsApp', x: 300, y: 330},
  {e: '📞', t: 'Panggilan', x: 1350, y: 280},
  {e: '✉️', t: 'Surat', x: 520, y: 640},
  {e: '🏢', t: 'Kaunter', x: 1180, y: 620},
  {e: '📝', t: 'Kertas', x: 860, y: 420},
];

const S03: React.FC = () => {
  const {s} = useT();
  const gray = fade(s, 12, 13.5);
  const q = useIn(13.5, 10);
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <StepTag text="Sebelum ini" />
      <svg width={1920} height={1080} style={{position: 'absolute', opacity: (1 - gray) * fade(s, 3, 4)}}>
        {ICONS.flatMap((a, i) => ICONS.slice(i + 1).map((b, j) => (
          <line key={`${i}-${j}`} x1={a.x + 90} y1={a.y + 90} x2={b.x + 90} y2={b.y + 90} stroke={CRIMSON} strokeWidth={3} strokeDasharray="12 12" opacity={0.35} />
        )))}
      </svg>
      {ICONS.map((ic, i) => {
        const p = spring({frame: (s - 0.4 - i * 0.5) * 30, fps: 30, config: {damping: 9}});
        const bob = Math.sin(s * 1.6 + i * 1.3) * 18;
        return (
          <div key={ic.t} style={{position: 'absolute', left: ic.x, top: ic.y + bob, width: 180, textAlign: 'center', fontFamily: FONT, opacity: p * (1 - 0.65 * gray), filter: `grayscale(${gray})`, transform: `scale(${p}) rotate(${Math.sin(s + i) * 6}deg)`}}>
            <div style={{width: 180, height: 180, borderRadius: 40, background: '#fff', boxShadow: '0 16px 40px rgba(140,14,33,0.15)', display: 'grid', placeItems: 'center', fontSize: 96}}>{ic.e}</div>
            <div style={{marginTop: 14, fontSize: 30, fontWeight: 700, color: INK}}>{ic.t}</div>
          </div>
        );
      })}
      {s > 13 && (
        <div style={{position: 'absolute', left: 960 - 560, top: 330, width: 1120, opacity: q, transform: `scale(${0.5 + 0.5 * q})`}}>
          <div style={{background: CRIMSON, color: '#fff', borderRadius: 48, padding: '60px 70px', fontFamily: FONT, fontSize: 84, fontWeight: 800, textAlign: 'center', lineHeight: 1.1, boxShadow: '0 30px 80px rgba(140,14,33,0.4)'}}>
            “Aduan saya sampai ke mana?”
          </div>
          <div style={{marginLeft: 200, width: 0, height: 0, borderLeft: '50px solid transparent', borderRight: '50px solid transparent', borderTop: `60px solid ${CRIMSON}`}} />
        </div>
      )}
    </AbsoluteFill>
  );
};

const S04: React.FC = () => (
  <AbsoluteFill>
    <Bg />
    <Brand />
    <StepTag text="Memperkenalkan" />
    <Laptop src="shots/home-desktop.png" imgRatio={860 / 1366} x={110} y={210} w={1080} at={0.6} />
    <Phone src="shots/home-phone.png" imgRatio={1688 / 780} x={1300} y={150} w={380} at={8} from="right" scroll={[10, 16, 0, 0.4]} />
    <div style={{position: 'absolute', left: 140, top: 900, display: 'flex', gap: 28}}>
      <Chip text="Percuma" at={12} />
      <Chip text="Tak perlu daftar akaun" at={12.7} />
      <Chip text="Siap dalam 3 minit" at={13.4} />
    </div>
  </AbsoluteFill>
);

const S05: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 12, 12.6);
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <StepTag text="Langkah 1" />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Buat aduan" title="Layari myaduan.my" sub="Tekan Buat Aduan Sekarang. Borang membimbing anda satu langkah pada satu masa." y={300} w={780} />
        <Phone src="shots/home-phone.png" imgRatio={1688 / 780} x={1120} y={120} w={420} at={0.5} scroll={[1.5, 3.5, 0, 0]}>
          <Tap x={196} y={578} at={5.5} />
        </Phone>
      </div>
      {s > 12 && (
        <>
          <Heading kicker="Keselamatan dahulu" title="Adakah ini kecemasan?" sub="Jika ya, butang Hubungi 999 terus dipaparkan." y={300} w={620} at={12.3} size={64} />
          <Browser src="shots/form-kecemasan.png" imgRatio={1060 / 1120} x={800} y={160} w={1000} at={12.5} crop={800}>
            <Highlight x={420} y={540} w={540} h={230} at={15} color={CRIMSON} />
          </Browser>
        </>
      )}
    </AbsoluteFill>
  );
};

const S06: React.FC = () => {
  const {s} = useT();
  const lock = fade(s, 17, 17.6);
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <StepTag text="Langkah 2" />
      <Heading kicker="Ceritakan masalah" title={lock < 0.5 ? 'Apa, di mana, dan gambar' : 'Privasi anda terjaga'} y={250} w={820} />
      <div style={{position: 'absolute', left: 120, top: 480, display: 'flex', flexDirection: 'column', opacity: 1 - lock}}>
        <Chip text="Butiran masalah" at={2} icon="📝" color={CRIMSON} big />
        <Chip text="Lokasi + GPS" at={3.5} icon="📍" color={CRIMSON} big />
        <Chip text="Hingga 3 gambar" at={5} icon="📷" color={CRIMSON} big />
      </div>
      {s > 17 && (
        <div style={{position: 'absolute', left: 120, top: 480, display: 'flex', flexDirection: 'column'}}>
          <Chip text="Nama, IC dan telefon disulitkan" at={17.6} icon="🔒" color={NAVY} big />
          <Chip text="Lokasi tersembunyi dalam gambar dibuang" at={19} icon="🛡️" color={NAVY} big />
          <Chip text="Gambar disimpan secara peribadi" at={20.4} icon="🗂️" color={NAVY} big />
        </div>
      )}
      <Phone src="shots/form-gambar-phone.png" imgRatio={1310 / 390} x={1180} y={110} w={420} at={0.3} scroll={[2, 30, 0, 1]} />
    </AbsoluteFill>
  );
};

const S07: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 10, 10.6);
  const zoom = interpolate(s, [13, 15], [1, 1.35], {...clamp, easing: Easing.inOut(Easing.cubic)});
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <StepTag text="Langkah 3" />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Semak & hantar" title="Semuanya sudah betul?" sub="Tersilap? Tekan Ubah pada bahagian itu." y={300} w={760} />
        <Phone src="shots/form-semakan-phone.png" imgRatio={1590 / 390} x={1180} y={110} w={420} at={0.3} scroll={[1, 9, 0, 0.55]} />
      </div>
      {s > 10 && (
        <div style={{position: 'absolute', inset: 0, transform: `scale(${zoom})`, transformOrigin: '1465px 767px'}}>
          <Heading kicker="Berjaya" title="Anda terima nombor rujukan" sub="Simpan nombor ini untuk menjejak aduan." y={300} w={560} at={10.3} size={64} />
          <Browser src="shots/form-berjaya.png" imgRatio={855 / 1120} x={720} y={170} w={1080} at={10.4} crop={760}>
            <Highlight x={450} y={506} w={590} h={90} at={12.5} />
          </Browser>
        </div>
      )}
    </AbsoluteFill>
  );
};

const STATUSES = ['Diterima', 'Dalam Semakan', 'Dalam Tindakan', 'Menunggu Pengesahan', 'Ditutup'];

const S08: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 11, 11.6);
  const ref = 'GOM-20261026-A0D0C';
  const typed = ref.slice(0, Math.floor(interpolate(s, [3, 7], [0, ref.length], clamp)));
  const prog = interpolate(s, [13.5, 21], [0, 4], clamp);
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <StepTag text="Jejak status" />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Semak Status" title="Bila-bila masa, di mana-mana" sub="Masukkan nombor rujukan atau No. kad pengenalan." y={300} w={760} />
        <Phone src="shots/semak-phone.png" imgRatio={1080 / 780} x={1180} y={110} w={420} at={0.3}>
          <div style={{position: 'absolute', left: 46, top: 310, width: 296, height: 44, background: '#fff', fontFamily: 'monospace', fontSize: 18, display: 'flex', alignItems: 'center', paddingLeft: 8, color: INK}}>
            {typed}
            <span style={{opacity: Math.floor(s * 2) % 2, marginLeft: 1}}>|</span>
          </div>
          <Tap x={196} y={400} at={8.5} />
        </Phone>
      </div>
      {s > 11 && (
        <>
          <Heading kicker="Perjalanan aduan anda" title="Tahu di mana aduan anda berada" x={0} w={1920} y={230} at={11.3} size={68} />
          <div style={{position: 'absolute', left: 200, top: 560, width: 1520, height: 12, borderRadius: 6, background: '#ead6da'}}>
            <div style={{width: `${(prog / 4) * 100}%`, height: 12, borderRadius: 6, background: `linear-gradient(90deg, ${CRIMSON}, ${GOLD})`}} />
          </div>
          {STATUSES.map((st, i) => {
            const on = prog >= i - 0.02;
            const p = spring({frame: (s - 12 - i * 0.15) * 30, fps: 30, config: {damping: 12}});
            return (
              <div key={st} style={{position: 'absolute', left: 200 + i * 380 - 110, top: 516, width: 220, textAlign: 'center', fontFamily: FONT, opacity: p}}>
                <div style={{margin: '0 auto', width: 100, height: 100, borderRadius: '50%', background: on ? (i === 4 ? GREEN : CRIMSON) : '#fff', border: `6px solid ${on ? '#fff' : '#ead6da'}`, boxShadow: on ? '0 10px 30px rgba(140,14,33,0.3)' : 'none', color: on ? '#fff' : '#b9a3a8', display: 'grid', placeItems: 'center', fontSize: 40, fontWeight: 800}}>
                  {on ? '✓' : i + 1}
                </div>
                <div style={{marginTop: 20, fontSize: 28, fontWeight: 700, color: on ? INK : '#9b8c90', lineHeight: 1.2}}>{st}</div>
              </div>
            );
          })}
        </>
      )}
    </AbsoluteFill>
  );
};

const S09: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 13, 13.6);
  return (
    <AbsoluteFill>
      <Bg dark />
      <Brand dark />
      <StepTag text="Di belakang tabir" dark />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Portal Admin" title="Semua aduan di satu tempat" x={0} w={1920} y={150} dark size={64} />
        <Laptop src="shots/admin-senarai-desktop.png" imgRatio={910 / 1392} x={410} y={290} w={1100} at={0.6}>
          <Highlight x={14} y={52} w={1032} h={84} at={4} />
        </Laptop>
      </div>
      {s > 13 && (
        <>
          <Heading kicker="Kelaskan & tugaskan" title="Pegawai yang betul untuk setiap aduan" y={300} w={640} at={13.3} dark size={62} />
          <Browser src="shots/admin-ditugaskan.png" imgRatio={1215 / 1366} x={820} y={170} w={1000} at={13.5} crop={780}>
            <Highlight x={56} y={528} w={596} h={124} at={16} />
          </Browser>
        </>
      )}
    </AbsoluteFill>
  );
};

const FLOW = [
  {e: '📥', t: 'Kes'},
  {e: '👷', t: 'Pegawai'},
  {e: '📧', t: 'Agensi'},
  {e: '↩️', t: 'Balasan agensi'},
  {e: '🗂️', t: 'Sejarah kes'},
];

const S10: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 13, 13.6);
  const notif = spring({frame: (s - 3) * 30, fps: 30, config: {damping: 13}});
  return (
    <AbsoluteFill>
      <Bg dark />
      <Brand dark />
      <StepTag text="Tindakan" dark />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Pegawai di lapangan" title="Notifikasi terus ke telefon" sub="Rekod tindakan atau panjangkan kepada agensi, dari mana-mana." y={300} w={760} dark />
        <Phone src="shots/admin-kes-phone.png" imgRatio={1700 / 800} x={1180} y={110} w={420} at={0.3}>
          <div style={{position: 'absolute', left: 12, top: 50, width: 368, transform: `translateY(${(1 - notif) * -160}px)`, background: 'rgba(250,250,252,0.97)', borderRadius: 22, padding: '14px 16px', boxShadow: '0 12px 30px rgba(0,0,0,0.3)', fontFamily: FONT, display: 'flex', gap: 12}}>
            <Img src={staticFile('logo.png')} style={{width: 44, height: 44, borderRadius: 10}} />
            <div>
              <div style={{fontSize: 15, fontWeight: 800, color: INK}}>MyAduan · Tugasan baharu</div>
              <div style={{fontSize: 14, color: '#4a4a4f', marginTop: 2}}>Lampu jalan rosak di depan SK Sungai Tua</div>
            </div>
          </div>
        </Phone>
      </div>
      {s > 13 && (
        <>
          <Heading kicker="Agensi berkaitan" title="Dipanjangkan tanpa dedah maklumat pengadu" x={0} w={1920} y={220} at={13.3} dark size={60} />
          {FLOW.map((n, i) => {
            const p = spring({frame: (s - 14 - i * 0.9) * 30, fps: 30, config: {damping: 12}});
            const x = 150 + i * 340;
            return (
              <React.Fragment key={n.t}>
                {i > 0 && (
                  <div style={{position: 'absolute', left: x - 120, top: 590, width: 100 * fade(s, 13.7 + i * 0.9, 14.1 + i * 0.9), height: 8, borderRadius: 4, background: '#f6d572'}} />
                )}
                <div style={{position: 'absolute', left: x, top: 470, width: 220, textAlign: 'center', fontFamily: FONT, opacity: p, transform: `scale(${0.5 + 0.5 * p})`}}>
                  <div style={{width: 220, height: 220, borderRadius: 40, background: '#fff', display: 'grid', placeItems: 'center', fontSize: 100, boxShadow: '0 20px 50px rgba(0,0,0,0.35)'}}>{n.e}</div>
                  <div style={{marginTop: 20, fontSize: 32, fontWeight: 700, color: '#fff'}}>{n.t}</div>
                </div>
              </React.Fragment>
            );
          })}
        </>
      )}
    </AbsoluteFill>
  );
};

const S11: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 10, 10.6);
  const stamp = spring({frame: (s - 12.5) * 30, fps: 30, config: {damping: 10, stiffness: 200}});
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <StepTag text="Selesai" />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Penutupan kes" title="Pegawai mohon tutup, Super Admin sahkan" y={300} w={800} size={68} />
        <Phone src="shots/admin-mohon-tutup.png" imgRatio={1700 / 780} x={1180} y={110} w={420} at={0.3} scroll={[2, 8, 0, 0.3]} />
      </div>
      {s > 10 && (
        <>
          <Heading kicker="Rakyat dimaklumkan" title="Status bertukar kepada Ditutup" x={0} w={1920} y={200} at={10.3} size={68} />
          <Card x={560} y={420} w={800} h={420} at={10.6} style={{padding: 50}}>
            <div style={{fontSize: 26, color: '#5f6368', fontWeight: 600}}>Nombor rujukan</div>
            <div style={{fontSize: 44, fontFamily: 'monospace', fontWeight: 700, color: INK, marginTop: 6}}>GOM-20261026-A0D0C</div>
            <div style={{fontSize: 26, color: '#5f6368', fontWeight: 600, marginTop: 34}}>Status</div>
            <div style={{display: 'inline-block', marginTop: 10, padding: '10px 26px', borderRadius: 999, background: '#e6f4ea', color: GREEN, fontSize: 34, fontWeight: 800}}>DITUTUP · Selesai</div>
          </Card>
          <div style={{position: 'absolute', left: 1150, top: 380, opacity: stamp, transform: `scale(${2.5 - 1.5 * stamp}) rotate(-14deg)`, border: `10px solid ${GREEN}`, color: GREEN, borderRadius: 26, padding: '14px 34px', fontFamily: FONT, fontSize: 70, fontWeight: 900, letterSpacing: 6, background: 'rgba(255,255,255,0.85)'}}>
            ✓ DITUTUP
          </div>
        </>
      )}
    </AbsoluteFill>
  );
};

const BENEFITS = [
  {e: '⏱️', t: 'Jimat masa', d: 'Tak perlu beratur atau tunggu panggilan berjawab.'},
  {e: '📄', t: 'Rekod rasmi', d: 'Setiap aduan ada nombor rujukan sendiri.'},
  {e: '🎯', t: 'Sampai ke orang yang betul', d: 'Ditugaskan terus kepada pegawai bertanggungjawab.'},
  {e: '🔎', t: 'Dijejak hingga selesai', d: 'Semak status bila-bila masa, dari telefon.'},
];

const GROUPS = [
  ['🛣️', 'Infrastruktur & Kemudahan'], ['💡', 'Utiliti & Perkhidmatan'], ['🌳', 'Alam Sekitar & Haiwan'], ['🏠', 'Perumahan & Tanah'],
  ['🚨', 'Keselamatan & Kecemasan'], ['🚌', 'Pengangkutan'], ['🤝', 'Kebajikan & Kesihatan'], ['🎓', 'Pendidikan & Pekerjaan'],
  ['🏪', 'Perniagaan & Bantuan'], ['🕌', 'Agama & Komuniti'], ['🏛️', 'Kerajaan & Dokumentasi'], ['➕', 'Lain-lain'],
];

const S12: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 17, 17.6);
  return (
    <AbsoluteFill>
      <Bg />
      <Brand />
      <StepTag text="Untuk masyarakat" />
      <div style={{opacity: 1 - swap}}>
        <Heading kicker="Apa maknanya untuk anda?" title="Aduan lebih mudah, lebih pantas, lebih telus" x={0} w={1920} y={170} size={64} />
        {BENEFITS.map((b, i) => (
          <Card key={b.t} x={130 + i * 420} y={400} w={380} h={440} at={2 + i * 1.6} style={{padding: 36, boxSizing: 'border-box'}}>
            <div style={{width: 110, height: 110, borderRadius: 30, background: '#fdf1f2', display: 'grid', placeItems: 'center', fontSize: 64}}>{b.e}</div>
            <div style={{fontSize: 36, fontWeight: 800, color: INK, marginTop: 30, lineHeight: 1.15}}>{b.t}</div>
            <div style={{fontSize: 26, color: '#4a4a4f', marginTop: 16, lineHeight: 1.4}}>{b.d}</div>
          </Card>
        ))}
      </div>
      {s > 17 && (
        <>
          <Heading kicker="Lebih 50 jenis aduan" title="Dari jalan berlubang hingga urusan kebajikan" x={0} w={1920} y={170} at={17.3} size={60} />
          {GROUPS.map(([e, t], i) => {
            const on = fade(s, 18.5 + i * 0.45, 18.9 + i * 0.45);
            return (
              <Card key={t} x={150 + (i % 4) * 415} y={370 + Math.floor(i / 4) * 175} w={385} h={150} at={18 + i * 0.12} style={{display: 'flex', alignItems: 'center', gap: 20, padding: '0 26px', boxSizing: 'border-box', border: `4px solid rgba(179,18,43,${on})`}}>
                <div style={{fontSize: 56}}>{e}</div>
                <div style={{fontSize: 27, fontWeight: 700, color: INK, lineHeight: 1.2}}>{t}</div>
              </Card>
            );
          })}
        </>
      )}
    </AbsoluteFill>
  );
};

const S13: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 9.5, 10.1);
  const sh = useIn(0.3, 10);
  return (
    <AbsoluteFill>
      <Bg dark />
      <Brand dark />
      <StepTag text="Keselamatan" dark />
      <div style={{opacity: 1 - swap}}>
        <div style={{position: 'absolute', left: 960 - 170, top: 210, width: 340, height: 400, fontSize: 300, textAlign: 'center', transform: `scale(${sh})`, filter: 'drop-shadow(0 0 40px rgba(246,213,114,0.6))'}}>🛡️</div>
        <div style={{position: 'absolute', top: 680, width: 1920, display: 'flex', justifyContent: 'center', gap: 30}}>
          <Chip text="Data peribadi disulitkan" at={2} icon="🔒" color={CRIMSON} big />
          <Chip text="Gambar disimpan peribadi" at={3.2} icon="🗂️" color={CRIMSON} big />
          <Chip text="Setiap akses direkodkan" at={4.4} icon="📋" color={CRIMSON} big />
        </div>
      </div>
      {s > 9.5 && (
        <>
          <Heading kicker="Telus & bertanggungjawab" title="Setiap tindakan tercatat" sub="Peranan jelas: Super Admin, Pegawai dan Pemerhati. Sejarah kes lengkap dari mula hingga akhir." y={300} w={760} at={9.8} dark />
          <Phone src="shots/admin-pengguna-phone.png" imgRatio={1700 / 800} x={1180} y={110} w={420} at={10} />
        </>
      )}
    </AbsoluteFill>
  );
};

const S14: React.FC = () => {
  const {s} = useT();
  const swap = fade(s, 15, 15.8);
  const logo = useIn(15.5, 9);
  const glow = 40 + 20 * Math.sin(s * 3);
  return (
    <AbsoluteFill>
      <Bg dark />
      {s < 16 && (
        <div style={{opacity: 1 - swap}}>
          <Brand dark />
          <Heading kicker="Jom guna MyAduan" title="Nampak masalah? Adukan." sub="Ambil gambar, isi borang, dapat nombor rujukan. Kongsikan dengan keluarga dan jiran." y={260} w={860} dark size={84} />
          <Card x={1150} y={190} w={600} h={680} at={1} style={{padding: 50, boxSizing: 'border-box', textAlign: 'center'}}>
            <Img src={staticFile('qr.png')} style={{width: 500, height: 500}} />
            <div style={{fontSize: 52, fontWeight: 800, color: DEEP, marginTop: 20}}>myaduan.my</div>
          </Card>
        </div>
      )}
      {s > 15 && (
        <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', fontFamily: FONT}}>
          <div style={{width: 380, height: 380, borderRadius: '50%', background: '#fff', transform: `scale(${logo})`, boxShadow: `0 0 ${glow}px #f6d572, 0 0 0 8px rgba(246,213,114,0.9)`}}>
            <Img src={staticFile('logo.png')} style={{width: 380, height: 380}} />
          </div>
          <div style={{marginTop: 50, fontSize: 92, fontWeight: 800, color: '#fff', opacity: fade(s, 16.3, 17), letterSpacing: -2}}>
            Suara anda. <span style={{color: '#f6d572'}}>Perhatian kami.</span>
          </div>
          <div style={{marginTop: 20, fontSize: 40, fontWeight: 600, color: '#fbf3dc', letterSpacing: 6, opacity: fade(s, 17.3, 18)}}>MYADUAN.MY · PARLIMEN GOMBAK</div>
        </AbsoluteFill>
      )}
    </AbsoluteFill>
  );
};

const MAP: Record<string, React.FC> = {S01, S02, S03, S04, S05, S06, S07, S08, S09, S10, S11, S12, S13, S14};

export const SceneComp: React.FC<{id: string}> = ({id}) => {
  const scene = scenes.find((x) => x.id === id)!;
  const C = MAP[id];
  return (
    <AbsoluteFill style={{backgroundColor: '#fff', overflow: 'hidden'}}>
      <C />
      {id !== 'S01' && <Caption scene={scene} />}
    </AbsoluteFill>
  );
};
