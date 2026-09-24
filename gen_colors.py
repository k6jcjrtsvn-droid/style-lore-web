#!/usr/bin/env python3
"""Generate /colors/ — the twelve color-season guide pages plus an index.

Companion to gen_types.py, and deliberately a SEPARATE script: gen_types.py
also rewrites robots.txt and sitemap.xml from an older copy, so running it
today would drop the /p/ and /pi/ crawler rules. This script writes only
colors/*.html and leaves every shared file alone.

SEASONS below must stay in step with SEASON_GUIDE in includes/style_brief.php
and COLOR_SEASONS in index.html. If they drift, the quiz result and the page
someone lands on will disagree, which is exactly the bug the style brief was
written to stop.

Run:  python3 gen_colors.py
"""
import datetime, html, json, os, pathlib

HERE = pathlib.Path(__file__).parent
OUT = HERE / "colors"
SITE = "https://style-lore.com"
TODAY = datetime.date.today().isoformat()

# id, name, family, blurb, best, fights, swatches, undertone, depth, chroma, contrast
SEASONS = [
 ("light-spring","Light Spring","spring",
  "Warm and light, with soft rather than intense coloring.",
  "Warm and light — peach, coral, soft aqua, buttery yellow. Keep the values close together; nothing very deep.",
  "Black, charcoal, heavy jewel tones, and anything cool and icy.",
  ["#FFD1B0","#FFA98F","#A9E5C5","#FCE79A","#AFD8E8","#F0C6DE"],.75,.2,.45,.2),
 ("true-spring","True Spring","spring",
  "Warm and clear, with medium depth and a bright, golden glow.",
  "Warm and clear at full clarity — golden yellow, coral, turquoise, grass green.",
  "Dusty or greyed-off shades, and cool blue-based darks.",
  ["#FFB27A","#FF8C69","#FFC845","#7CB518","#FF9F45","#2FBFB0"],.85,.5,.75,.55),
 ("bright-spring","Bright Spring","spring",
  "Warm and vivid — the brightest, clearest coloring in the spring family.",
  "Hot, clear brights — poppy, turquoise, bright pink, golden yellow.",
  "Muted, dusty or earthy shades; they go flat.",
  ["#FF6F59","#E63946","#00C2C7","#2E86FF","#FF3D8A","#FFD23F"],.8,.35,.95,.8),
 ("light-summer","Light Summer","summer",
  "Cool and light, with soft, powdery coloring.",
  "Cool and powdery — soft blue, lilac, rose, sage. Keep the contrast low.",
  "Black, hot brights, and orange-based warms.",
  ["#B7D3E3","#CBB9DE","#E8B6C0","#B7CBB0","#C6C4C6","#A9B4E0"],.2,.2,.3,.2),
 ("true-summer","True Summer","summer",
  "Cool and muted, with medium depth and a gentle contrast.",
  "Cool and gently greyed — rose, teal, soft navy, plum.",
  "Orange, warm golds, neon, and stark black-against-white.",
  ["#C98C93","#4E9E9B","#8A6459","#A8425E","#7C93A6","#9C7A8C"],.15,.5,.35,.45),
 ("soft-summer","Soft Summer","summer",
  "Cool, with the softest, most muted coloring of the four seasons.",
  "Muted and cool with the values kept close — sage, dusty blue, mauve, soft taupe, rose-brown.",
  "Pure black next to white, hot brights, and any orange or golden warmth.",
  ["#94A88D","#7691A8","#8B6B84","#B4A79B","#C08A8F","#6E7A82"],.3,.5,.15,.25),
 ("soft-autumn","Soft Autumn","autumn",
  "Warm and muted, with earthy, gently blended coloring.",
  "Warm and softened — camel, terracotta, olive, soft teal.",
  "Icy cool pastels and hard black.",
  ["#C69C6D","#A98E71","#C68A78","#5C8C82","#808B4A","#C1714A"],.7,.5,.25,.35),
 ("true-autumn","True Autumn","autumn",
  "Warm and rich, with medium-deep, earthy coloring.",
  "Warm and earthy at full depth — rust, olive, mustard, brick, deep teal.",
  "Cool pastels, pure white, anything icy.",
  ["#CC5B26","#6E7A2E","#B0472C","#D4A017","#7A4B2A","#2E7C6E"],.8,.65,.5,.5),
 ("deep-autumn","Deep Autumn","autumn",
  "Warm and deep, with rich, high-contrast coloring.",
  "Deep warm darks — chocolate, forest, burgundy, bronze — with real contrast between pieces.",
  "Light dusty pastels, which wash out against this depth.",
  ["#4A2E1E","#4B4A20","#6E1F2C","#A0522D","#1F4C4A","#8C6B2E"],.75,.85,.45,.7),
 ("deep-winter","Deep Winter","winter",
  "Cool and deep, with dramatic, high-contrast coloring.",
  "Cool darks with a clear bright beside them — black, true red, emerald, deep navy, icy pink.",
  "Muted earth tones, camel, warm beige.",
  ["#14151A","#C2172A","#0E6B4F","#12213D","#F0C6D6","#4B2258"],.2,.85,.5,.85),
 ("true-winter","True Winter","winter",
  "Cool and clear, with striking, high-contrast coloring.",
  "Pure cool colors at full clarity — true red, royal blue, magenta, emerald, stark white and black.",
  "Anything dusty, muted or warm-golden.",
  ["#F3D0DE","#1A5DBF","#C7268C","#12805F","#DCEAF5","#F5E11B"],.1,.6,.85,.85),
 ("bright-winter","Bright Winter","winter",
  "Cool and the most vivid of all — icy, jewel-bright coloring.",
  "Icy and jewel-bright — electric blue, hot pink, turquoise, true red.",
  "Soft muted tones and warm earths.",
  ["#1E6DF0","#FF2D87","#00C2D1","#E8112D","#8A2BE2","#A6D608"],.2,.5,.95,.9),
]

