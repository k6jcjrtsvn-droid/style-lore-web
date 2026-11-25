/* ---- the drawn wardrobe: the shapes ---------------------------------
   One cut table per slot. A style is a row of numbers against a shared
   path rather than a drawing of its own, which is why there can be
   thirty of them: adding "culottes" is a line, not an illustration.
   -------------------------------------------------------------------- */
const TOP_CUTS = {
  tee:     { hem:.30, neck:1.05, sleeve:.22, ease:0   },
  blouse:  { hem:.40, neck:1.30, sleeve:.58, ease:1.3, drop:3 },
  sweater: { hem:.88, neck:1.12, sleeve:.94, ease:2.4 },
  tank:    { hem:.30, neck:2.00, sleeve:0,   ease:0, shoulder:.40, drop:6 },
  shirt:   { hem:1.02,neck:1.18, sleeve:.94, ease:1.7, collar:true },
  crop:    { hem:-.50,neck:1.06, sleeve:.20, ease:0   },
};
const BOTTOM_CUTS = {
  jeans:    { len:1.00, hem:1.05, ease:.4  },
  trousers: { len:1.00, hem:1.60, ease:1.7 },
  skirt:    { skirt:true, len:.52, flare:.44 },
  maxi:     { skirt:true, len:1.00, flare:.30 },
  shorts:   { len:.32, hem:1.30, ease:1.0 },
  leggings: { len:.98, hem:.95, ease:-.8  },
};
const DRESS_CUTS = {
  shift: { len:.50, flare:.12, neck:1.12, sleeve:.20 },
  wrap:  { len:.54, flare:.28, neck:1.70, sleeve:.42, drop:4 },
  aline: { len:.52, flare:.55, neck:1.16, sleeve:0, shoulder:.46, drop:3 },
  gown:  { len:1.00,flare:.42, neck:1.85, sleeve:0, shoulder:.38, drop:7 },
};
const COAT_CUTS = {
  blazer:   { len:.20, ease:2.2, sleeve:.92 },
  coat:     { len:.64, ease:3.4, sleeve:.95 },
  bomber:   { len:.06, ease:3.0, sleeve:.88 },
  cardigan: { len:.32, ease:2.6, sleeve:.92 },
  trench:   { len:.88, ease:3.6, sleeve:.95 },
};
/* Shoes. shaft is how far up the leg it climbs, heel the wedge under
   the back of the sole, toe how far past the ball it runs. */
const SHOE_CUTS = {
  flats:    { shaft:0,  heel:0,  toe:.60, sole:1.2 },
  heels:    { shaft:0,  heel:9,  toe:.70, sole:.7  },
  boots:    { shaft:30, heel:3,  toe:.56, sole:2.0 },
  sneakers: { shaft:7,  heel:1,  toe:.60, sole:2.8 },
  sandals:  { shaft:0,  heel:2,  toe:.72, sole:1.0, open:true },
  loafers:  { shaft:4,  heel:2,  toe:.58, sole:1.7 },
};

function wearTopPath(g, cut){
  const { cx, shoulderY, waistY, hipY, shoulderW, waistW, hipW, neckW } = g;
  const m = EASE + (cut.ease || 0);
  const ribY = shoulderY + (waistY - shoulderY) * .42;
  const ribW = (g.chestW == null ? waistW + (shoulderW - waistW) * .55 : g.chestW) + m;
  const t = Math.max(0, Math.min(1, cut.hem));
  const endY = waistY + (hipY - waistY) * cut.hem + 8;
  const endW = waistW + (hipW - waistW) * t + m + 2;
  const sh = (shoulderW + m) * (cut.shoulder == null ? 1 : cut.shoulder);
  const nk = neckW * (cut.neck == null ? 1.05 : cut.neck);
  const ny = shoulderY - 5 + (cut.drop || 0);
  return [
    "M", cx - nk, ny,
    "C", cx - sh * .62, shoulderY - 4, cx - sh, shoulderY, cx - sh, shoulderY + 7,
    "C", cx - ribW, ribY, cx - endW - 1, endY - 22, cx - endW, endY,
    "L", cx + endW, endY,
    "C", cx + endW + 1, endY - 22, cx + ribW, ribY, cx + sh, shoulderY + 7,
    "C", cx + sh, shoulderY, cx + sh * .62, shoulderY - 4, cx + nk, ny,
    "Q", cx, ny + (cut.neck > 1.5 ? 10 : 5), cx - nk, ny,
    "Z",
  ].join(" ");
}

