#!/usr/bin/env python3
"""Generate /compare/ — one page per confusable pair, for the "am I X or Y?" search.

WHY THIS EXISTS. "soft summer vs soft autumn", "classic vs dramatic classic",
"am I a deep winter or a deep autumn" are among the highest-intent phrases in
this whole category: somebody typing one has already taken a quiz somewhere and
does not believe the answer. They are not served by a page about ONE season.

WHY IT SCRAPES THE LIVE SITE instead of holding its own copy. Two reasons, and
the second one is the important one:

  1. It is the same rule the Pinterest pin builder follows — a derived page must
     not be able to promise something the page it links to does not say.
  2. gen_colors.py and gen_types.py ARE NO LONGER THE SOURCE OF TRUTH. Both are
     marked stale; /types/ and /colors/ have been hand-edited since 2026-09-30
     and are now far richer than either generator would produce. Reading the
     generators would produce pages that contradict the live site, and IMPORTING
     either one would re-run its build() at module scope and destroy the hand
     edits. So: HTTP only, and this script writes nothing but compare/*.html.

WHAT KEEPS THESE PAGES FROM BEING THIN DUPLICATES. A real risk, because each
parent page already carries its own "how to tell" section. The answer is that
only MUTUAL pairs get a page — where A's page has a test for B and B's page has
a different test for A. The comparison page is then the only place on the site
where both directions sit together, next to both palettes and both self-checks.
Pairs that are described from one side only are deliberately skipped: there is
nothing additional to say yet, and a page that restates one parent section is
worse than no page.

Run:  python3 gen_compare.py     (needs network; writes only compare/)
"""
import datetime, html, json, pathlib, re, urllib.request

HERE = pathlib.Path(__file__).parent
OUT = HERE / "compare"
SITE = "https://style-lore.com"
TODAY = datetime.date.today().isoformat()

SEASONS = ["light-spring","true-spring","bright-spring","light-summer","true-summer","soft-summer",
           "soft-autumn","true-autumn","deep-autumn","deep-winter","true-winter","bright-winter"]
TYPES = ["dramatic","soft-dramatic","flamboyant-natural","natural","soft-natural","dramatic-classic",
         "classic","soft-classic","flamboyant-gamine","gamine","soft-gamine","theatrical-romantic","romantic"]

def fetch(url):
    with urllib.request.urlopen(url, timeout=30) as r:
        return r.read().decode("utf-8", "replace")

def text_of(frag):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", frag)).strip()

def section(page, heading_pattern):
    m = re.search(r"<h2[^>]*>(?:" + heading_pattern + r")</h2>(.*?)(?=<h2|<p class=\"foot\")", page, re.S)
    return m.group(1) if m else ""

def scrape(kind, pid):
    """Everything one parent page can tell us. Nothing is invented here."""
    folder = "colors" if kind == "season" else "types"
    page = fetch(f"{SITE}/{folder}/{pid}.html")
    name = text_of(re.search(r"<h1[^>]*>(.*?)</h1>", page, re.S).group(1))

    hero = "#B8285A"
    m = re.search(r"--type:(#[0-9A-Fa-f]{6})", page)
    if m: hero = m.group(1)

    swatches = re.findall(r"<figcaption>(#[0-9A-Fa-f]{6})</figcaption>", page)[:6]

    # The headline mix-up: "Most often confused with <Name>" / "Commonly confused with"
    head = section(page, r"Most often confused with [^<]+|Commonly confused with")
    headline_other, headline_line = None, ""
    hm = re.search(r"<h2[^>]*>Most often confused with ([^<]+)</h2>", page)
    if hm:
        headline_other = hm.group(1).strip()
        p = re.search(r"<p>(.*?)</p>", head, re.S)
        headline_line = text_of(p.group(1)) if p else ""
    else:
        p = re.search(r"<p>(.*?)</p>", head, re.S)
        if p:
            headline_line = text_of(p.group(1))
            d = re.match(r"([A-Z][A-Za-z ]+?)\s+[—-]\s+(.*)", headline_line)
            if d:
                headline_other, headline_line = d.group(1).strip(), d.group(2).strip()

    # Per-neighbour paragraphs, keyed by the other page's id.
    neighbours = {}
    body = head + section(page, r"Neighboring seasons and how to tell")
    # seasons:  <p><b><a href="/colors/x.html">Name</a></b> — text</p>
    for m in re.finditer(r"<p><b><a href=\"/(?:colors|types)/([a-z-]+)\.html\">[^<]+</a></b>\s*[—-]\s*(.*?)</p>", body, re.S):
        neighbours[m.group(1)] = text_of(m.group(2))
    # types:    <h3 ...>Name <span>(<a href="/types/x.html">read the guide</a>)</span></h3><p>text</p>
    for m in re.finditer(r"<h3[^>]*>.*?href=\"/(?:colors|types)/([a-z-]+)\.html\".*?</h3>\s*<p>(.*?)</p>", body, re.S):
        neighbours.setdefault(m.group(1), text_of(m.group(2)))

    # The self-check card, kept as a list of questions.
    checks, check_intro = [], ""
    card = re.search(r"<div class=\"card\"><b>A three-question self-check</b>(.*?)</div>", body, re.S)
    if card:
        inner = card.group(1)
        checks = [text_of(li) for li in re.findall(r"<li>(.*?)</li>", inner, re.S)]
        ip = re.search(r"<p[^>]*>(.*?)</p>", inner, re.S)
        if ip: check_intro = text_of(ip.group(1))

    return dict(kind=kind, id=pid, name=name, hero=hero, swatches=swatches,
                headline_other=headline_other, headline_line=headline_line,
                neighbours=neighbours, checks=checks, check_intro=check_intro)