# The pair people actually mix up, and the one test that separates them.
CONFUSED = {
 "light-spring":  ("Light Summer","Both are light and soft, so the depth is no help. The undertone is. Hold peach against soft pink: Light Spring warms up next to the peach, Light Summer goes sallow and needs the pink."),
 "true-spring":   ("True Autumn","Both are warm, and that is the trap. True Spring is clear — the color looks like it has a light behind it. True Autumn is the same warmth with the light turned down and earth mixed in."),
 "bright-spring": ("Bright Winter","Both want full intensity, so brightness will not separate them. Warmth does: Bright Spring takes golden yellow and coral, Bright Winter takes icy pink and blue-red and goes greenish against gold."),
 "light-summer":  ("Light Spring","Same lightness, opposite undertone. If gold jewellery looks slightly dirty on you and silver looks clean, you are in Light Summer."),
 "true-summer":   ("Soft Summer","Both cool and both muted. True Summer can still carry a color that reads clearly — a real rose, a real teal. Soft Summer needs everything greyed a step further before it settles."),
 "soft-summer":   ("Soft Autumn","The single most common mix-up in color analysis, because both are muted and neither can take a hard bright. Soft Summer is cool-muted and wants sage and mauve; Soft Autumn is warm-muted and wants camel and terracotta."),
 "soft-autumn":   ("Soft Summer","Both are gentle and blended, so softness proves nothing. Put camel next to greyed mauve: Soft Autumn comes alive on the camel, Soft Summer looks tired by it."),
 "true-autumn":   ("Deep Autumn","Same warmth, different depth. True Autumn sits at medium — rust and mustard. Deep Autumn needs chocolate and burgundy, and medium shades start to look washed out on it."),
 "deep-autumn":   ("Deep Winter","Both go dark and both take contrast. Deep Autumn's darks have brown and bronze in them; Deep Winter's have blue and black. Compare chocolate against true black — one of them will look like it belongs on you."),
 "deep-winter":   ("Deep Autumn","Both are deep and dramatic. Deep Winter is cool: black works, camel does not. If warm beige drains you and pure white does not, you are on the winter side."),
 "true-winter":   ("Bright Winter","Both cool and clear. True Winter is balanced — it can wear stark black and white as the whole outfit. Bright Winter wants the intensity turned all the way up and looks slightly flat in plain black and white."),
 "bright-winter": ("Bright Spring","Both are the loudest of their family. Bright Winter's brights are icy underneath; Bright Spring's have gold in them. Test electric blue against turquoise."),
}

NEUTRALS = {
 "spring":"Warm light neutrals: ivory, camel, warm beige, light denim, soft gold-brown. Skip pure white and black — both are harsher than anything in your own coloring.",
 "summer":"Cool soft neutrals: soft navy, greyed taupe, oyster, cocoa with a rose cast, and a grey that leans blue rather than yellow. Soft white beats stark white.",
 "autumn":"Earthy neutrals: camel, olive, chocolate, warm taupe, oatmeal. These are your blacks — an autumn wardrobe built on brown reads more expensive than one built on black.",
 "winter":"Stark neutrals: true black, charcoal, pure white, deep navy. Winter is the one family that can wear real black next to real white and look better for it.",
}

