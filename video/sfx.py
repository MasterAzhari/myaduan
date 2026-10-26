"""Synthesise the 6-second launch soundtrack (stereo 48 kHz WAV)."""
import sys, wave
import numpy as np

SR = 48000
DUR = 6.0
N = int(SR * DUR)
rng = np.random.default_rng(7)
L = np.zeros(N)
R = np.zeros(N)


def t_(d):
    return np.arange(int(SR * d)) / SR


def add(sig, at, gain=1.0, pan=0.0):
    i = int(at * SR)
    sig = sig[: max(0, N - i)]
    l = np.cos((pan + 1) * np.pi / 4) * np.sqrt(2)
    r = np.sin((pan + 1) * np.pi / 4) * np.sqrt(2)
    L[i : i + len(sig)] += sig * gain * l
    R[i : i + len(sig)] += sig * gain * r


def onepole_lp(x, fc):
    """Low-pass with per-sample cutoff (fc may be array)."""
    fc = np.broadcast_to(fc, x.shape)
    a = np.exp(-2 * np.pi * fc / SR)
    y = np.zeros_like(x)
    acc = 0.0
    for i in range(len(x)):
        acc = (1 - a[i]) * x[i] + a[i] * acc
        y[i] = acc
    return y


def reverb(x, decay=1.6, mix=0.35):
    ir_t = t_(decay)
    ir = rng.standard_normal(len(ir_t)) * np.exp(-ir_t * 5.0 / decay)
    ir = onepole_lp(ir, 5000)
    ir /= np.sqrt(np.sum(ir**2))
    n = len(x) + len(ir) - 1
    nf = 1 << (n - 1).bit_length()
    wet = np.fft.irfft(np.fft.rfft(x, nf) * np.fft.rfft(ir, nf), nf)[:n]
    out = np.zeros(len(wet))
    out[: len(x)] += x * (1 - mix)
    return out + wet * mix


def env(t, a, d):
    return np.minimum(t / a, 1.0) * np.exp(-np.maximum(t - a, 0) / d)


# 1) Touch "tick" + digital blip at 0.0s
t = t_(0.25)
blip = np.sin(2 * np.pi * (1800 - 1200 * t / 0.25) * t) * env(t, 0.002, 0.04)
add(blip, 0.02, 0.5)

# 2) Riser 0.05 -> 1.0s : filtered noise sweep + rising detuned tone
d = 0.95
t = t_(d)
prog = t / d
noise = rng.standard_normal(len(t))
sweep = onepole_lp(noise, 300 + 9000 * prog**2) * (prog**2.2)
f = 110 * 2 ** (prog * 3)  # three octaves up
ph = 2 * np.pi * np.cumsum(f) / SR
tone = (np.sin(ph) + 0.5 * np.sin(ph * 1.01) + 0.3 * np.sin(2 * ph)) * prog**2.5
add(sweep, 0.05, 0.35, -0.2)
add(tone, 0.05, 0.18, 0.2)

# 3) BOOM at 1.0s: sub drop + punchy click + noise burst, with reverb
t = t_(2.6)
fsub = 38 + 90 * np.exp(-t * 18)
sub = np.sin(2 * np.pi * np.cumsum(fsub) / SR) * np.exp(-t * 1.6)
click = rng.standard_normal(len(t)) * np.exp(-t * 60)
burst = onepole_lp(rng.standard_normal(len(t)), 2500) * np.exp(-t * 6)
boom = 1.1 * sub + 0.35 * click + 0.6 * burst
boom = np.tanh(boom * 1.6)
add(reverb(boom, 2.2, 0.3), 1.0, 0.9)

# 4) Whooshes (rings at 1.05s, text at 2.25s, banner at 3.7s)
def whoosh(d, lo, hi):
    t = t_(d)
    p = t / d
    shape = np.sin(np.pi * p) ** 2
    fc = lo + (hi - lo) * np.sin(np.pi * p)
    return onepole_lp(rng.standard_normal(len(t)), fc) * shape

add(whoosh(0.7, 400, 4000), 1.05, 0.45, 0.5)
add(whoosh(0.55, 600, 6000), 2.2, 0.5, -0.5)
add(whoosh(0.45, 800, 7000), 3.55, 0.45, 0.3)

# 5) Shimmer / sparkle chimes 1.3s and 2.6s (gold glitter)
def chime(freq, d=1.4):
    t = t_(d)
    s = sum(np.sin(2 * np.pi * freq * k * t) / k**1.3 for k in (1, 2.76, 5.4))
    return s * env(t, 0.003, 0.35)

scale = [1318.5, 1568, 1760, 2093, 2349, 2637, 3136]
for i in range(14):
    add(chime(rng.choice(scale)), 1.25 + i * 0.045 + rng.uniform(0, 0.02), 0.07, rng.uniform(-0.8, 0.8))
for i, fq in enumerate([1568, 2093, 2637, 3136]):
    add(reverb(chime(fq), 1.2, 0.4), 2.55 + i * 0.07, 0.11, -0.4 + i * 0.27)

# 6) Big hit + triumphant brass chord at 3.8s ("DILANCARKAN")
t = t_(2.2)
hit = np.tanh(1.5 * (np.sin(2 * np.pi * np.cumsum(45 + 70 * np.exp(-t * 20)) / SR) * np.exp(-t * 2.5)
                     + 0.4 * rng.standard_normal(len(t)) * np.exp(-t * 40)))
add(reverb(hit, 2.0, 0.3), 3.8, 0.75)

def saw(freq, t):
    out = np.zeros_like(t)
    for k in range(1, 14):
        out += np.sin(2 * np.pi * freq * k * t) / k
    return out

t = t_(2.2)
chord = [130.81, 196.0, 261.63, 329.63, 392.0, 523.25]  # C major, wide voicing
br = sum(saw(f * (1 + 0.003 * (j % 2)), t) for j, f in enumerate(chord))
br = onepole_lp(br, 600 + 3500 * env(t, 0.08, 0.6)) * env(t, 0.03, 1.4)
add(reverb(br / 6, 2.0, 0.35), 3.8, 0.55)

# 7) Firework crackles 4.0 -> 5.6s
for i in range(22):
    at = rng.uniform(4.0, 5.6)
    t = t_(0.6)
    pop = onepole_lp(rng.standard_normal(len(t)), rng.uniform(2500, 7000)) * np.exp(-t * rng.uniform(25, 60))
    add(pop, at, rng.uniform(0.15, 0.35), rng.uniform(-1, 1))
for i in range(3):
    t = t_(1.0)
    thump = np.sin(2 * np.pi * np.cumsum(60 + 80 * np.exp(-t * 25)) / SR) * np.exp(-t * 6)
    add(reverb(thump, 1.2, 0.4), 4.05 + i * 0.45, 0.35, -0.5 + i * 0.5)

# Master: fade tail, soft-limit, normalise to -1 dBFS
fade = np.ones(N)
fs = int(5.3 * SR)
fade[fs:] = np.linspace(1, 0, N - fs) ** 1.5
L *= fade
R *= fade
m = max(np.abs(L).max(), np.abs(R).max())
L = np.tanh(L / m * 1.3) / np.tanh(1.3) * 0.89
R = np.tanh(R / m * 1.3) / np.tanh(1.3) * 0.89

out = np.stack([L, R], axis=1)
pcm = (out * 32767).astype("<i2")
with wave.open(sys.argv[1], "wb") as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes(pcm.tobytes())
print("ok", len(pcm) / SR, "s")
