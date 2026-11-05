#!/usr/bin/env python3
"""Render docs/images/*.svg from docs/data/results*.json.  Usage: python3 bench/charts.py"""
import json
import math
import os
from html import escape

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = json.load(open(os.path.join(ROOT, 'docs/data/results.json')))
NOJIT = json.load(open(os.path.join(ROOT, 'docs/data/results-nojit.json')))
OUT = os.path.join(ROOT, 'docs/images')
os.makedirs(OUT, exist_ok=True)

FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
STYLE = """
<style>
  svg { --surface:#fcfcfb; --ink:#0b0b0b; --ink2:#52514e; --muted:#8a8984; --grid:#e6e5e1;
        --up:#eb6834; --ours:#2a78d6; --neutral:#a9a8a2; }
  @media (prefers-color-scheme: dark) {
    svg { --surface:#1a1a19; --ink:#f0efec; --ink2:#c3c2b7; --muted:#8f8e86; --grid:#34332f;
          --up:#d95926; --ours:#3987e5; --neutral:#6b6a64; }
  }
  .bg { fill: var(--surface); }
  text { font-family: %s; fill: var(--ink2); font-size: 12px; }
  .title { fill: var(--ink); font-size: 16px; font-weight: 600; }
  .sub { fill: var(--ink2); font-size: 12px; }
  .val { fill: var(--ink); font-size: 12px; font-weight: 600; }
  .muted { fill: var(--muted); font-size: 11px; }
  .grid { stroke: var(--grid); stroke-width: 1; }
  .axis { stroke: var(--muted); stroke-width: 1; }
  .up { fill: var(--up); } .ours { fill: var(--ours); } .neutral { fill: var(--neutral); }
  .l-up { stroke: var(--up); } .l-ours { stroke: var(--ours); }
  .line { fill: none; stroke-width: 2; stroke-linejoin: round; }
  .dot { stroke: var(--surface); stroke-width: 2; }
</style>
""" % FONT


def svg(w, h, title, sub, body, desc):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}" '
            f'role="img" aria-labelledby="t d">{STYLE}'
            f'<title id="t">{escape(title)}</title><desc id="d">{escape(desc)}</desc>'
            f'<rect class="bg" width="{w}" height="{h}" rx="8"/>'
            f'<text class="title" x="24" y="34">{escape(title)}</text>'
            f'<text class="sub" x="24" y="54">{escape(sub)}</text>{body}</svg>\n')


def hbar(x, y, w, h, cls, tip, r=4):
    """Horizontal bar anchored left, rounded on the value end only."""
    w = max(w, 1.5)
    r = min(r, w, h / 2)
    d = (f'M{x},{y} H{x + w - r} Q{x + w},{y} {x + w},{y + r} V{y + h - r} '
         f'Q{x + w},{y + h} {x + w - r},{y + h} H{x} Z')
    return f'<path class="{cls}" d="{d}"><title>{escape(tip)}</title></path>'


def vbar(x, y0, w, h, cls, tip, r=4):
    """Vertical bar anchored at baseline y0, rounded on the top only."""
    h = max(h, 1.5)
    r = min(r, h, w / 2)
    y = y0 - h
    d = (f'M{x},{y0} V{y + r} Q{x},{y} {x + r},{y} H{x + w - r} Q{x + w},{y} {x + w},{y + r} '
         f'V{y0} Z')
    return f'<path class="{cls}" d="{d}"><title>{escape(tip)}</title></path>'


def legend(x, y, items):
    out, cx = '', x
    for cls, label in items:
        out += f'<rect class="{cls}" x="{cx}" y="{y - 9}" width="10" height="10" rx="2"/>'
        out += f'<text x="{cx + 16}" y="{y}">{escape(label)}</text>'
        cx += 26 + len(label) * 6.6
    return out


def fmt_ms(v):
    if v >= 1:
        return f'{v:.2f} ms'
    if v >= 0.01:
        return f'{v * 1000:.0f} µs'
    return f'{v * 1000:.1f} µs'


def nice_max(v):
    exp = 10 ** math.floor(math.log10(v))
    for m in (1, 2, 2.5, 5, 10):
        if m * exp >= v:
            return m * exp


