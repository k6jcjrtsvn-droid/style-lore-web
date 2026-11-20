#!/usr/bin/env python3
"""Generate the private run-sheets for whoever is posting to Pinterest.

NOT public pages and not part of the site's SEO. Both carry meta noindex, are
excluded in robots.txt via `Disallow: /pins/` and are deliberately absent from
sitemap.xml. They exist because the person doing the posting works off a phone,
and a folder of PNGs plus a separate document is a worse tool than one page you
scroll.

  /pins/          batch 1 — 31 pins, posted Oct/Nov 2026
  /pins/compare/  batch 2 — the 22 "am I this one or that one?" comparison pins

Batch 2 is a second page rather than 22 more cards on the first, because the
"posted" ticks live in localStorage per device: appending would have shown
Jayne's finished 31 as unticked on any fresh load. /pins/ links to it at the
top, so the link she already has still reaches the new work.

Copy comes from the kit files, never retyped here, so a page cannot promise
something the pins do not say:
  Downloads/style-lore-social/SOCIAL-LAUNCH-KIT.md   (the 6 general pins)
  Downloads/style-lore-pins/PINS-25.md               (the 25 search-term pins)
  Downloads/style-lore-pins/PINS-COMPARE-22.md       (the 22 comparison pins)

Run:  python3 gen_pins_page.py
"""
import html, io, pathlib, re

HERE = pathlib.Path(__file__).parent
OUT  = HERE / "pins"
DL   = pathlib.Path.home() / "mnt" / "Downloads"

kit  = io.open(DL / "style-lore-social" / "SOCIAL-LAUNCH-KIT.md", encoding="utf-8").read()
pins = io.open(DL / "style-lore-pins" / "PINS-25.md", encoding="utf-8").read()
comp = io.open(DL / "style-lore-pins" / "PINS-COMPARE-22.md", encoding="utf-8").read()

days = []
for m in re.finditer(r'### (pin-\d-[a-z-]+)\n\*\*Title:\*\* (.+?)\n\*\*Description:\*\*\n> (.+?)\n', kit, re.S):
    f = m.group(1) + ".png"
    days.append({"file": f, "title": m.group(2).strip(),
                 "desc": re.sub(r"\s+", " ", m.group(3)).strip(), "tags": "",
                 "dest": "https://style-lore.com/",
                 "board": "Color Analysis" if f == "pin-2-seasons.png" else "Kibbe Body Types"})
for d in days:
    if d["file"] == "pin-5-type-card.png":
        d["dest"] = "https://style-lore.com/types/flamboyant-gamine.html"


def parse_kit(text):
    """The table gives file, title and destination; the Descriptions section
    gives the body and the hashtag line. Same shape in PINS-25 and
    PINS-COMPARE-22, so one parser does both."""
    meta = {}
    for m in re.finditer(r'\| `([a-z0-9-]+\.png)` \| (.+?) \| `(https://[^`]+)` \|', text):
        meta[m.group(1)] = {"title": m.group(2).strip(), "dest": m.group(3).strip(),
                            "desc": "", "tags": ""}
    for m in re.finditer(r'\*\*`([a-z0-9-]+\.png)`\*\*\s*\n\s*> (.+?)\n\s*\n`(#[^`]+)`', text, re.S):
        if m.group(1) in meta:
            meta[m.group(1)]["desc"] = re.sub(r"\s+", " ", m.group(2)).strip()
            meta[m.group(1)]["tags"] = m.group(3).strip()
    return meta


meta = parse_kit(pins)

types   = sorted(f for f in meta if f.startswith("type-"))
seasons = sorted(f for f in meta if f.startswith("season-"))
for i in range(max(len(types), len(seasons))):
    if i < len(types):
        d = meta[types[i]];   days.append({**d, "file": types[i],   "board": "Kibbe Body Types"})
    if i < len(seasons):
        d = meta[seasons[i]]; days.append({**d, "file": seasons[i], "board": "Color Analysis"})

assert len(days) == 31, len(days)
assert all(d["desc"] for d in days), [d["file"] for d in days if not d["desc"]]

# ---------------------------------------------------------------- batch 2
cmeta = parse_kit(comp)
cfiles = sorted(f for f in cmeta if f.startswith("compare-"))
# the kit's own tables decide the board, so a pin cannot land on the wrong one
cboard = {}
for section, board in (("## Color seasons", "Color Analysis"),
                       ("## Kibbe types", "Kibbe Body Types")):
    start = comp.index(section)
    chunk = comp[start:comp.index("\n## ", start + 1)]
    for f in re.findall(r'`([a-z0-9-]+\.png)`', chunk):
        cboard[f] = board
