# -*- coding: utf-8 -*-
"""Finish the drawn wardrobe: real garment shapes for every style in
   WEAR_SLOTS, and a Clothes tab in the figure editor to pick them.

   The data model (fig.wear) and the catalogue (WEAR_SLOTS) already
   existed; nothing drew them and nothing let a person choose one, so a
   figure with no closet photos came out undressed."""
import io, sys, os

P = sys.argv[1] if len(sys.argv) > 1 else 'index.html'
s = io.open(P, encoding='utf-8').read()
n0 = len(s)
hits = []

def rep(old, new, label):
    global s
    if old not in s:
        raise SystemExit('MISS: ' + label)
    if s.count(old) != 1:
        raise SystemExit('AMBIGUOUS (%d): %s' % (s.count(old), label))
    s = s.replace(old, new)
    hits.append(label)

BLOCK = io.open(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                             'wear_block.js'), encoding='utf-8').read()

# 1. the shapes, in front of the old single shoe shape
rep("function shoePath(g, side){", BLOCK + "\nfunction shoePath(g, side){", 'shapes')

# 2. draw them. A photographed piece still wins its slot.
rep("""  // --- clothes ------------------------------------------------------
  // Order is the order you put them on.
  const wide = Math.max(g.shoulderW, g.hipW) + halo + 8;
  const putOn = parts => { if(parts) parts.forEach(x => kids.push(x)); };
  if(pieces.dress){
    putOn(garment("dress", pieces.dress, dressPath(g),
      box(g.cx - wide, g.shoulderY - 6, g.cx + wide, g.kneeY)));
  } else {
    if(pieces.bottom) putOn(garment("bottom", pieces.bottom, bottomPath(g, true),
      box(g.cx - g.hipW - halo, g.waistY - 4, g.cx + g.hipW + halo, g.ankleY - 6)));
    if(pieces.top) putOn(garment("top", pieces.top, topPath(g, false),
      box(g.cx - g.shoulderW - halo, g.shoulderY - 6, g.cx + g.shoulderW + halo, g.waistY + 12)));
  }
  if(pieces.shoes){
    const sy = g.ankleY - 15, sy2 = g.ankleY + 10;
    putOn(garment("shoe-l", pieces.shoes, shoePath(g, "l"), box(g.cx - g.ankleW - 11, sy, g.cx, sy2)));
    putOn(garment("shoe-r", pieces.shoes, shoePath(g, "r"), box(g.cx, sy, g.cx + g.ankleW + 11, sy2)));
  }
  if(pieces.outerwear){
    const cw = g.hipW + halo + 7, cy2 = g.hipY + (g.kneeY - g.hipY) * .52;
    putOn(garment("coat-l", pieces.outerwear, coatPath(g, "l"), box(g.cx - cw, g.shoulderY - 6, g.cx, cy2)));
    putOn(garment("coat-r", pieces.outerwear, coatPath(g, "r"), box(g.cx, g.shoulderY - 6, g.cx + cw, cy2)));
  }
""", """  // --- clothes ------------------------------------------------------
  // Order is the order you put them on. Two wardrobes land here: pieces
  // are photographs of things the person owns, wear is the drawn
  // wardrobe they picked. A photograph always wins its slot -- a picture
  // of your own jacket beats a drawing of a jacket -- and the drawn one
  // fills whatever is left, so a figure is never naked by default.
  const wide = Math.max(g.shoulderW, g.hipW) + halo + 8;
  const putOn = parts => { if(parts) parts.forEach(x => kids.push(x)); };
  const drawn = (key, d, col) => {
    if(!d) return;
    kids.push(h("path", { key:key, d:d, fill:col,
      stroke:"rgba(40,34,30,.18)", strokeWidth:.8, strokeLinejoin:"round" }));
    kids.push(h("path", { key:key + "-sh", d:d, fill:clothShade,
      style:{ pointerEvents:"none" } }));
  };
  const wearDress = !pieces.dress && !pieces.top && !pieces.bottom && wearStyle("dress");
  const wearTop   = !pieces.dress && !pieces.top && !wearDress && wearStyle("top");
  const wearBot   = !pieces.dress && !pieces.bottom && !wearDress && wearStyle("bottom");
  const wearCoat  = !pieces.outerwear && wearStyle("outerwear");
  const wearShoe  = wearStyle("shoes");

  if(wearBot){
    const cut = BOTTOM_CUTS[wearBot] || BOTTOM_CUTS.jeans;
    drawn("w-bottom", wearBottomPath(g, cut), wearCol("bottom"));
  }
  if(wearDress){
    const cut = DRESS_CUTS[wearDress] || DRESS_CUTS.shift;
    if(cut.sleeve) ["l","r"].forEach(sd =>
      drawn("w-dsleeve-" + sd, sleevePath(g, sd, cut.sleeve, 0), wearCol("dress")));
    drawn("w-dress", wearDressPath(g, cut), wearCol("dress"));
  }
  if(wearTop){
    const cut = TOP_CUTS[wearTop] || TOP_CUTS.tee;
    if(cut.sleeve) ["l","r"].forEach(sd =>
      drawn("w-sleeve-" + sd, sleevePath(g, sd, cut.sleeve, cut.ease), wearCol("top")));
    drawn("w-top", wearTopPath(g, cut), wearCol("top"));
  }

  if(pieces.dress){
    putOn(garment("dress", pieces.dress, dressPath(g),
      box(g.cx - wide, g.shoulderY - 6, g.cx + wide, g.kneeY)));
  } else {
    if(pieces.bottom) putOn(garment("bottom", pieces.bottom, bottomPath(g, true),
      box(g.cx - g.hipW - halo, g.waistY - 4, g.cx + g.hipW + halo, g.ankleY - 6)));
    if(pieces.top) putOn(garment("top", pieces.top, topPath(g, false),
      box(g.cx - g.shoulderW - halo, g.shoulderY - 6, g.cx + g.shoulderW + halo, g.waistY + 12)));
  }

  // Shoes. The style the person picked decides the shape whether the
  // slot is filled by a photograph or by the drawn wardrobe.
  if(pieces.shoes || wearShoe){
    const cut = SHOE_CUTS[wearShoe] || SHOE_CUTS.flats;
    const sy = g.ankleY - 6 - (cut.shaft || 0), sy2 = g.ankleY + 12 + (cut.heel || 0);
    ["l","r"].forEach(sd => {
      const paths = wearShoePaths(g, sd, cut);
      const x0 = sd === "l" ? g.cx - g.ankleW - g.footL - 4 : g.cx - g.ankleW;
      const x1 = sd === "l" ? g.cx + g.ankleW : g.cx + g.ankleW + g.footL + 4;
      paths.forEach((d, i) => {
        if(pieces.shoes) putOn(garment("shoe-" + sd + (i ? "-" + i : ""), pieces.shoes, d,
                                       box(x0, sy, x1, sy2)));
        else drawn("w-shoe-" + sd + i, d, i && cut.heel > 2
                     ? mix(wearCol("shoes"), "#000000", .22) : wearCol("shoes"));
      });
    });
  }

  if(wearCoat){
    const cut = COAT_CUTS[wearCoat] || COAT_CUTS.blazer;
    if(cut.sleeve) ["l","r"].forEach(sd =>
      drawn("w-csleeve-" + sd, sleevePath(g, sd, cut.sleeve, cut.ease), wearCol("outerwear")));
    ["l","r"].forEach(sd => drawn("w-coat-" + sd, wearCoatPath(g, sd, cut), wearCol("outerwear")));
  }
  if(pieces.outerwear){
    const cw = g.hipW + halo + 7, cy2 = g.hipY + (g.kneeY - g.hipY) * .52;
    putOn(garment("coat-l", pieces.outerwear, coatPath(g, "l"), box(g.cx - cw, g.shoulderY - 6, g.cx, cy2)));
    putOn(garment("coat-r", pieces.outerwear, coatPath(g, "r"), box(g.cx, g.shoulderY - 6, g.cx + cw, cy2)));
  }
""", 'draw')