def metals(u):
    return ("Gold, brass, bronze and copper. Silver tends to look grey and flat against warm skin."
            if u >= .5 else
            "Silver, platinum and white gold. Yellow gold usually reads slightly dirty against cool skin.")

def contrast_note(c):
    if c < .35:
        return "Keep the values close. A low-contrast outfit — one family of tones head to toe — reads as intentional on you, while a hard light-against-dark split looks like the clothes are wearing you."
    if c < .65:
        return "Moderate contrast. You can put a lighter piece against a darker one, but the jump should be a step, not a cliff."
    return "Let the pieces contrast. A light piece against a dark one matches the contrast already in your own coloring; an all-mid-tone outfit can look like it has gone slightly grey."

def lum(hexs):
    r, g, b = (int(hexs[i:i+2], 16) / 255 for i in (1, 3, 5))
    f = lambda c: c / 12.92 if c <= .03928 else ((c + .055) / 1.055) ** 2.4
    return .2126 * f(r) + .7152 * f(g) + .0722 * f(b)

def hero_color(swatches):
    """The darkest swatch, darkened further until white text on it passes AA.
    Several seasons are pale by definition, so picking a swatch straight off
    the palette would put white type on powder blue."""
    c = min(swatches, key=lum)
    r, g, b = (int(c[i:i+2], 16) for i in (1, 3, 5))
    for _ in range(40):
        if (1.05) / (lum("#%02X%02X%02X" % (r, g, b)) + .05) >= 4.5:
            break
        r, g, b = int(r * .92), int(g * .92), int(b * .92)
    return "#%02X%02X%02X" % (r, g, b)

def page(title, desc, body, canonical, color, jsonld=""):
    return f'''<!doctype html>
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Bodoni+Moda:wght@700&family=Work+Sans:wght@400;500;600&display=swap');
:root{{--paper:#FBF0F3;--raised:#FFF8F9;--ink:#2E1620;--soft:#6C4C56;--line:#EACBD3;--accent:#B8285A;--type:{color};}}
*{{box-sizing:border-box}}body{{margin:0;background:var(--paper);color:var(--ink);font:16px/1.6 'Work Sans',-apple-system,Segoe UI,Roboto,sans-serif}}
a{{color:var(--accent)}}.wrap{{max-width:720px;margin:0 auto;padding:0 20px 60px}}
.crumbs{{font-size:13px;color:var(--soft);margin:6px 0 14px}}.crumbs a{{color:var(--soft)}}
header.top{{display:flex;justify-content:space-between;align-items:center;padding:18px 0}}
.brand{{font-family:'Bodoni Moda',Georgia,serif;font-weight:700;font-size:22px;color:var(--ink);text-decoration:none}}
.hero{{background:var(--type);color:#fff;border-radius:22px;padding:34px 26px;margin:6px 0 26px}}
.hero .eyebrow{{font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.8}}
.hero h1{{font-family:'Bodoni Moda',Georgia,serif;font-size:42px;line-height:1.05;margin:6px 0 10px}}
.hero p{{margin:0;font-size:17px;opacity:.94}}
h2{{font-family:'Bodoni Moda',Georgia,serif;font-size:26px;margin:32px 0 10px}}
ul{{padding-left:20px}}li{{margin:6px 0}}
.card{{background:var(--raised);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin:12px 0}}
.cta{{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;padding:14px 24px;border-radius:999px;margin-top:8px}}
.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}}
.tile{{display:block;color:#fff;text-decoration:none;border-radius:16px;padding:16px;min-height:96px}}
.tile b{{font-family:'Bodoni Moda',Georgia,serif;font-size:19px;display:block}}.tile span{{font-size:12.5px;opacity:.9}}
.tile .sw{{display:flex;gap:4px;margin-bottom:10px}}.tile .sw i{{flex:1 1 0;height:22px;border-radius:5px}}
.pal{{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin:14px 0 6px}}
.pal figure{{margin:0}}.pal i{{display:block;height:74px;border-radius:12px;border:1px solid rgba(46,22,32,.08)}}
.pal figcaption{{font-size:11px;letter-spacing:.04em;color:var(--soft);text-align:center;margin-top:6px;font-variant-numeric:tabular-nums}}
.dials{{display:grid;grid-template-columns:1fr;gap:10px;margin:14px 0}}
.dial{{display:grid;grid-template-columns:96px 1fr 80px;align-items:center;gap:10px;font-size:13.5px}}
.dial b{{font-weight:600}}.dial u{{text-decoration:none;display:block;height:8px;border-radius:99px;background:var(--line);position:relative}}
.dial u s{{position:absolute;top:0;left:0;height:8px;border-radius:99px;background:var(--type);display:block}}
.dial span{{color:var(--soft);text-align:right}}
@media(max-width:560px){{.pal{{grid-template-columns:repeat(3,1fr)}}}}
.foot{{font-size:12.5px;color:var(--soft);margin-top:40px;border-top:1px solid var(--line);padding-top:16px}}
</style>
</head>
<body>
<div class="wrap">
<header class="top"><a class="brand" href="/">Style-LORE</a><a href="/">Take the free quiz →</a></header>
{body}
<p class="foot">Style-LORE is a style quiz, closet and community built around the Kibbe body types and seasonal color analysis. A season is a starting read, not a fixed label — daylight and your own eyes always win. <a href="/privacy.html">Privacy</a> · <a href="/terms.html">Terms</a> · <a href="/colors/">All 12 seasons</a> · <a href="/types/">All 13 Kibbe types</a></p>
</div>
</body>
</html>'''