cdays = [{**cmeta[f], "file": f, "board": cboard[f]} for f in cfiles]

assert len(cdays) == 22, len(cdays)
assert all(d["desc"] for d in cdays), [d["file"] for d in cdays if not d["desc"]]
assert all(d["board"] for d in cdays), [d["file"] for d in cdays if not d["board"]]
assert all("/compare/" in d["dest"] for d in cdays), \
    [d["file"] for d in cdays if "/compare/" not in d["dest"]]


def block(label, text, kind):
    return (f'<div class="f"><div class="l">{label}</div>'
            f'<div class="v" data-copy="{html.escape(text, quote=True)}">{html.escape(text)}</div>'
            f'<button class="c" type="button" data-kind="{kind}">Copy</button></div>')


def render(days, *, title, h1, sub, intro, imgbase, storekey, foot, unit="Day"):
    cards = []
    for i, d in enumerate(days, 1):
        full = d["desc"] + (("\n\n" + d["tags"]) if d["tags"] else "")
        cards.append(f'''
<article class="day" id="day{i}">
  <div class="shot"><img src="{imgbase}{d['file']}" alt="" loading="lazy" width="1000" height="1500"></div>
  <div class="body">
    <div class="head"><span class="n">{unit} {i}</span><span class="board">{html.escape(d['board'])}</span>
      <label class="done"><input type="checkbox" data-day="{i}"> posted</label></div>
    {block("Title", d["title"], "title")}
    {block("Destination link", d["dest"], "link")}
    {block("Description", full, "desc")}
    <a class="dl" href="{imgbase}{d['file']}" download>Save image</a>
  </div>
</article>''')

    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{title}</title>