# 1. Speedup per query shape -------------------------------------------------------------------
def speedup():
    rows = DATA['shapes']
    W, L, R, top, bh, gap = 760, 270, 70, 84, 22, 14
    pw = W - L - R
    xmax = nice_max(max(r['speedup'] for r in rows))
    H = top + len(rows) * (bh + gap) + 40
    body = ''
    for t in range(0, int(xmax) + 1, 5 if xmax > 10 else 1):
        x = L + pw * t / xmax
        body += f'<line class="grid" x1="{x}" x2="{x}" y1="{top - 8}" y2="{H - 36}"/>'
        body += f'<text class="muted" x="{x}" y="{H - 20}" text-anchor="middle">{t}×</text>'
    x1 = L + pw / xmax
    for i, r in enumerate(rows):
        y = top + i * (bh + gap)
        body += f'<text x="{L - 12}" y="{y + 15}" text-anchor="end">{escape(r["name"])}</text>'
        tip = (f'{r["name"]}: upstream {fmt_ms(r["upstream"]["median_ms"])}, '
               f'replacement {fmt_ms(r["replacement"]["median_ms"])}')
        body += hbar(L, y, pw * r['speedup'] / xmax, bh, 'ours', tip)
        body += (f'<text class="val" x="{L + pw * r["speedup"] / xmax + 8}" y="{y + 15}">'
                 f'{r["speedup"]:.1f}×</text>')
    body += f'<line class="axis" x1="{x1}" x2="{x1}" y1="{top - 8}" y2="{H - 36}" stroke-dasharray="3 3"/>'
    body += f'<text class="muted" x="{x1 + 4}" y="{top - 12}">1× = no change</text>'
    return svg(W, H, 'Speedup per query shape',
               'Upstream time ÷ replacement time, median of 15 rounds, PHP 8.3 + PCRE JIT', body,
               'Horizontal bar chart. ' + '; '.join(f'{r["name"]}: {r["speedup"]}x' for r in rows))


# 2/3. Line charts against SELECT width -------------------------------------------------------
def line_chart(key_up, key_ours, title, sub, ylabel, fmt, name, axis_fmt=None):
    axis_fmt = axis_fmt or fmt
    rows = DATA['scaling']
    W, H, L, R, T, B = 760, 380, 76, 130, 84, 52
    pw, ph = W - L - R, H - T - B
    xs = [r['columns'] for r in rows]
    ymax = nice_max(max(r[key_up] for r in rows))
    X = lambda c: L + pw * c / xs[-1]
    Y = lambda v: T + ph - ph * v / ymax
    body = ''
    for i in range(5):
        v = ymax * i / 4
        body += f'<line class="grid" x1="{L}" x2="{L + pw}" y1="{Y(v)}" y2="{Y(v)}"/>'
        body += f'<text class="muted" x="{L - 8}" y="{Y(v) + 4}" text-anchor="end">{axis_fmt(v)}</text>'
    for c in xs:
        if c >= 200 or c == 10:
            body += f'<text class="muted" x="{X(c)}" y="{T + ph + 18}" text-anchor="middle">{c}</text>'
    body += f'<line class="axis" x1="{L}" x2="{L + pw}" y1="{T + ph}" y2="{T + ph}"/>'
    body += f'<text class="muted" x="{L + pw / 2}" y="{H - 12}" text-anchor="middle">Columns in the SELECT list</text>'
    body += f'<text class="muted" x="{L}" y="{T - 12}" text-anchor="middle">{escape(ylabel)}</text>'
    for key, cls, label in ((key_up, 'up', 'Upstream'), (key_ours, 'ours', 'Replacement')):
        pts = ' '.join(f'{X(r["columns"])},{Y(r[key])}' for r in rows)
        body += f'<polyline class="line l-{cls}" points="{pts}"/>'
        for r in rows:
            body += (f'<circle class="dot {cls}" cx="{X(r["columns"])}" cy="{Y(r[key])}" r="4.5">'
                     f'<title>{label}, {r["columns"]} columns: {fmt(r[key])}</title></circle>')
        last = rows[-1]
        body += (f'<text class="val" x="{X(last["columns"]) + 10}" y="{Y(last[key]) + 4}">'
                 f'{label} {fmt(last[key])}</text>')
    body += legend(W - 250, 54, [('up', 'Upstream'), ('ours', 'Replacement')])
    desc = 'Line chart. ' + '; '.join(f'{r["columns"]} columns: upstream {fmt(r[key_up])}, replacement {fmt(r[key_ours])}' for r in rows)
    open(os.path.join(OUT, name), 'w').write(svg(W, H, title, sub, body, desc))