# 3. a shoe covers the foot. Drawing the bare foot under a closed shoe
#    is what left a wedge of skin poking out past every toe.
rep("""    const fx = g.cx + s * (g.ankleW * .55);
    kids.push(h("path", { key:"foot" + s,""",
"""    const fx = g.cx + s * (g.ankleW * .55);
    if(shoesHideFoot) return;
    kids.push(h("path", { key:"foot" + s,""", 'nofoot')

rep("""  const wearStyle = slot => (wear[slot] || {}).style || null;""",
"""  const wearStyle = slot => (wear[slot] || {}).style || null;
  /* A closed shoe covers the foot, so the foot is not drawn under it.
     An open one does not, which is the whole point of a sandal. */
  const shoeCut = SHOE_CUTS[wearStyle("shoes")] || (pieces.shoes ? SHOE_CUTS.flats : null);
  const shoesHideFoot = !!(shoeCut && !shoeCut.open);""", 'shoecut')

# 3b. a new figure arrives dressed. The catalogue and the data model
#     were already here; with nothing drawn and nothing chosen, every
#     figure that had not logged a closet photo was a naked person.
rep("""    body: Object.assign({}, FAMILY_BODY[fam] || FAMILY_BODY.classic),
    face: Object.assign({}, FACE_DEFAULTS, FAMILY_FACE[fam] || FAMILY_FACE.classic),
    wear: {},""",
"""    body: Object.assign({}, FAMILY_BODY[fam] || FAMILY_BODY.classic),
    face: Object.assign({}, FACE_DEFAULTS, FAMILY_FACE[fam] || FAMILY_FACE.classic),
    wear: {
      top:    { style:"tee",   color:"c7" },
      bottom: { style:"jeans", color:"c3" },
      shoes:  { style:"flats", color:"c1" },
    },""", 'dressed')