/* A sleeve is the arm's own outline with ease on it, stopped at a
   fraction of the way to the wrist. Without one, every top on the
   figure was sleeveless whatever it was called. */
function sleevePath(g, side, frac, ease){
  const { cx, shoulderY, hipY, shoulderW, armW, armGap } = g;
  const s = side === "l" ? -1 : 1;
  const m = EASE + (ease || 0) * .5;
  const wristY = hipY + 16;
  const endY = shoulderY + (wristY - shoulderY) * frac + 2;
  const outerTop = cx + s * (shoulderW + armW * .55 + m);
  const innerTop = cx + s * (shoulderW - armW * .55);
  const outerEnd = cx + s * (shoulderW + armGap + armW * (1 - .18 * frac) + m * .8);
  const innerEnd = cx + s * (shoulderW + armGap - armW * .12 - m * .25);
  return [
    "M", innerTop, shoulderY + 1,
    "L", outerTop, shoulderY + 4,
    "C", outerTop + s * 1.2, shoulderY + 26, outerEnd, endY - 26, outerEnd, endY,
    "Q", (outerEnd + innerEnd) / 2, endY + 2.5, innerEnd, endY,
    "C", innerEnd, endY - 26, innerTop + s * 1.0, shoulderY + 28, innerTop, shoulderY + 1,
    "Z",
  ].join(" ");
}

function wearBottomPath(g, cut){
  const { cx, waistY, hipY, ankleY, waistW, hipW, thighW, ankleW } = g;
  const m = EASE + (cut.ease || 0);
  const topY = waistY - 2;
  const hemY = hipY + (ankleY - hipY) * cut.len;
  const wW = waistW + m, hW = hipW + m;
  if(cut.skirt){
    const hemW = hipW * (1 + (cut.flare || .3)) + m;
    return [
      "M", cx - wW, topY,
      "C", cx - hW, hipY - 18, cx - hW, hipY - 4, cx - hW, hipY + 2,
      "C", cx - hW - 1, hemY - 46, cx - hemW, hemY - 34, cx - hemW, hemY,
      "Q", cx, hemY + 6, cx + hemW, hemY,
      "C", cx + hemW, hemY - 34, cx + hW + 1, hemY - 46, cx + hW, hipY + 2,
      "C", cx + hW, hipY - 4, cx + hW, hipY - 18, cx + wW, topY,
      "Z",
    ].join(" ");
  }
  const legW = thighW + (ankleW - thighW) * Math.min(1, cut.len);
  const hemW = legW * (cut.hem || 1) + m;
  const crotchY = hipY + (hemY - hipY) * .16;
  return [
    "M", cx - wW, topY,
    "C", cx - hW, hipY - 18, cx - hW, hipY - 4, cx - hW, hipY + 2,
    "C", cx - hW + 1, hemY - 44, cx - hemW, hemY - 26, cx - hemW, hemY,
    "L", cx - hemW * .16, hemY,
    "L", cx - 1.5, crotchY,
    "L", cx + hemW * .16, hemY,
    "L", cx + hemW, hemY,
    "C", cx + hemW, hemY - 26, cx + hW - 1, hemY - 44, cx + hW, hipY + 2,
    "C", cx + hW, hipY - 4, cx + hW, hipY - 18, cx + wW, topY,
    "Z",
  ].join(" ");
}

function wearDressPath(g, cut){
  const { cx, shoulderY, waistY, hipY, ankleY, shoulderW, waistW, hipW, neckW } = g;
  const m = EASE;
  const ribY = shoulderY + (waistY - shoulderY) * .42;
  const ribW = (g.chestW == null ? waistW + (shoulderW - waistW) * .55 : g.chestW) + m;
  const hemY = hipY + (ankleY - hipY) * cut.len;
  const hemW = hipW * (1 + (cut.flare || .2)) + m;
  const sh = (shoulderW + m) * (cut.shoulder == null ? 1 : cut.shoulder);
  const nk = neckW * (cut.neck == null ? 1.1 : cut.neck);
  const ny = shoulderY - 5 + (cut.drop || 0);
  return [
    "M", cx - nk, ny,
    "C", cx - sh * .62, shoulderY - 4, cx - sh, shoulderY, cx - sh, shoulderY + 7,
    "C", cx - ribW, ribY, cx - waistW - m - 1, waistY - 14, cx - waistW - m, waistY,
    "C", cx - hipW - m, hipY - 4, cx - hemW, hemY - 52, cx - hemW, hemY,
    "Q", cx, hemY + 6, cx + hemW, hemY,
    "C", cx + hemW, hemY - 52, cx + hipW + m, hipY - 4, cx + waistW + m, waistY,
    "C", cx + waistW + m + 1, waistY - 14, cx + ribW, ribY, cx + sh, shoulderY + 7,
    "C", cx + sh, shoulderY, cx + sh * .62, shoulderY - 4, cx + nk, ny,
    "Q", cx, ny + (cut.neck > 1.5 ? 11 : 5), cx - nk, ny,
    "Z",
  ].join(" ");
}