def dial(label, value, low, high):
    # Three bands, not two. A value sitting on .5 is genuinely in the middle,
    # and calling Soft Summer "Deep" because depth rounds up reads as wrong to
    # anyone who knows the system.
    word = low if value < .4 else (high if value > .6 else "Medium")
    return (f'<div class="dial"><b>{label}</b><u><s style="width:{round(value*100)}%"></s></u>'
            f'<span>{word}</span></div>')

def swatch_row(sw):
    return '<div class="pal">' + ''.join(
        f'<figure><i style="background:{c}"></i><figcaption>{c}</figcaption></figure>' for c in sw) + '</div>'

def tile(s):
    sid, name, fam, blurb, *_ = s
    sw = s[6]
    return (f'<a class="tile" href="/colors/{sid}.html" style="background:{hero_color(sw)}">'
            f'<span class="sw">{"".join(f"<i style=background:{c}></i>" for c in sw)}</span>'
            f'<b>{html.escape(name)}</b><span>{html.escape(blurb)}</span></a>')

FAMILY_NAME = {"spring":"Spring","summer":"Summer","autumn":"Autumn","winter":"Winter"}

def build():
    OUT.mkdir(exist_ok=True)
    by_id = {s[0]: s for s in SEASONS}
    urls = []

    for s in SEASONS:
        sid, name, fam, blurb, best, fights, sw, u, d, ch, co = s
        hero = hero_color(sw)
        conf_name, conf_text = CONFUSED[sid]
        canonical = f"{SITE}/colors/{sid}.html"
        title = f"{name} Color Palette — Colors That Suit You, and the Ones That Don't | Style-LORE"
        desc = (f"The {name} color season explained: {blurb} The six colors that carry it, what to avoid, "
                f"the metals that suit you, and how to tell it apart from {conf_name}.")
        related = [by_id[o[0]] for o in SEASONS if o[2] == fam and o[0] != sid]
        if conf_name:
            match = [o for o in SEASONS if o[1] == conf_name]
            if match and match[0] not in related:
                related.append(match[0])

        body = f'''
<div class="hero"><div class="eyebrow">Color season</div><h1>{html.escape(name)}</h1><p>{html.escape(blurb)}</p></div>

<h2>The {html.escape(name)} palette</h2>
{swatch_row(sw)}
<p style="font-size:13.5px;color:var(--soft);margin-top:4px">Six colors that carry this season. They are a direction, not a uniform — the point is the family they belong to, not these exact swatches.</p>

<h2>What defines it</h2>
<div class="dials">
{dial("Undertone", u, "Cool", "Warm")}
{dial("Depth", d, "Light", "Deep")}
{dial("Clarity", ch, "Muted", "Clear")}
{dial("Contrast", co, "Low", "High")}
</div>
<p>{html.escape(contrast_note(co))}</p>

<h2>Colors that work</h2>
<p>{html.escape(best)}</p>

<h2>Colors that fight it</h2>
<p>{html.escape(fights)}</p>

<h2>Neutrals to build on</h2>
<p>{html.escape(NEUTRALS[fam])}</p>

<h2>Metals</h2>
<p>{html.escape(metals(u))}</p>

<h2>Most often confused with {html.escape(conf_name)}</h2>
<p>{html.escape(conf_text)}</p>

<div class="card" style="border-color:var(--accent)">
<b>Not sure you're a {html.escape(name)}?</b>
<p style="margin:6px 0 4px">The Style-LORE quiz reads your undertone, depth, clarity and contrast from a photo taken in daylight, and gives you your season with the reasoning shown — then checks anything in your closet, or anything you're about to buy, against it. Free, no account needed to start.</p>
<a class="cta" href="/?season={sid}">Find your season free</a>
</div>

<h2>Related seasons</h2>
<div class="grid">{''.join(tile(o) for o in related)}</div>
<p style="margin-top:18px"><a href="/colors/">See all twelve seasons</a> · <a href="/types/">The 13 Kibbe body types</a></p>
'''
        ld = {"@context":"https://schema.org","@graph":[
          {"@type":"Article","headline":f"{name} color season: your palette, your neutrals, your metals",
           "description":desc,"mainEntityOfPage":canonical,"datePublished":"2026-09-24","dateModified":TODAY,
           "inLanguage":"en","author":{"@type":"Organization","name":"Style-LORE","url":SITE},
           "publisher":{"@type":"Organization","name":"Style-LORE","url":SITE,
             "logo":{"@type":"ImageObject","url":f"{SITE}/email-assets/logo.png"}},
           "about":{"@type":"Thing","name":f"{name} (seasonal color analysis)"}},
          {"@type":"BreadcrumbList","itemListElement":[
            {"@type":"ListItem","position":1,"name":"Style-LORE","item":SITE+"/"},
            {"@type":"ListItem","position":2,"name":"Color seasons","item":SITE+"/colors/"},
            {"@type":"ListItem","position":3,"name":name,"item":canonical}]}]}
        crumbs = (f'<nav class="crumbs" aria-label="Breadcrumb"><a href="/">Style-LORE</a> › '
                  f'<a href="/colors/">Color seasons</a> › {html.escape(name)}</nav>')
        (OUT / f"{sid}.html").write_text(
            page(title, desc, crumbs + body, canonical, hero,
                 '<script type="application/ld+json">' + json.dumps(ld) + '</script>'),
            encoding="utf-8")
        urls.append(canonical)
        print("wrote colors/%s.html  hero %s" % (sid, hero))

    # ---- index ----
    fams = ""
    for fam in ("spring", "summer", "autumn", "winter"):
        members = [s for s in SEASONS if s[2] == fam]
        fams += (f'<h2>{FAMILY_NAME[fam]}</h2><div class="grid">'
                 + ''.join(tile(s) for s in members) + '</div>')
    body = f'''
<nav class="crumbs" aria-label="Breadcrumb"><a href="/">Style-LORE</a> › Color seasons</nav>
<div class="hero" style="background:var(--accent)"><div class="eyebrow">Guides</div><h1>The 12 color seasons</h1><p>Which colors make you look rested, and which ones quietly drain you.</p></div>
<p>Seasonal color analysis sorts coloring on four dials — <b>undertone</b> (warm or cool), <b>depth</b> (light or deep), <b>clarity</b> (muted or clear) and <b>contrast</b> (how far apart your own hair, skin and eyes sit). Those four produce twelve seasons across the four families. Your season is not about what you like; it is about which colors stop competing with your face.</p>
<p>Most people who feel "nothing suits me" are wearing the right depth in the wrong undertone, or the right undertone at the wrong clarity. The pages below give each season's palette, the neutrals worth building a wardrobe on, which metals to wear, and the one season it is most often mistaken for.</p>
{fams}
<div class="card" style="border-color:var(--accent);margin-top:24px"><b>Find yours in two minutes</b><p style="margin:6px 0 4px">Style-LORE reads your undertone, depth, clarity and contrast, gives you your season and your palette, then checks your closet against it. Free, no account needed to start.</p><a class="cta" href="/">Find your season free</a></div>
<p style="margin-top:18px">Color is half of it. The other half is line — see <a href="/types/">the 13 Kibbe body types</a>.</p>
'''
    ld_index = {"@context":"https://schema.org","@type":"CollectionPage","name":"The 12 color seasons",
      "url":SITE+"/colors/","description":"Every seasonal color analysis season explained, with palettes, neutrals and metals.",
      "mainEntity":{"@type":"ItemList","itemListElement":[
        {"@type":"ListItem","position":i+1,"name":s[1],"url":f"{SITE}/colors/{s[0]}.html"}
        for i, s in enumerate(SEASONS)]}}
    (OUT / "index.html").write_text(
        page("The 12 Color Seasons — Palettes, Neutrals & a Free Quiz | Style-LORE",
             "Every color season explained: Light, True and Bright Spring; Light, True and Soft Summer; "
             "Soft, True and Deep Autumn; Deep, True and Bright Winter. Palettes, neutrals, metals and a free quiz.",
             body, f"{SITE}/colors/", "#B8285A",
             '<script type="application/ld+json">' + json.dumps(ld_index) + '</script>'),
        encoding="utf-8")
    print("wrote colors/index.html")
    print("%d pages" % (len(urls) + 1))

build()