# 4. Ablation ---------------------------------------------------------------------------------
def ablation():
    rows = DATA['ablation']
    W, L, R, top, bh, gap = 760, 200, 170, 84, 24, 14
    pw = W - L - R
    xmax = nice_max(max(r['median_ms'] for r in rows))
    H = top + len(rows) * (bh + gap) + 40
    cls = {'Upstream': 'up', 'Both (this package)': 'ours'}
    body = ''
    for i in range(6):
        v = xmax * i / 5
        x = L + pw * v / xmax
        body += f'<line class="grid" x1="{x}" x2="{x}" y1="{top - 8}" y2="{H - 36}"/>'
        body += f'<text class="muted" x="{x}" y="{H - 20}" text-anchor="middle">{fmt_ms(v) if v else "0"}</text>'
    for i, r in enumerate(rows):
        y = top + i * (bh + gap)
        body += f'<text x="{L - 12}" y="{y + 16}" text-anchor="end">{escape(r["name"])}</text>'
        w = pw * r['median_ms'] / xmax
        body += hbar(L, y, w, bh, cls.get(r['name'], 'neutral'), f'{r["name"]}: {fmt_ms(r["median_ms"])}')
        body += (f'<text class="val" x="{L + w + 8}" y="{y + 16}">{fmt_ms(r["median_ms"])}'
                 f'<tspan class="muted" dx="6">{r["calls"]:,} calls</tspan></text>')
    return svg(W, H, 'Which of the two changes does the work?',
               '400-column SELECT, each change applied on its own, median per parse', body,
               'Horizontal bar chart. ' + '; '.join(f'{r["name"]}: {r["median_ms"]} ms, {r["calls"]} preg_match calls' for r in rows))


# 5. Cap sweep --------------------------------------------------------------------------------
def cap():
    rows = DATA['cap']
    nojit = {str(r['cap']): r for r in NOJIT['cap']}
    W, H, L, R, T, B = 760, 400, 76, 24, 84, 96
    pw, ph = W - L - R, H - T - B
    ymax = nice_max(max(r.get('wide_select_ms', 0) for r in rows))
    slot = pw / len(rows)
    bw = slot * 0.5
    Y = lambda v: T + ph - ph * v / ymax
    body = ''
    for i in range(6):
        v = ymax * i / 5
        body += f'<line class="grid" x1="{L}" x2="{L + pw}" y1="{Y(v)}" y2="{Y(v)}"/>'
        body += f'<text class="muted" x="{L - 8}" y="{Y(v) + 4}" text-anchor="end">{fmt_ms(v) if v else "0"}</text>'
    for i, r in enumerate(rows):
        cx = L + slot * i + slot / 2
        label = '∞' if r['cap'] == 'unbounded' else f'{{1,{r["cap"]}}}'
        body += f'<text x="{cx}" y="{T + ph + 20}" text-anchor="middle">{label}</text>'
        if 'error' in r:
            body += f'<text class="muted" x="{cx}" y="{T + ph - 30}" text-anchor="middle">pattern</text>'
            body += f'<text class="muted" x="{cx}" y="{T + ph - 16}" text-anchor="middle">too large</text>'
            continue
        cls = 'ours' if r['cap'] == 64 else 'neutral'
        tip = f'cap {r["cap"]}: {fmt_ms(r["wide_select_ms"])} per parse, {r["calls"]} preg_match calls'
        body += vbar(cx - bw / 2, T + ph, bw, ph * r['wide_select_ms'] / ymax, cls, tip)
        body += f'<text class="val" x="{cx}" y="{Y(r["wide_select_ms"]) - 8}" text-anchor="middle">{fmt_ms(r["wide_select_ms"])}</text>'
        nj = nojit[str(r['cap'])]
        if 'huge_insert_error' in nj:
            body += f'<text class="muted" x="{cx}" y="{T + ph + 38}" text-anchor="middle">fails on 3 MB</text>'
            body += f'<text class="muted" x="{cx}" y="{T + ph + 52}" text-anchor="middle">with JIT off</text>'
    body += f'<line class="axis" x1="{L}" x2="{L + pw}" y1="{T + ph}" y2="{T + ph}"/>'
    body += f'<text class="muted" x="{L + pw / 2}" y="{H - 12}" text-anchor="middle">Repetition bound on the “other” group (shipped: {{1,64}}+)</text>'
    return svg(W, H, 'Choosing the cap',
               '400-column SELECT, median per parse, PCRE JIT on', body,
               'Column chart. ' + '; '.join(f'cap {r["cap"]}: ' + (r.get('error') or f'{r["wide_select_ms"]} ms') for r in rows))