CSS = """
@import url('https://fonts.googleapis.com/css2?family=Bodoni+Moda:wght@700&family=Work+Sans:wght@400;500;600&display=swap');
:root{--paper:#FBF0F3;--raised:#FFF8F9;--ink:#2E1620;--soft:#6C4C56;--line:#EACBD3;--accent:#B8285A;}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.6 'Work Sans',-apple-system,Segoe UI,Roboto,sans-serif}
a{color:var(--accent)}.wrap{max-width:720px;margin:0 auto;padding:0 20px 60px}
.crumbs{font-size:13px;color:var(--soft);margin:6px 0 14px}.crumbs a{color:var(--soft)}
header.top{display:flex;justify-content:space-between;align-items:center;padding:18px 0}
.brand{font-family:'Bodoni Moda',Georgia,serif;font-weight:700;font-size:22px;color:var(--ink);text-decoration:none}
.hero{color:#fff;border-radius:22px;padding:34px 26px;margin:6px 0 10px}
.hero .eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.85}
.hero h1{font-family:'Bodoni Moda',Georgia,serif;font-size:40px;line-height:1.06;margin:6px 0 10px}
.hero p{margin:0;font-size:17px;opacity:.95}
h2{font-family:'Bodoni Moda',Georgia,serif;font-size:26px;margin:32px 0 10px}
h3{font-family:'Bodoni Moda',Georgia,serif;font-size:20px;margin:22px 0 6px}
ul,ol{padding-left:20px}li{margin:6px 0}
.card{background:var(--raised);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin:12px 0}
.answer{background:var(--raised);border:1px solid var(--accent);border-left:5px solid var(--accent);border-radius:14px;padding:16px 20px;margin:16px 0;font-size:17px}
.cta{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;padding:14px 24px;border-radius:999px;margin-top:8px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:14px 0}
.side{background:var(--raised);border:1px solid var(--line);border-radius:16px;padding:16px}
.side h3{margin:0 0 8px;font-size:19px}.side h3 a{text-decoration:none}
.pal{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:8px 0 2px}
.pal figure{margin:0}.pal i{display:block;height:46px;border-radius:9px;border:1px solid rgba(46,22,32,.08)}
.pal figcaption{font-size:10px;letter-spacing:.03em;color:var(--soft);text-align:center;margin-top:4px;font-variant-numeric:tabular-nums}
@media(max-width:560px){.two{grid-template-columns:1fr}}
.foot{font-size:12.5px;color:var(--soft);margin-top:40px;border-top:1px solid var(--line);padding-top:16px}
"""