# 3c. the normaliser threw the default outfit away: it built `wear` from
#     scratch, so a figure that had never been edited came back naked.
#     An absent `wear` means "never dressed" and takes the default; an
#     empty one means "took everything off" and is honoured.
rep("""  const wear = {};
  if(raw.wear && typeof raw.wear === "object"){""",
"""  const wear = (raw.wear && typeof raw.wear === "object") ? {} : Object.assign({}, base.wear);
  if(raw.wear && typeof raw.wear === "object"){""", 'wearbase')

# 3d. Lorey is dressed on purpose rather than by default.
rep("""const LOREY_FIGURE = {
  skin: "s5", hair: "wavy", hairColor: "h1", eyes: "e2",
  nose: "n2", mouth: "m2", brow: "b2",""",
"""const LOREY_FIGURE = {
  skin: "s5", hair: "wavy", hairColor: "h1", eyes: "e2",
  nose: "n2", mouth: "m2", brow: "b2",
  wear: { bottom: { style:"trousers", color:"c1" }, shoes: { style:"heels", color:"c1" } },""", 'loreywear')

# 4. the editor: a Clothes tab
rep("""const FIGURE_TABS = [
  { id:"skin", name:"Skin" },
  { id:"hair", name:"Hair" },
  { id:"face", name:"Face" },
  { id:"body", name:"Body" },
];""", """const FIGURE_TABS = [
  { id:"skin", name:"Skin" },
  { id:"hair", name:"Hair" },
  { id:"face", name:"Face" },
  { id:"body", name:"Body" },
  { id:"wear", name:"Clothes" },
];

/* The slots the drawing can actually put on. The catalogue lists more
   than this; showing a person a choice that draws nothing is worse than
   not offering it. */
const WEAR_DRAWN = ["top", "bottom", "dress", "outerwear", "shoes"];""", 'tabs')

rep("""          tab === "body" ? e("div", { key:"body" },""",
"""          tab === "wear" ? e(WearTab, { key:"wear", fig:fig, pieces:pieces, onChange:onChange }) : null,

          tab === "body" ? e("div", { key:"body" },""", 'tabslot')

rep("""function FigureEditor({ figure, kibbeTypeId, palette, pieces, onChange, onSave, onClose, busy, error }){""",
"""/* The Clothes tab. One slot at a time: the list of styles for that
   slot, then the colour, then a way to take it off again. */
function WearTab({ fig, pieces, onChange }){
  const slots = WEAR_SLOTS.filter(x => WEAR_DRAWN.indexOf(x.id) >= 0);
  const [slot, setSlot] = useState(slots[0].id);
  const def = slots.find(x => x.id === slot) || slots[0];
  const wear = fig.wear || {};
  const on = wear[slot] || null;
  const put = patch => {
    const next = Object.assign({}, wear);
    if(patch === null) delete next[slot];
    else next[slot] = Object.assign({ color: GARMENT_COLORS[0].id }, next[slot], patch);
    onChange(Object.assign({}, fig, { wear: next }));
  };
  const shadowed = pieces && pieces[slot];
  return e("div", null,
    e("p", { className:"footnote", style:{ margin:"0 0 10px" } },
      "Dress the figure. Anything you photograph into your closet is worn instead."),
    e(Chips, { list:slots, value:slot, onPick:setSlot, label:"What to put on" }),
    shadowed ? e("p", { className:"footnote", style:{ margin:"8px 0 0" } },
      "You have a " + def.name.toLowerCase().replace(/s$/, "") + " in your closet, so that is what the figure wears.") : null,
    e("div", { className:"eyebrow fig-label" }, def.name),
    e(Chips, { list:def.styles, value:on && on.style, onPick:v => put({ style:v }), label:def.name }),
    on ? e("div", { className:"eyebrow fig-label" }, "Colour") : null,
    on ? e(Swatches, { list:GARMENT_COLORS, value:on.color,
                       onPick:v => put({ color:v }), label:"Colour" }) : null,
    on ? e("button", { type:"button", className:"btn-text", style:{ padding:0, marginTop:6 },
                       onClick:() => put(null) }, "Take it off") : null
  );
}

function FigureEditor({ figure, kibbeTypeId, palette, pieces, onChange, onSave, onClose, busy, error }){""", 'weartab')

io.open(P, 'w', encoding='utf-8').write(s)
print('%s %d -> %d (+%d)' % (P, n0, len(s), len(s) - n0))
print('applied:', ', '.join(hits))