<link rel="icon" href="/favicon.ico" sizes="48x48">
<style>
@import url('https://fonts.googleapis.com/css2?family=Bodoni+Moda:wght@700&family=Work+Sans:wght@400;500;600&display=swap');
:root{{--paper:#FBF0F3;--raised:#FFF8F9;--ink:#2E1620;--soft:#6C4C56;--line:#EACBD3;--accent:#B8285A}}
*{{box-sizing:border-box}}
body{{margin:0;background:var(--paper);color:var(--ink);font:16px/1.6 'Work Sans',-apple-system,Segoe UI,Roboto,sans-serif}}
.wrap{{max-width:620px;margin:0 auto;padding:0 16px 80px}}
header{{padding:26px 0 8px}}
h1{{font-family:'Bodoni Moda',Georgia,serif;font-size:34px;line-height:1.1;margin:0 0 6px}}
.sub{{color:var(--soft);margin:0 0 18px}}
h2{{font-family:'Bodoni Moda',Georgia,serif;font-size:22px;margin:26px 0 8px}}
.intro{{background:var(--raised);border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin:14px 0}}
.intro ol,.intro ul{{padding-left:20px;margin:8px 0}}
.intro li{{margin:6px 0}}
.warn{{border-color:var(--accent);border-left:5px solid var(--accent)}}
.jump{{display:block;background:var(--accent);color:#fff;text-decoration:none;border-radius:14px;
  padding:14px 18px;margin:14px 0;font-weight:600}}
.jump small{{display:block;font-weight:400;opacity:.9;margin-top:3px}}
.day{{background:var(--raised);border:1px solid var(--line);border-radius:18px;overflow:hidden;margin:18px 0}}
.day.ok{{opacity:.5}}
.shot{{background:#fff;border-bottom:1px solid var(--line)}}
.shot img{{display:block;width:100%;height:auto}}
.body{{padding:14px 16px 16px}}
.head{{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}}
.n{{font-family:'Bodoni Moda',Georgia,serif;font-size:21px}}
.board{{font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#fff;background:var(--accent);border-radius:99px;padding:3px 10px}}
.done{{margin-left:auto;font-size:13px;color:var(--soft);display:flex;align-items:center;gap:6px}}
.f{{position:relative;margin:10px 0;padding:10px 12px;background:var(--paper);border:1px solid var(--line);border-radius:12px}}
.l{{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--soft);margin-bottom:3px}}
.v{{font-size:15px;word-break:break-word;white-space:pre-wrap}}
.c{{position:absolute;top:8px;right:8px;background:var(--accent);color:#fff;border:0;border-radius:99px;
   padding:5px 12px;font:600 12px 'Work Sans',sans-serif;cursor:pointer}}
.c.ok{{background:#2F7A4F}}
.dl{{display:inline-block;margin-top:6px;color:var(--accent);font-weight:600;text-decoration:none;font-size:14px}}
.foot{{color:var(--soft);font-size:13px;margin-top:34px;border-top:1px solid var(--line);padding-top:16px}}
</style>
</head>
<body>
<div class="wrap">
<header>
<h1>{h1}</h1>
<p class="sub">{sub}</p>
</header>
{intro}
{''.join(cards)}

<p class="foot">{foot}</p>
</div>
<script>
(function(){{
  var K='{storekey}';
  function read(){{try{{return JSON.parse(localStorage.getItem(K)||'{{}}')}}catch(e){{return {{}}}}}}
  function write(o){{try{{localStorage.setItem(K,JSON.stringify(o))}}catch(e){{}}}}
  var done=read();
  document.querySelectorAll('input[data-day]').forEach(function(cb){{
    var d=cb.getAttribute('data-day');
    if(done[d]){{cb.checked=true;cb.closest('.day').classList.add('ok');}}
    cb.addEventListener('change',function(){{
      done[d]=cb.checked; write(done);
      cb.closest('.day').classList.toggle('ok',cb.checked);
    }});
  }});
  document.querySelectorAll('.c').forEach(function(b){{
    b.addEventListener('click',function(){{
      var t=b.parentNode.querySelector('.v').getAttribute('data-copy');
      function ok(){{b.textContent='Copied';b.classList.add('ok');setTimeout(function(){{b.textContent='Copy';b.classList.remove('ok')}},1400);}}
      if(navigator.clipboard&&navigator.clipboard.writeText){{navigator.clipboard.writeText(t).then(ok,fallback);}}else{{fallback();}}
      function fallback(){{
        var ta=document.createElement('textarea');ta.value=t;ta.style.position='fixed';ta.style.opacity='0';
        document.body.appendChild(ta);ta.select();try{{document.execCommand('copy');ok();}}catch(e){{}}document.body.removeChild(ta);
      }}
    }});
  }});
}})();
</script>
</body>
</html>'''


# ------------------------------------------------------------------ batch 1
JUMP = ('<a class="jump" href="compare/">The 22 comparison pins are ready &rarr;'
        '<small>&ldquo;Am I this one or that one?&rdquo; &mdash; a fresh sheet, '
        'same two boards. Start there once these 31 are done.</small></a>')

INTRO_1 = JUMP + '''
<div class="intro">
<h2 style="margin-top:0">Before day 1 — twenty minutes, once</h2>
<ol>
<li>Post from your own Pinterest <b>business account</b>.</li>
<li><b>Claim style-lore.com</b> on it: Settings → Claimed accounts → Claim website. Kenneth can add whatever Pinterest asks for to the site. This gives you analytics on every pin from the site and puts your profile beside them. <b>Only one Pinterest account can ever claim a site</b>, so claim it on the account you'll post from and tell Kenneth once it's done.</li>
<li>Make two boards, named exactly <b>Kibbe Body Types</b> and <b>Color Analysis</b>. Each day below says which one.</li>
<li>Put this in both board descriptions:<br><i>Free Kibbe body type and colour season quiz at style-lore.com — free on iPhone and Android.</i></li>
</ol>
</div>

<div class="intro">
<h2 style="margin-top:0">Each day — five minutes</h2>
<ol>
<li>Find today's card below and tap <b>Save image</b>.</li>
<li>Pinterest → Create → Create Pin → upload it.</li>
<li>Copy the <b>Title</b>, the <b>Description</b> and the <b>Destination link</b> across.</li>
<li>Pick the board shown, publish, tick <i>posted</i>.</li>
</ol>
<p style="margin:10px 0 0"><b>The destination link is the one that matters.</b> A Soft Summer pin landing on the Soft Summer page does far better than one dumping people on the home page — in how Pinterest ranks it and in whether anyone stays.</p>
</div>

<div class="intro warn">
<h2 style="margin-top:0">Three rules</h2>
<ul>
<li><b>One pin a day. Never a batch.</b> Pinterest suppresses accounts that dump twenty at once.</li>
<li><b>Don't judge it before day 14.</b> Pinterest is slow and then it isn't. A quiet first fortnight is normal.</li>
<li><b>Both apps are live — say so.</b> iPhone was approved on 20 November 2026, so Style-LORE is on the <b>App Store and Google Play</b>. "Free on iPhone and Android" is accurate now. The old rule said never to claim the iPhone app was out; that rule is retired.</li>
</ul>
<p style="margin:10px 0 0">Anything unclear, ask Kenneth rather than guess — especially anything claiming what the app does. Everything below is checked against the live site, so pasting it exactly is always safe.</p>
</div>
'''

FOOT_1 = ('Ticks are remembered on this device only — they’re a convenience, not a '
          'record. The 22 comparison pins are now built and waiting at '
          '<a href="compare/">/pins/compare/</a>. Thank you for doing this: Google '
          'knows our pages exist but hasn’t indexed most of them, and links from '
          'pins are exactly what changes that.')

# ------------------------------------------------------------------ batch 2
INTRO_2 = '''
<div class="intro">
<h2 style="margin-top:0">What these are</h2>
<p style="margin:0 0 8px">Twenty-two pins for the question people actually type: <i>am I a Soft Summer or a Soft Autumn? A Dramatic or a Soft Dramatic?</i> Somebody searching that has already taken a quiz somewhere and doesn't believe the answer — which makes them the easiest person on Pinterest to win.</p>
<p style="margin:0">Same two boards as before. Nothing to set up — if you did the first 31, you're already ready.</p>
</div>

<div class="intro">
<h2 style="margin-top:0">Each day — five minutes</h2>
<ol>
<li>Find today's card below and tap <b>Save image</b>.</li>
<li>Pinterest → Create → Create Pin → upload it.</li>
<li>Copy the <b>Title</b>, the <b>Description</b> and the <b>Destination link</b> across.</li>
<li>Pick the board shown, publish, tick <i>posted</i>.</li>
</ol>
<p style="margin:10px 0 0"><b>The destination link matters even more on these.</b> Each one goes to its own comparison page — the page that answers exactly the question the pin asks. Sending it to the home page instead wastes the whole pin.</p>
</div>

<div class="intro warn">
<h2 style="margin-top:0">Same three rules</h2>
<ul>
<li><b>One pin a day. Never a batch.</b></li>
<li><b>Don't judge it before day 14.</b> These are slower-burning than the first set and should keep earning for months.</li>
<li><b>Both apps are live — say so.</b> iPhone was approved on 20 November 2026, so Style-LORE is on the <b>App Store and Google Play</b>. "Free on iPhone and Android" is accurate now. The old rule said never to claim the iPhone app was out; that rule is retired.</li>
</ul>
<p style="margin:10px 0 0">Everything below is taken word for word from the page it links to, so pasting it exactly is always safe.</p>
</div>

<p style="margin:18px 0 0"><a href="../" style="color:var(--accent);font-weight:600;text-decoration:none">&larr; Back to the first 31</a></p>
'''

FOOT_2 = ('Ticks are remembered on this device only — they’re a convenience, not a '
          'record, and they are kept separately from the first 31 so neither sheet '
          'disturbs the other. Every word on these pins comes from the comparison '
          'page it links to. <a href="../">The first 31 are here.</a>')

OUT.mkdir(exist_ok=True)
(OUT / "index.html").write_text(render(
    days,
    title="Style-LORE — the Pinterest run sheet",
    h1="The Pinterest run sheet",
    sub="31 pins, one a day, about five minutes each. Everything is written — tap Copy, paste, publish.",
    intro=INTRO_1, imgbase="img/", storekey="lore_pins_done", foot=FOOT_1,
), encoding="utf-8")

(OUT / "compare").mkdir(exist_ok=True)
(OUT / "compare" / "index.html").write_text(render(
    cdays,
    title="Style-LORE — the comparison pins",
    h1="The comparison pins",
    sub="22 pins for the “am I this one or that one?” searches. One a day, about five minutes each.",
    intro=INTRO_2, imgbase="../img/", storekey="lore_pins_done_compare", foot=FOOT_2,
    unit="Pin",
), encoding="utf-8")

print("wrote pins/index.html         — %d days" % len(days))
print("wrote pins/compare/index.html — %d pins" % len(cdays))