# 6. JIT on vs off ----------------------------------------------------------------------------
def jit():
    pick = lambda d: next(s for s in d['shapes'] if s['name'].startswith('Product detail'))
    groups = [('PCRE JIT on', pick(DATA)), ('PCRE JIT off', pick(NOJIT))]
    W, H, L, R, T, B = 760, 360, 76, 24, 84, 56
    pw, ph = W - L - R, H - T - B
    ymax = nice_max(max(g[1]['upstream']['median_ms'] for g in groups))
    Y = lambda v: T + ph - ph * v / ymax
    body = ''
    for i in range(5):
        v = ymax * i / 4
        body += f'<line class="grid" x1="{L}" x2="{L + pw}" y1="{Y(v)}" y2="{Y(v)}"/>'
        body += f'<text class="muted" x="{L - 8}" y="{Y(v) + 4}" text-anchor="end">{fmt_ms(v) if v else "0"}</text>'
    slot, bw = pw / len(groups), 70
    for i, (label, s) in enumerate(groups):
        cx = L + slot * i + slot / 2
        for j, (key, cls, name) in enumerate((('upstream', 'up', 'Upstream'), ('replacement', 'ours', 'Replacement'))):
            v = s[key]['median_ms']
            x = cx - bw - 1 + j * (bw + 2)
            body += vbar(x, T + ph, bw, ph * v / ymax, cls, f'{label}, {name}: {fmt_ms(v)}')
            body += f'<text class="val" x="{x + bw / 2}" y="{Y(v) - 8}" text-anchor="middle">{fmt_ms(v)}</text>'
        body += f'<text x="{cx}" y="{T + ph + 20}" text-anchor="middle">{label} ({s["speedup"]:.1f}× faster)</text>'
    body += f'<line class="axis" x1="{L}" x2="{L + pw}" y1="{T + ph}" y2="{T + ph}"/>'
    body += legend(W - 250, 54, [('up', 'Upstream'), ('ours', 'Replacement')])
    return svg(W, H, 'With and without the PCRE JIT', '400-column SELECT, median per parse', body,
               'Grouped column chart. ' + '; '.join(f'{l}: upstream {s["upstream"]["median_ms"]} ms, replacement {s["replacement"]["median_ms"]} ms' for l, s in groups))


open(os.path.join(OUT, 'speedup.svg'), 'w').write(speedup())
line_chart('upstream_ms', 'replacement_ms', 'Parse time grows with the SELECT list',
           'Median per parse, PHP 8.3 + PCRE JIT', 'per parse', fmt_ms, 'scaling-time.svg',
           lambda v: f'{v:.2f} ms' if v else '0')
line_chart('calls_upstream', 'calls_replacement', 'preg_match() calls per parse',
           'One call per token upstream; up to 64 tokens per call in the replacement', 'calls',
           lambda v: f'{v:,.0f}', 'scaling-calls.svg')
open(os.path.join(OUT, 'ablation.svg'), 'w').write(ablation())
open(os.path.join(OUT, 'cap.svg'), 'w').write(cap())
open(os.path.join(OUT, 'jit.svg'), 'w').write(jit())
print('\n'.join(sorted(os.listdir(OUT))))
