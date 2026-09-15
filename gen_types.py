#!/usr/bin/env python3
"""Generates web/types/*.html — one public, crawlable guide per Kibbe type —
plus web/types/index.html, sitemap.xml and robots.txt. Content lives here so
the pages can be regenerated after a wording change; run and commit."""
import json, os, html, datetime

TYPES = json.load(open('/tmp/kibbe.json'))
COLORS = {
 'dramatic':'#24243D','soft-dramatic':'#4B2142','flamboyant-natural':'#7A4A24','natural':'#4F6B3A','soft-natural':'#8C7A5C',
 'dramatic-classic':'#1F3A5C','classic':'#35577A','soft-classic':'#6C84A0','flamboyant-gamine':'#B3491C','gamine':'#B3821F',
 'soft-gamine':'#C98BA0','theatrical-romantic':'#7C2348','romantic':'#A15A73'}

# Per-type editorial: what works, what fights the lines, three outfits, the
# most common mix-up. Written to be useful to a human, not just to Google.
G = {
 'dramatic': dict(
  works=["Long, unbroken vertical lines: a column dress, a floor-length coat, a single-breasted suit worn as one piece.",
         "Crisp, structured fabrics with some weight — gabardine, heavy crepe, leather, dense wool — that hold a sharp edge.",
         "One large, bold detail rather than many small ones: an oversized lapel, a single statement earring, a wide belt.",
         "Sharp geometry in accessories: pointed toes, angular bags, long straight pendants."],
  avoid=["Small, fussy details — ruffles, tiny prints, delicate jewelry — get lost on a long, sharp frame.",
         "Soft, clingy or overly draped fabrics blur the lines that are your strongest feature.",
         "Anything that chops the vertical: wide contrasting belts at the natural waist, cropped-and-boxy combinations."],
  outfits=["A black column dress, sharp pointed pumps, one long metal earring.",
           "A long camel overcoat over a slim turtleneck and straight trousers, all in one tone.",
           "A leather jacket with a strong shoulder, high-waisted wide trousers, a structured tote."],
  mixup="Soft Dramatic — both are long and striking, but Soft Dramatic carries visible curve and wants fluid fabrics; pure Dramatic wants the fabric to stand up on its own."),
 'soft-dramatic': dict(
  works=["Long lines built in liquid fabrics: bias-cut silk, matte jersey, velvet, satin that pools and drapes.",
         "Draped detail that follows the body — a cowl neck, a wrap dress, a sarong skirt, a plunging neckline.",
         "Glamour at scale: large jewelry, a dramatic sleeve, a bold print that is big rather than busy.",
         "Waist emphasis with softness — a sash or a soft belt rather than a rigid one."],
  avoid=["Stiff, boxy tailoring with no waist reads heavy on a curvy, elongated frame.",
         "Tiny, dainty details disappear; a delicate chain does nothing for you.",
         "Very short hemlines and cropped jackets that cut the long line."],
  outfits=["A bias-cut emerald slip dress, gold cuff, strappy heels.",
           "A draped wrap top tucked into wide fluid trousers, with one oversized hoop.",
           "A long soft-shouldered coat over a knit column dress, belted loosely."],
  mixup="Theatrical Romantic — both are curvy and glamorous, but Theatrical Romantic is petite and compact; Soft Dramatic is long and needs scale."),
 'flamboyant-natural': dict(
  works=["Relaxed, unconstructed tailoring: an oversized blazer, a long trench, a boyfriend coat.",
         "Natural textures with body — linen, suede, chunky knit, raw denim, tweed.",
         "Long, easy layers that drape rather than cinch; asymmetry and undone details.",
         "Chunky, organic accessories: a wide leather cuff, a big slouchy bag, a flat wide-brim hat."],
  avoid=["Anything fitted through the shoulder and rigid — a sharp, tight jacket looks borrowed.",
         "Symmetry and precision: matching sets, tiny neat details, delicate jewelry.",
         "Short, fussy hemlines and anything that reads 'dainty'."],
  outfits=["An oversized camel blazer, white tee, straight jeans and loafers.",
           "A long knit cardigan over a slip skirt with flat boots.",
           "A linen jumpsuit with rolled sleeves and a wide leather belt sitting low."],
  mixup="Dramatic — both are long, but Dramatic wants sharp and structured; Flamboyant Natural wants relaxed and broad."),
 'natural': dict(
  works=["Clean, easy shapes with a relaxed fit: a straight coat, a boxy jacket, a simple shift.",
         "Matte, textured fabrics — cotton, wool, linen, suede — in earthy or muted tones.",
         "Minimal ornamentation; let the texture and the cut do the talking.",
         "Practical, mid-scale accessories: a leather satchel, a plain belt, a wooden bangle."],
  avoid=["Stiff, sharp tailoring with exaggerated shoulders.",
         "Ornate detail — ruffles, sequins, lace — reads fussy against blunt, broad lines.",
         "Very fitted, body-conscious silhouettes that fight the width of the frame."],
  outfits=["A boxy denim jacket, a plain tee, wide cropped trousers and white sneakers.",
           "A relaxed wool coat over a rib knit dress with ankle boots.",
           "A linen shirt half-tucked into tapered chinos, leather sandals."],
  mixup="Soft Natural — the softer sibling adds curve and wants gentle waist definition; pure Natural keeps it straighter."),
 'soft-natural': dict(
  works=["Relaxed shapes with a gently defined waist: a wrap dress in a soft fabric, a belted cardigan.",
         "Soft, slightly textured fabrics — brushed cotton, jersey, fine knit, washed silk.",
         "Rounded, unfussy details: a scoop neck, a soft blouse, a slightly gathered skirt.",
         "Natural, mid-scale accessories with a little softness — a rounded bag, a hoop earring."],
  avoid=["Rigid tailoring and sharp shoulders overwhelm the softness.",
         "Heavy embellishment and very ornate prints.",
         "Anything that hides the waist entirely on a frame that has one."],
  outfits=["A soft wrap dress in rust jersey with tan flat sandals.",
           "An oversized cardigan belted over a slip skirt, ankle boots.",
           "A washed-silk blouse tucked into straight jeans with a rounded shoulder bag."],
  mixup="Flamboyant Natural — both are broad and relaxed, but Flamboyant Natural is longer and wants no waist emphasis; Soft Natural wants a little."),
 'dramatic-classic': dict(
  works=["Precise tailoring with a slight edge: a sharp-lapel blazer, a pencil skirt, a tailored shirt dress.",
         "Smooth, medium-weight fabrics with structure — crepe, fine wool, cotton sateen.",
         "Symmetrical, clean lines and a little contrast: a crisp collar, a defined shoulder.",
         "Polished, geometric accessories: a structured bag, a pointed flat, a simple chain."],
  avoid=["Slouchy, unstructured pieces look untidy rather than relaxed.",
         "Heavy ornamentation and romantic detail.",
         "Anything oversized — scale is moderate and the frame is balanced."],
  outfits=["A navy sharp-shouldered blazer, white shirt, cigarette trousers and pointed flats.",
           "A tailored shirt dress with a slim belt and a structured bag.",
           "A fine-knit top with a pencil skirt and block-heel pumps."],
  mixup="Classic — pure Classic is fully balanced; Dramatic Classic carries a little sharpness and can take a bolder shoulder or line."),
 'classic': dict(
  works=["Balanced, symmetrical, moderate everything: a well-cut blazer, a knee-length skirt, a simple sheath.",
         "Smooth, refined fabrics — fine wool, silk blends, quality cotton — in clean solids or subtle patterns.",
         "Timeless details: a notched collar, a straight hem, a neat cuff.",
         "Understated, medium-scale accessories: pearl studs, a leather loafer, a classic tote."],
  avoid=["Anything extreme — oversized, ultra-fitted, very ornate or very undone.",
         "Loud prints and heavy texture that disrupt the smoothness.",
         "Trend-driven pieces with exaggerated proportions."],
  outfits=["A camel sheath dress, nude pumps and a slim watch.",
           "A grey blazer, silk shell, straight trousers and loafers.",
           "A trench over a fine-knit top and a straight midi skirt."],
  mixup="Soft Classic — adds a little curve and softness; Dramatic Classic adds a little edge. Pure Classic sits right in the middle."),
 'soft-classic': dict(
  works=["Balanced shapes softened at the edges: a rounded lapel, a slightly gathered sleeve, a soft sheath.",
         "Smooth fabrics with a little drape — crepe de chine, soft wool, matte jersey.",
         "Gentle waist definition and rounded necklines.",
         "Refined, slightly delicate accessories: a small pendant, a rounded-toe pump, a soft leather bag."],
  avoid=["Sharp, angular tailoring and hard geometric details.",
         "Anything heavy, chunky or oversized.",
         "Overly ornate or frilly pieces — soft, not fussy."],
  outfits=["A blush wrap blouse, straight cream trousers and rounded-toe flats.",
           "A soft sheath dress with a slim belt and a small structured bag.",
           "A fine cardigan over a scoop-neck top with a flowing midi skirt."],
  mixup="Soft Natural — both are soft, but Soft Natural is broader and more relaxed; Soft Classic stays polished and symmetrical."),
 'flamboyant-gamine': dict(
  works=["Sharp, compact tailoring: a cropped jacket, a boxy blazer, a straight mini skirt.",
         "Crisp fabrics with graphic contrast — bold stripes, colorblocking, geometric prints.",
         "Broken-up lines and mixed pieces rather than one long silhouette.",
         "Playful, angular accessories: a chunky sneaker, a geometric earring, a small structured bag."],
  avoid=["Long, unbroken flowing lines that swallow a compact frame.",
         "Soft, draped, romantic detail.",
         "Oversized pieces with no shape."],
  outfits=["A cropped leather jacket, striped tee, straight jeans and loafers.",
           "A boxy tweed jacket with a mini skirt and ankle boots.",
           "A colorblocked knit with tapered trousers and a chunky sneaker."],
  mixup="Gamine — pure Gamine is more mixed and playful; Flamboyant Gamine leans sharper and a touch longer in line."),
 'gamine': dict(
  works=["Compact, mixed shapes: a fitted top with a straight skirt, a short jacket over a slim dress.",
         "Crisp fabrics and lively details — contrast trim, buttons, small graphic prints.",
         "Broken lines and a bit of contrast in every outfit; a mix of sharp and soft.",
         "Small-to-medium, playful accessories: a beret, a small crossbody, a stud earring."],
  avoid=["Long, heavy, flowing pieces that overwhelm a small frame.",
         "Very plain, monochrome, unbroken looks that read flat on you.",
         "Large-scale accessories and big prints."],
  outfits=["A fitted striped top, a cropped cardigan and a straight mini skirt.",
           "A short trench over a slim knit dress with flat ankle boots.",
           "A collared blouse with contrast buttons, tapered trousers and loafers."],
  mixup="Soft Gamine — adds roundness and wants softer fabrics; pure Gamine is crisper and more mixed."),
 'soft-gamine': dict(
  works=["Compact shapes with a little curve: a fitted cardigan, a rounded-collar blouse, a flared mini skirt.",
         "Soft, slightly textured fabrics — fine knit, cotton with stretch, lightweight tweed.",
         "Playful, rounded detail: a peter pan collar, a puff sleeve, a bow at small scale.",
         "Small, sweet accessories: a rounded bag, a ballet flat, a small hoop."],
  avoid=["Long, straight, severe lines and hard geometry.",
         "Oversized, heavy or very structured pieces.",
         "Large-scale prints and chunky jewelry."],
  outfits=["A fitted rounded-collar blouse, a flared mini skirt and ballet flats.",
           "A cropped cardigan over a fit-and-flare dress with a small crossbody.",
           "A soft knit top, high-waisted cropped trousers and a rounded loafer."],
  mixup="Theatrical Romantic — both are petite and curvy, but Theatrical Romantic is fully rounded and glamorous; Soft Gamine keeps some crispness and contrast."),
 'theatrical-romantic': dict(
  works=["Fitted, curve-following shapes with a sharp accent: a corseted top, a wrap dress with a deep V, a pencil skirt.",
         "Luxurious, fluid fabrics — silk, satin, velvet, fine lace — that hug rather than hang.",
         "Waist emphasis always, plus one sharp detail: a pointed shoe, a defined shoulder, a bold earring.",
         "Small-scale, glamorous accessories: delicate but sparkly, never chunky."],
  avoid=["Boxy, loose or heavy pieces that hide the curve.",
         "Rough, matte, oversized textures.",
         "Anything very long and unbroken — the frame is compact."],
  outfits=["A fitted wrap dress in ruby satin with pointed slingbacks.",
           "A corset-style top, a high-waisted pencil skirt and a small jeweled clutch.",
           "A fitted knit with a draped midi skirt and a delicate drop earring."],
  mixup="Romantic — pure Romantic is fully soft and rounded; Theatrical Romantic carries a sharp edge and can take a pointed toe or a defined shoulder."),
 'romantic': dict(
  works=["Soft, curve-following silhouettes: a draped dress, a rounded neckline, a gathered waist.",
         "Plush, fluid fabrics — velvet, chiffon, jersey, cashmere, soft lace.",
         "Rounded detail everywhere: ruffles at small scale, soft bows, a scoop or sweetheart neckline.",
         "Delicate, rounded, slightly ornate accessories: a pearl drop, a soft satchel, a rounded-toe heel."],
  avoid=["Sharp tailoring, straight boxy lines and hard geometry.",
         "Rough or stiff textures — heavy denim, canvas, leather with a hard edge.",
         "Oversized, long, unbroken pieces."],
  outfits=["A draped blush dress with a sweetheart neckline and rounded-toe heels.",
           "A soft cashmere sweater tucked into a flowing midi skirt with ballet flats.",
           "A velvet wrap top, a gathered satin skirt and a pearl drop earring."],
  mixup="Soft Classic — both are soft, but Soft Classic is balanced and polished; Romantic is all curve and needs the fabric to move."),
}