def shell(title, desc, body, canonical, jsonld=""):
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{html.escape(title)}</title>
<meta name="description" content="{html.escape(desc)}">
<link rel="canonical" href="{canonical}">
<meta property="og:type" content="article">
<meta property="og:site_name" content="Style-LORE">
<meta property="og:title" content="{html.escape(title)}">
<meta property="og:description" content="{html.escape(desc)}">
<meta property="og:url" content="{canonical}">
<meta property="og:image" content="https://style-lore.com/assets/og-image.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://style-lore.com/assets/og-image.png">
<link rel="icon" href="/favicon.ico" sizes="48x48"><link rel="icon" type="image/png" sizes="96x96" href="/favicon-96.png"><link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
{jsonld}
<style>{CSS}</style>
</head>
<body>
<div class="wrap">
<header class="top"><a class="brand" href="/">Style-LORE</a><a href="/">Take the free quiz →</a></header>
{body}
<p class="foot">Style-LORE is a style quiz, closet and community built around the Kibbe body types and seasonal color analysis. These reads are a starting point, not a fixed label — daylight and your own eyes always win. <a href="/privacy.html">Privacy</a> · <a href="/terms.html">Terms</a> · <a href="/colors/">All 12 seasons</a> · <a href="/types/">All 13 Kibbe types</a> · <a href="/compare/">All comparisons</a></p>
</div>
</body>
</html>"""

def side(p, folder):
    pal = ""
    if p["swatches"]:
        pal = '<div class="pal">' + "".join(
            f'<figure><i style="background:{c}"></i><figcaption>{c}</figcaption></figure>'
            for c in p["swatches"]) + "</div>"
    return (f'<div class="side" style="border-top:4px solid {p["hero"]}">'
            f'<h3><a href="/{folder}/{p["id"]}.html">{html.escape(p["name"])}</a></h3>{pal}</div>')

def build():
    OUT.mkdir(exist_ok=True)
    pages = {}
    for kind, ids in (("season", SEASONS), ("type", TYPES)):
        for pid in ids:
            pages[(kind, pid)] = scrape(kind, pid)
            print("read", kind, pid)

    written, skipped = [], []
    for kind, ids in (("season", SEASONS), ("type", TYPES)):
        folder = "colors" if kind == "season" else "types"
        for i, a_id in enumerate(ids):
            for b_id in ids[i + 1:]:
                A, B = pages[(kind, a_id)], pages[(kind, b_id)]
                # MUTUAL ONLY -- see the module docstring.
                if b_id not in A["neighbours"] or a_id not in B["neighbours"]:
                    if b_id in A["neighbours"] or a_id in B["neighbours"]:
                        skipped.append(f"{a_id} vs {b_id} (described from one side only)")
                    continue

                slug = f"{a_id}-vs-{b_id}"
                canonical = f"{SITE}/compare/{slug}.html"
                an, bn = A["name"], B["name"]
                label = "color season" if kind == "season" else "Kibbe body type"

                # The crispest one-liner available: whichever page names the
                # other as ITS headline mix-up. That sentence was written to be
                # the answer, so it leads.
                answer = ""
                if A.get("headline_other") == bn: answer = A["headline_line"]
                elif B.get("headline_other") == an: answer = B["headline_line"]
                if not answer: answer = A["neighbours"][b_id]

                title = f"{an} vs {bn} — How to Tell Which One You Are | Style-LORE"
                desc = (f"{an} or {bn}? The one difference that separates them, both palettes side by side, "
                        f"and a self-check you can do in daylight.") if kind == "season" else \
                       (f"{an} or {bn}? What actually separates the two Kibbe types, what each one wants to wear, "
                        f"and a self-check you can do in front of a mirror.")

                faq = [
                    {"@type": "Question", "name": f"Am I {an} or {bn}?",
                     "acceptedAnswer": {"@type": "Answer", "text": answer}},
                    {"@type": "Question", "name": f"What does {an} say about {bn}?",
                     "acceptedAnswer": {"@type": "Answer", "text": A["neighbours"][b_id]}},
                    {"@type": "Question", "name": f"What does {bn} say about {an}?",
                     "acceptedAnswer": {"@type": "Answer", "text": B["neighbours"][a_id]}},
                ]
                ld = {"@context": "https://schema.org", "@graph": [
                    {"@type": "Article",
                     "headline": f"{an} vs {bn}: how to tell which one you are",
                     "description": desc, "mainEntityOfPage": canonical,
                     "datePublished": TODAY, "dateModified": TODAY, "inLanguage": "en",
                     "author": {"@type": "Organization", "name": "Style-LORE", "url": SITE},
                     "publisher": {"@type": "Organization", "name": "Style-LORE", "url": SITE,
                                   "logo": {"@type": "ImageObject", "url": f"{SITE}/email-assets/logo.png"}},
                     "about": [{"@type": "Thing", "name": an}, {"@type": "Thing", "name": bn}]},
                    {"@type": "BreadcrumbList", "itemListElement": [
                        {"@type": "ListItem", "position": 1, "name": "Style-LORE", "item": f"{SITE}/"},
                        {"@type": "ListItem", "position": 2, "name": "Comparisons", "item": f"{SITE}/compare/"},
                        {"@type": "ListItem", "position": 3, "name": f"{an} vs {bn}", "item": canonical}]},
                    {"@type": "FAQPage", "mainEntity": faq}]}

                def checklist(p):
                    if not p["checks"]: return ""
                    intro = f'<p style="margin:6px 0 0">{html.escape(p["check_intro"])}</p>' if p["check_intro"] else ""
                    items = "".join(f"<li>{html.escape(q)}</li>" for q in p["checks"])
                    return (f'<div class="card"><b>The self-check from the {html.escape(p["name"])} guide</b>'
                            f'{intro}<ul>{items}</ul></div>')

                # Do not promise the reader two DIFFERENT tests when the two
                # guides say the same thing in opposite directions -- which is
                # exactly what happens for the closest pairs. Measure it.
                wa = set(re.findall(r"[a-z]{4,}", A["neighbours"][b_id].lower()))
                wb = set(re.findall(r"[a-z]{4,}", B["neighbours"][a_id].lower()))
                overlap = len(wa & wb) / max(1, len(wa | wb))
                framing = ("Each guide describes the other from its own point of view, and the two "
                           "tests are different. This is the only page that carries both."
                           if overlap < 0.5 else
                           "Both guides describe this pair the same way from opposite directions, which "
                           "is itself the useful part \u2014 the rule reads true whichever side you start from.")

                quiz_href = f"/?season={a_id}" if kind == "season" else f"/?type={a_id}"
                body = f'''
<p class="crumbs"><a href="/">Style-LORE</a> › <a href="/compare/">Comparisons</a> › {html.escape(an)} vs {html.escape(bn)}</p>
<div class="hero" style="background:linear-gradient(110deg,{A["hero"]} 0%,{A["hero"]} 48%,{B["hero"]} 52%,{B["hero"]} 100%)">
<div class="eyebrow">{html.escape(label)} · the one that gets mixed up</div>
<h1>{html.escape(an)} vs {html.escape(bn)}</h1>
<p>How to tell which one you actually are.</p>
</div>

<div class="answer"><b>The short answer.</b> {html.escape(answer)}</div>

<div class="two">{side(A, folder)}{side(B, folder)}</div>

<h2>What separates them, from each side</h2>
<p style="color:var(--soft);font-size:14px">{framing}</p>
<h3>Read from {html.escape(an)}</h3>
<p>{html.escape(A["neighbours"][b_id])}</p>
<h3>Read from {html.escape(bn)}</h3>
<p>{html.escape(B["neighbours"][a_id])}</p>

<h2>Check it on yourself</h2>
{checklist(A)}
{checklist(B)}

<div class="card" style="border-color:var(--accent)">
<b>Still not sure?</b>
<p style="margin:6px 0 4px">The Style-LORE quiz works it out from a photo taken in daylight and shows you the reasoning rather than just the label — then checks anything in your closet, or anything you are about to buy, against the answer. Free, and no account needed to start.</p>
<a class="cta" href="{quiz_href}">Settle it with the free quiz</a>
</div>

<h2>The full guides</h2>
<p><a href="/{folder}/{a_id}.html">{html.escape(an)} — the complete guide</a><br>
<a href="/{folder}/{b_id}.html">{html.escape(bn)} — the complete guide</a></p>
'''
                (OUT / f"{slug}.html").write_text(
                    shell(title, desc, body, canonical,
                          '<script type="application/ld+json">' + json.dumps(ld) + '</script>'),
                    encoding="utf-8")
                written.append((kind, slug, f"{an} vs {bn}"))

    # Index
    rows = {"season": [], "type": []}
    for kind, slug, label in written:
        rows[kind].append(f'<li><a href="/compare/{slug}.html">{html.escape(label)}</a></li>')
    body = f'''
<p class="crumbs"><a href="/">Style-LORE</a> › Comparisons</p>
<div class="hero" style="background:linear-gradient(110deg,#6C84A0 0%,#8B6B84 100%)">
<div class="eyebrow">the pairs people actually mix up</div>
<h1>Am I this one, or that one?</h1>
<p>The pairs that get confused, and the one difference that settles each.</p>
</div>
<p>Almost nobody is unsure between two things that look nothing alike. The real doubt is always between neighbours — two seasons on the same side of muted, two Kibbe types that share a family. Each page below puts one such pair side by side, with the test from both guides rather than just one.</p>
<h2>Color seasons</h2><ul>{''.join(rows["season"])}</ul>
<h2>Kibbe body types</h2><ul>{''.join(rows["type"])}</ul>
<p style="margin-top:18px"><a href="/colors/">All twelve seasons</a> · <a href="/types/">All thirteen Kibbe types</a></p>
'''
    ld_index = {"@context": "https://schema.org", "@type": "CollectionPage",
                "name": "Style-LORE comparisons", "url": f"{SITE}/compare/",
                "description": "Side-by-side comparisons of the color seasons and Kibbe body types people most often confuse."}
    (OUT / "index.html").write_text(
        shell("Am I This One or That One? — Season & Kibbe Type Comparisons | Style-LORE",
              "The color seasons and Kibbe body types people actually confuse, side by side, with the one test that separates each pair.",
              body, f"{SITE}/compare/",
              '<script type="application/ld+json">' + json.dumps(ld_index) + '</script>'),
        encoding="utf-8")

    print()
    print("wrote %d comparison pages + index" % len(written))
    for kind, slug, label in written: print("   ", slug)
    if skipped:
        print()
        print("skipped %d one-sided pairs (nothing additional to say yet):" % len(skipped))
        for s in skipped: print("   ", s)

build()