function wearCoatPath(g, side, cut){
  const { cx, shoulderY, waistY, hipY, kneeY, shoulderW, waistW, hipW, neckW } = g;
  const s = side === "l" ? -1 : 1;
  const m = EASE + (cut.ease || 2.2);
  const hemY = hipY + (kneeY - hipY) * (cut.len == null ? .5 : cut.len);
  const sh = shoulderW + m;
  const ribY = shoulderY + (waistY - shoulderY) * .42;
  const ribW = (g.chestW == null ? waistW + (shoulderW - waistW) * .55 : g.chestW) + m;
  const hemOuter = cx + s * (hipW + m + 4);
  const lapelTop = cx + s * (neckW + 1);
  const lapelHem = cx + s * (hipW * .62);
  return [
    "M", lapelTop, shoulderY - 5,
    "C", cx + s * sh * .62, shoulderY - 4, cx + s * sh, shoulderY, cx + s * sh, shoulderY + 7,
    "C", cx + s * ribW, ribY, hemOuter, hipY, hemOuter, hemY,
    "L", lapelHem, hemY,
    "C", lapelHem, hipY, cx + s * (neckW + 6), waistY - 10, lapelTop, shoulderY - 5,
    "Z",
  ].join(" ");
}

/* A shoe, as a fashion plate draws one: a sole on the ground, the heel
   a wedge under the back of it, a shaft climbing the ankle if the style
   has one, and the foot inside rather than alongside. The back of the
   shoe stops short of the centre line, or the left and right ones meet
   in the middle and read as one lump. */
function wearShoePaths(g, side, cut){
  const { cx, ankleY, ankleW, footL } = g;
  const s = side === "l" ? -1 : 1;
  const sole = cut.sole == null ? 1.4 : cut.sole;
  const heel = cut.heel || 0;
  const shaft = cut.shaft || 0;
  const groundY = ankleY + 7 + sole;
  const topY = ankleY - 4 - shaft;
  const heelBackX = cx + s * (ankleW * .02);
  const backX = cx - s * (ankleW * (shaft ? .26 : .10));
  const openX = cx + s * (ankleW * (shaft ? 1.00 : 1.12));
  const toeX = cx + s * (ankleW * .55 + footL * (cut.toe == null ? .80 : cut.toe));
  const out = [];
  if(cut.open){
    out.push([
      "M", heelBackX - s * 1.5, ankleY + 5, "L", toeX, ankleY + 5,
      "Q", toeX + s * 2.2, groundY - 1, toeX - s * 3.5, groundY,
      "L", heelBackX - s * 1.5, groundY, "Z",
    ].join(" "));
    out.push([
      "M", cx + s * (ankleW * .16), ankleY + 5,
      "L", cx + s * (ankleW * .60), ankleY - 5,
      "L", cx + s * (ankleW * 1.05), ankleY - 5,
      "L", cx + s * (ankleW * .92), ankleY + 5, "Z",
    ].join(" "));
    return out;
  }
  // The shaft drops straight before the shoe runs forward to the toe.
  // Taking the outer edge from the collar to the toe in one curve is
  // what made every boot come out shaped like a bell.
  out.push([
    "M", backX, topY,
    "Q", cx + s * (ankleW * .40), topY - 1.4, openX, topY,
    "L", openX + s * .6, ankleY + 1,
    "C", openX + s * 1.8, ankleY + 3.5, toeX - s * 6, ankleY + 3.5, toeX, ankleY + 6,
    "C", toeX + s * 2.2, ankleY + 8, toeX + s * 1.0, groundY, toeX - s * 4, groundY,
    "L", heelBackX - s * 1.5, groundY,
    "C", heelBackX - s * 1.5, ankleY + 3, backX, ankleY, backX, topY,
    "Z",
  ].join(" "));
  if(heel > 2){
    out.push([
      "M", heelBackX - s * 2, groundY - .6,
      "L", cx + s * (ankleW * .26), groundY - .6,
      "L", cx + s * (ankleW * .16), groundY + heel,
      "L", heelBackX - s * 2.6, groundY + heel, "Z",
    ].join(" "));
  }
  return out;
}