OUT = '/home/claude/lore-work/f/types'
os.makedirs(OUT, exist_ok=True)
SITE = 'https://style-lore.com'
TODAY = datetime.date.today().isoformat()
today = datetime.date.today().isoformat()

def page(title, desc, body, canonical, color='#B8285A', jsonld=''):
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
.tile img{{width:100%;height:auto;aspect-ratio:360/440;border-radius:10px;display:block;margin-bottom:10px}}
.looks{{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:0 0 22px}}.looks img{{width:100%;height:auto;border-radius:18px;box-shadow:0 8px 24px -12px rgba(28,26,22,.25)}}.looks figcaption{{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b665e;text-align:center;margin-top:8px}}.looks figure{{margin:0}}
.looks-note{{font-size:14px;color:#6b665e;margin:-10px 0 24px}}
.foot{{font-size:12.5px;color:var(--soft);margin-top:40px;border-top:1px solid var(--line);padding-top:16px}}
</style>
</head>
<body>
<div class="wrap">
<header class="top"><a class="brand" href="/">Style-LORE</a><a href="/">Take the free quiz →</a></header>
{body}
<p class="foot">Style-LORE is a style quiz, closet and community built around the Kibbe body types. Results are a starting read, not a fixed label. <a href="/privacy.html">Privacy</a> · <a href="/terms.html">Terms</a> · <a href="/types/">All 13 types</a></p>
</div>
</body>
</html>'''

urls = []
for t in TYPES:
    g = G[t['id']]
    others = [o for o in TYPES if o['family'] == t['family'] and o['id'] != t['id']]
    title = f"{t['name']} Kibbe Type — What to Wear, What to Skip, 3 Outfits | Style-LORE"
    desc = f"The {t['name']} Kibbe body type explained: {t['tag']} What works, what fights your lines, three outfit ideas, and the type it's most often confused with."
    canonical = f"{SITE}/types/{t['id']}.html"
    li = lambda xs: ''.join(f'<li>{html.escape(x)}</li>' for x in xs)
    body = f'''
<div class="hero"><div class="eyebrow">Kibbe body type</div><h1>{html.escape(t['name'])}</h1><p>{html.escape(t['tag'])}</p></div>
<div class="looks">
<figure><img src="/img/kibbe/{t['id']}-w.svg" width="360" height="440" alt="{html.escape(t['name'])} outfit idea, womenswear" loading="lazy"><figcaption>Womenswear</figcaption></figure>
<figure><img src="/img/kibbe/{t['id']}-m.svg" width="360" height="440" alt="{html.escape(t['name'])} outfit idea, menswear" loading="lazy"><figcaption>Menswear</figcaption></figure>
</div>
<p class="looks-note">One outfit in {html.escape(t['name'])} lines for each wardrobe — a starting idea, not a rule.</p>
<p>{html.escape(t['desc'])}</p>
<h2>What works on a {html.escape(t['name'])}</h2>
<ul>{li(g['works'])}</ul>
<h2>What fights your lines</h2>
<ul>{li(g['avoid'])}</ul>
<h2>Three outfits to start with</h2>
{''.join(f'<div class="card">{html.escape(o)}</div>' for o in g['outfits'])}
<h2>Most often confused with</h2>
<p>{html.escape(g['mixup'])}</p>
<div class="card" style="border-color:var(--accent)">
<b>Not sure you're a {html.escape(t['name'])}?</b>
<p style="margin:6px 0 4px">The Style-LORE quiz takes about two minutes and works out your type from your bone structure, flesh and scale — then checks your closet against it and gives you a verdict on anything before you buy it. Free, no account needed to start.</p>
<a class="cta" href="/?type={t['id']}">Take the free quiz</a>
</div>
<h2>Related types</h2>
<div class="grid">{''.join(f'<a class="tile" href="/types/{o["id"]}.html" style="background:{COLORS[o["id"]]}"><img src="/img/kibbe/{o["id"]}-w.svg" width="360" height="440" alt="" loading="lazy"><b>{html.escape(o["name"])}</b><span>{html.escape(o["tag"])}</span></a>' for o in others)}</div>
'''
    ld = {
      "@context": "https://schema.org",
      "@graph": [
        {"@type": "Article", "headline": f"{t['name']} Kibbe body type: what to wear, what to skip", "description": desc,
         "mainEntityOfPage": canonical, "datePublished": "2026-09-13", "dateModified": TODAY, "inLanguage": "en",
         "author": {"@type": "Organization", "name": "Style-LORE", "url": SITE},
         "publisher": {"@type": "Organization", "name": "Style-LORE", "url": SITE, "logo": {"@type": "ImageObject", "url": f"{SITE}/email-assets/logo.png"}},
         "about": {"@type": "Thing", "name": f"{t['name']} (Kibbe body type)"},
         "image": [f"{SITE}/img/kibbe/{t['id']}-w.svg", f"{SITE}/img/kibbe/{t['id']}-m.svg"]},
        {"@type": "BreadcrumbList", "itemListElement": [
          {"@type": "ListItem", "position": 1, "name": "Style-LORE", "item": SITE + "/"},
          {"@type": "ListItem", "position": 2, "name": "Kibbe body types", "item": SITE + "/types/"},
          {"@type": "ListItem", "position": 3, "name": t['name'], "item": canonical}]}
      ]}
    body = f'<nav class="crumbs" aria-label="Breadcrumb"><a href="/">Style-LORE</a> › <a href="/types/">Kibbe body types</a> › {html.escape(t["name"])}</nav>' + body
    open(f'{OUT}/{t["id"]}.html', 'w').write(page(title, desc, body, canonical, COLORS[t['id']], '<script type="application/ld+json">' + json.dumps(ld) + '</script>'))
    urls.append(canonical)

# index
tiles = ''.join(f'<a class="tile" href="/types/{t["id"]}.html" style="background:{COLORS[t["id"]]}"><img src="/img/kibbe/{t["id"]}-w.svg" width="360" height="440" alt="" loading="lazy"><b>{html.escape(t["name"])}</b><span>{html.escape(t["tag"])}</span></a>' for t in TYPES)
body = f'''
<div class="hero" style="background:var(--accent)"><div class="eyebrow">Guides</div><h1>The 13 Kibbe body types</h1><p>What each one looks like, what to wear, and what to skip — with three outfits to start from.</p></div>
<p>The Kibbe system reads your frame — bone structure, flesh and scale, and how much yin (curve) or yang (sharpness) runs through it — and groups the results into thirteen types across five families: Dramatic, Natural, Classic, Gamine and Romantic. Knowing yours tells you which silhouettes, fabrics and details will look like they were made for you, and which ones you'll keep returning to the store.</p>
<div class="grid">{tiles}</div>
<div class="card" style="border-color:var(--accent);margin-top:24px"><b>Find yours in two minutes</b><p style="margin:6px 0 4px">Style-LORE's quiz works out your type, your style words and your best colors, then checks your closet against them. Free, no account needed to start.</p><a class="cta" href="/">Take the free quiz</a></div>
'''
ld_index = {"@context": "https://schema.org", "@type": "CollectionPage", "name": "The 13 Kibbe body types", "url": SITE + "/types/",
  "description": "What each Kibbe body type looks like, what to wear, and what to skip.",
  "mainEntity": {"@type": "ItemList", "itemListElement": [{"@type": "ListItem", "position": i + 1, "name": t['name'], "url": f"{SITE}/types/{t['id']}.html"} for i, t in enumerate(TYPES)]}}
open(f'{OUT}/index.html', 'w').write(page("The 13 Kibbe Body Types — Guides, Outfits & a Free Quiz | Style-LORE", "Every Kibbe body type explained: Dramatic, Soft Dramatic, Flamboyant Natural, Natural, Soft Natural, Dramatic Classic, Classic, Soft Classic, Flamboyant Gamine, Gamine, Soft Gamine, Theatrical Romantic and Romantic.", body, f"{SITE}/types/", '#B8285A', '<script type="application/ld+json">' + json.dumps(ld_index) + '</script>'))
urls.insert(0, f"{SITE}/types/")

sm = '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + f'<url><loc>{SITE}/</loc><lastmod>{today}</lastmod><priority>1.0</priority></url>\n' + ''.join(f'<url><loc>{u}</loc><lastmod>{today}</lastmod><priority>0.8</priority></url>\n' for u in urls) + f'<url><loc>{SITE}/privacy.html</loc><lastmod>{today}</lastmod><priority>0.3</priority></url>\n<url><loc>{SITE}/terms.html</loc><lastmod>{today}</lastmod><priority>0.3</priority></url>\n' + '</urlset>\n'
open('/home/claude/lore-work/f/sitemap.xml', 'w').write(sm)
open('/home/claude/lore-work/f/robots.txt', 'w').write(f"User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /includes/\nDisallow: /uploads/\nDisallow: /beta-admin.html\nSitemap: {SITE}/sitemap.xml\n")
print('generated', len(urls), 'pages')
