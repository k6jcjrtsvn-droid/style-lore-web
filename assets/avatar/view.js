/* The 3D figure, mounted on a canvas.

   Everything heavy lives behind this module: three.js, the base bundle
   and the skin maps are about 3.7 MB together, and none of it is fetched
   until someone actually opens the 3D view. The app's own 2D figure is
   untouched and is still what the feed, the profile and Lorey use. */
import * as THREE from '../three.module.js';
import { loadBase, applyMix, groupCentre, styliseHead, shapeFor, sliderMix, MACRO_NEUTRAL }
  from './avatar.js';
import { makeBody, armsDown, bodyNormals, refine, obscurance } from './body.js';
import { top as mkTop, bottoms as mkBottom, skirt as mkSkirt, shoes as mkShoes }
  from './wardrobe.js';
import { scalp, buildHair, buildBrows, buildLashes } from './hairgen.js';
import { studioEnv } from './env.js';

/* The app's catalogue is not the pipeline's catalogue -- the drawn
   wardrobe has styles the mesh has no pattern for, and the other way
   round. These are the joins, in one place, so a style that has no 3D
   equivalent falls back to its nearest neighbour rather than vanishing. */
const HAIR = { short:'short', crop:'crop', bob:'bob', long:'long', curls:'curls',
               coils:'curls', updo:'crop', braids:'long', wavy:'wavy', bald:null };
const TOP  = { tee:'tee', blouse:'long-sleeve', sweater:'long-sleeve', tank:'tank',
               shirt:'long-sleeve', crop:'crop' };
const BOTTOM = { jeans:'leggings', trousers:'trousers', shorts:'shorts',
                 leggings:'leggings', capri:'capri' };
const SKIRT  = { skirt:'a-line', maxi:'maxi' };
const SHOE = { flats:'flat', heels:'pump', boots:'ankle-boot', sneakers:'sneaker',
               sandals:'sandal', loafers:'flat' };
const SKIN = { s1:[.97,.87,.80], s2:[.95,.80,.67], s3:[.90,.72,.56], s4:[.82,.60,.44],
               s5:[.66,.45,.32], s6:[.50,.33,.23], s7:[.36,.23,.16], s8:[.25,.16,.11] };
const HAIRC = { h1:0x1b1109, h2:0x2e1a0d, h3:0x5c3a1e, h4:0x7f5830, h5:0xa8834a,
                h6:0xc9a96c, h7:0x6e2310, h8:0x3a3a44, h9:0x9a9a9a, h10:0xd8d2c8,
                h11:0x8c4a7a, h12:0x3f5f8c };
const GARMC = { c1:0x2A2622, c2:0x4A4F5C, c3:0x3E5C76, c4:0x6E8CA0, c5:0x7D9D7A,
                c6:0xC2B49A, c7:0xE8DED1, c8:0xFFFFFF, c9:0xB9867A, c10:0xA14B5B,
                c11:0x8C5A2B, c12:0xD4A24C, c13:0x6B4C8C, c14:0x2F6B6B };

/* An eye is a sphere with the whole eye painted on it -- sclera, limbal
   ring, iris fibres and pupil -- plus a thin clear shell in front to
   catch the highlight. A catchlight is most of what makes a face look
   alive, and a face with none looks dead at any resolution. */
function eyeTex(THREE, hex){
  const N = 512, c = document.createElement('canvas'); c.width = c.height = N;
  const g = c.getContext('2d');
  const col = new THREE.Color(hex);
  const hx = '#' + col.getHexString();
  const dark = '#' + col.clone().multiplyScalar(.30).getHexString();
  const lit  = '#' + col.clone().lerp(new THREE.Color(0xffffff), .50).getHexString();

  /* The sclera is not white. It is a warm grey that goes pink into the
     corners, and it has vessels in it. A pure white eyeball is the
     single loudest tell in a rendered face -- it reads as a bead. */
  g.fillStyle = '#ded6cd'; g.fillRect(0, 0, N, N);
  const warm = g.createLinearGradient(0, 0, N, 0);
  warm.addColorStop(0,  'rgba(188,126,116,.62)');
  warm.addColorStop(.5, 'rgba(255,255,255,0)');
  warm.addColorStop(1,  'rgba(188,126,116,.62)');
  g.fillStyle = warm; g.fillRect(0, 0, N, N);
  const cx = N/2, cy = N/2, IR = N*0.175;
  let seed = 0x9e3779b9;
  const rnd = () => {
    seed = (Math.imul(seed ^ (seed >>> 15), 2246822507) ^ 0x165667b1) >>> 0;
    return seed / 4294967296;
  };
  g.lineCap = 'round';
  for(let i = 0; i < 46; i++){                       // vessels
    const side = i % 2 ? 1 : -1;
    let a2 = cx + side * (IR * (1.5 + rnd() * 2.4));
    let b2 = cy + (rnd() - .5) * N * .22;
    g.strokeStyle = 'rgba(176,70,62,' + (.10 + rnd() * .20).toFixed(3) + ')';
    g.lineWidth = .6 + rnd() * 1.1;
    g.beginPath(); g.moveTo(a2, b2);
    let ang = side > 0 ? Math.PI : 0;
    for(let k = 0; k < 7; k++){
      ang += (rnd() - .5) * .9;
      a2 += Math.cos(ang) * (5 + rnd() * 11);
      b2 += Math.sin(ang) * (5 + rnd() * 11);
      g.lineTo(a2, b2);
    }
    g.stroke();
  }

  /* The iris, built from the back forwards: the dark pigment ring at the
     rim, the stroma, the collarette, then the fibres over all of it. */
  const rg = g.createRadialGradient(cx, cy, IR*.20, cx, cy, IR);
  rg.addColorStop(0,   dark);
  rg.addColorStop(.34, hx);
  rg.addColorStop(.62, lit);
  rg.addColorStop(.88, hx);
  rg.addColorStop(1,   dark);
  g.fillStyle = rg; g.beginPath(); g.arc(cx, cy, IR, 0, 7); g.fill();

  for(let i = 0; i < 340; i++){                      // fibres
    const a2 = i / 340 * Math.PI * 2 + rnd() * .05;
    const r0 = IR * (.30 + rnd() * .10), r1 = IR * (.62 + rnd() * .34);
    g.strokeStyle = rnd() < .5
      ? 'rgba(0,0,0,' + (.10 + rnd() * .26).toFixed(3) + ')'
      : 'rgba(255,255,255,' + (.05 + rnd() * .16).toFixed(3) + ')';
    g.lineWidth = .5 + rnd() * 1.0;
    g.beginPath();
    g.moveTo(cx + Math.cos(a2)*r0, cy + Math.sin(a2)*r0);
    const mid = (r0 + r1) * .5, wob = (rnd() - .5) * .10;
    g.quadraticCurveTo(cx + Math.cos(a2 + wob)*mid, cy + Math.sin(a2 + wob)*mid,
                       cx + Math.cos(a2)*r1,        cy + Math.sin(a2)*r1);
    g.stroke();
  }
  // the collarette: the ruff where the pupil's muscle meets the stroma
  g.strokeStyle = 'rgba(255,255,255,.13)'; g.lineWidth = IR*.055;
  g.beginPath(); g.arc(cx, cy, IR*.42, 0, 7); g.stroke();
  // and the limbal ring, which is what makes an iris sit IN an eye
  g.lineWidth = IR*.16; g.strokeStyle = 'rgba(6,4,10,.85)';
  g.beginPath(); g.arc(cx, cy, IR*.945, 0, 7); g.stroke();
  g.fillStyle = '#05030a';
  g.beginPath(); g.arc(cx, cy, IR*.27, 0, 7); g.fill();

  /* The shadow the upper lid throws on the ball. The eye never rotates,
     so this can be baked: without it the sclera is as bright at the top,
     under the lid, as it is in the open, and the ball reads as sitting
     on the face rather than in it. */
  const lid = g.createLinearGradient(0, 0, 0, N*0.52);
  lid.addColorStop(0,   'rgba(28,20,24,.80)');
  lid.addColorStop(.55, 'rgba(32,24,28,.42)');
  lid.addColorStop(1,   'rgba(40,30,34,0)');
  g.fillStyle = lid; g.fillRect(0, 0, N, N*0.52);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  t.anisotropy = 8;
  return t;
}

/* The cornea is a lens, and that is most of what an eye looks like.

   The iris sits about three millimetres behind it in fluid that bends
   light, so it is magnified, and it SHIFTS as the head turns -- when you
   look at someone from the side you are not looking at their iris
   straight on, you are looking at it through a prism. A flat painted
   iris on a sphere has none of that: it turns rigidly with the ball and
   reads as a printed decal, which is exactly what the eyes looked like.

   The offset is done in the surface's own tangent frame, derived from
   the screen derivatives, and it is faded out away from the iris so the
   sclera and its vessels do not slide about with it. */
function withIrisParallax(THREE, m, depth){
  m.onBeforeCompile = sh => {
    sh.uniforms.irisDepth = { value: depth };
    sh.fragmentShader = sh.fragmentShader
      .replace('#include <common>', '#include <common>\nuniform float irisDepth;')
      .replace('#include <map_fragment>', [
        '{',
        '  vec3 V = normalize( vViewPosition );',
        '  vec3 Nn = normalize( vNormal );',
        '  vec3 dpx = dFdx( -vViewPosition ), dpy = dFdy( -vViewPosition );',
        '  vec2 dux = dFdx( vMapUv ),        duy = dFdy( vMapUv );',
        '  vec3 Tn = normalize( dpx * duy.y - dpy * dux.y );',
        '  vec3 Bn = normalize( -dpx * duy.x + dpy * dux.x );',
        '  vec2 vt = vec2( dot( V, Tn ), dot( V, Bn ) );',
        // how far into the iris we are: 0 at the limbus, 1 at the pupil
        '  float rr = length( vMapUv - vec2( 0.5 ) );',
        '  float inIris = 1.0 - smoothstep( 0.14, 0.20, rr );',
        '  vec2 uvp = vMapUv - vt * irisDepth * inIris;',
        '  vec4 sampledDiffuseColor = texture2D( map, uvp );',
        '  diffuseColor *= sampledDiffuseColor;',
        '}',
      ].join('\n'));
  };
  m.customProgramCacheKey = () => 'iris' + depth;
  return m;
}

/* Fine detail, generated rather than downloaded.

   The baked skin maps carry the shape of a face -- the socket, the lash
   line, the lip. What they cannot carry is pores: at 1024 across a whole
   body, one texel is about two millimetres of skin, so the pore is
   smaller than the texel that would have to hold it. Raising the bake to
   2048 would quadruple a map that is already the largest file the figure
   downloads, and still only halve the texel.

   So the fine detail is a small tiling map, built on a canvas at load
   time, repeated across the body and added to the baked normal. It costs
   no download at all, it is the same on every device because the
   generator is explicit, and because it tiles it has no resolution: the
   closer the camera gets, the more of it there is to see. */
export function detailNormal(THREE, kind, seed){
  const N = 256;
  const h = new Float32Array(N * N);
  let s0 = ((seed | 0) * 2654435761 + 1) >>> 0 || 1;
  const rnd = () => {
    let x = s0;
    x = (x ^ (x << 13)) >>> 0; x = (x ^ (x >>> 17)) >>> 0; x = (x ^ (x << 5)) >>> 0;
    s0 = x; return x / 4294967296;
  };
  const wrap = i => (i % N + N) % N;
  const dent = (cx, cy, r, depth) => {
    const r2 = r * r;
    for(let y = Math.floor(cy - r); y <= cy + r; y++){
      for(let x = Math.floor(cx - r); x <= cx + r; x++){
        const dx = x - cx, dy = y - cy, d2 = dx*dx + dy*dy;
        if(d2 > r2) continue;
        const f = 1 - d2 / r2;
        h[wrap(y) * N + wrap(x)] += depth * f * f;
      }
    }
  };
  if(kind === 'skin'){
    // pores: many small ones, a few larger, none of them round enough to
    // tile into a pattern the eye can lock on to
    for(let i = 0; i < 2600; i++)
      dent(rnd()*N, rnd()*N, 1.1 + rnd()*2.2, -(0.35 + rnd()*0.65));
    for(let i = 0; i < 260; i++)
      dent(rnd()*N, rnd()*N, 2.6 + rnd()*3.0, -(0.25 + rnd()*0.45));
    // and the faint creases between them, which is what stops skin
    // reading as orange peel
    for(let i = 0; i < 420; i++){
      let x = rnd()*N, y = rnd()*N;
      const a = rnd() * Math.PI * 2, len = 6 + rnd()*22, d = .18 + rnd()*.3;
      for(let t = 0; t < len; t++){
        x += Math.cos(a) + (rnd()-.5)*.6; y += Math.sin(a) + (rnd()-.5)*.6;
        dent(x, y, 0.9 + rnd()*0.6, -d);
      }
    }
  } else {
    // a weave: two sets of threads crossing, the over-under of them is
    // what makes cloth read as cloth instead of as painted plastic
    const pitch = 8;
    for(let y = 0; y < N; y++) for(let x = 0; x < N; x++){
      const u = (x % pitch) / pitch, v = (y % pitch) / pitch;
      const over = ((x / pitch | 0) + (y / pitch | 0)) % 2 === 0;
      const a = Math.sin(u * Math.PI), b = Math.sin(v * Math.PI);
      h[y*N+x] = (over ? a*a*1.0 + b*b*0.35 : b*b*1.0 + a*a*0.35) - 0.6;
    }
    for(let i = 0; i < 1400; i++) dent(rnd()*N, rnd()*N, 1 + rnd()*1.6, -(0.1 + rnd()*0.3));
  }
  const c = document.createElement('canvas'); c.width = c.height = N;
  const g = c.getContext('2d');
  const img = g.createImageData(N, N);
  const S = kind === 'skin' ? 2.2 : 1.5;
  for(let y = 0; y < N; y++) for(let x = 0; x < N; x++){
    const gx = (h[y*N + wrap(x+1)] - h[y*N + wrap(x-1)]) * S;
    const gy = (h[wrap(y+1)*N + x] - h[wrap(y-1)*N + x]) * S;
    const L = Math.hypot(gx, gy, 1);
    const o = (y*N + x) * 4;
    img.data[o]   = Math.round((-gx/L * .5 + .5) * 255);
    img.data[o+1] = Math.round(( gy/L * .5 + .5) * 255);
    img.data[o+2] = Math.round(( 1/L  * .5 + .5) * 255);
    img.data[o+3] = 255;
  }
  g.putImageData(img, 0, 0);
  const t = new THREE.CanvasTexture(c);
  t.wrapS = t.wrapT = THREE.RepeatWrapping;
  t.colorSpace = THREE.NoColorSpace;
  t.anisotropy = 8;
  return t;
}

/* Add a tiling detail normal on top of whatever normal map a material
   already has, by rewriting the one chunk that consumes it. */
export function withDetail(THREE, m, map, repeat, scale){
  const prev = m.onBeforeCompile;
  m.onBeforeCompile = sh => {
    if(prev) prev(sh);
    sh.uniforms.detailMap = { value: map };
    sh.uniforms.detailRepeat = { value: repeat };
    sh.uniforms.detailScale = { value: scale };
    sh.fragmentShader = sh.fragmentShader
      .replace('#include <common>', '#include <common>\n' +
        'uniform sampler2D detailMap; uniform float detailRepeat; uniform float detailScale;')
      .replace(
        'vec3 mapN = texture2D( normalMap, vNormalMapUv ).xyz * 2.0 - 1.0;\n\tmapN.xy *= normalScale;',
        'vec3 mapN = texture2D( normalMap, vNormalMapUv ).xyz * 2.0 - 1.0;\n' +
        '\tmapN.xy *= normalScale;\n' +
        '\tvec3 dN = texture2D( detailMap, vNormalMapUv * detailRepeat ).xyz * 2.0 - 1.0;\n' +
        '\tmapN.xy += dN.xy * detailScale;');
  };
  m.customProgramCacheKey = () => 'detail' + repeat + '_' + scale;
  return m;
}

/* ------------------------------------------------- pre-integrated skin

   Skin is not opaque. Light goes into it, bounces around inside and
   comes back out somewhere else, redder than it went in, because the
   blood and the dermis eat the blue first. Every surface shader without
   that reads as painted clay no matter how good its texture is, and that
   -- not the resolution of anything -- is what has been making this face
   look like a model of a face.

   Doing it properly means blurring irradiance across the surface, which
   on a phone is out of the question. Penner's pre-integrated skin gets
   most of it for the price of one texture lookup: the scattering over a
   sphere depends only on how the surface is lit and how sharply it is
   curved, so both can be integrated ahead of time into a small table.
   Flat skin lit head-on looks ordinary; skin that curves away from the
   light -- the edge of a nostril, the rim of an ear, the top of a lip --
   glows red into its own shadow, which is exactly where a person can
   tell flesh from plastic.

   The table is computed here rather than downloaded, from the six-
   Gaussian diffusion profile that is the standard fit to measured
   Caucasian dermis. It is 128x64 and costs nothing. */
const SKIN_PROFILE = [
  [0.0064, 0.233, 0.455, 0.649], [0.0484, 0.100, 0.336, 0.344],
  [0.1870, 0.118, 0.198, 0.000], [0.5670, 0.113, 0.007, 0.007],
  [1.9900, 0.358, 0.004, 0.000], [7.4100, 0.078, 0.000, 0.000],
];
const MM_PER_UNIT = 106;          // the figure is ~16 units for ~1.7 m
const MAX_CURV = 0.25;            // 1/mm, i.e. a radius of 4 mm

export function skinLUT(THREE){
  const W = 128, H = 64;
  const c = document.createElement('canvas'); c.width = W; c.height = H;
  const g = c.getContext('2d');
  const img = g.createImageData(W, H);
  const prof = (d, out) => {
    out[0] = out[1] = out[2] = 0;
    for(const [v, r, gg, b] of SKIN_PROFILE){
      const e = Math.exp(-(d*d) / (2*v)) / (2*Math.PI*v);
      out[0] += r*e; out[1] += gg*e; out[2] += b*e;
    }
  };
  const w = [0,0,0], lightAcc = [0,0,0], wAcc = [0,0,0];
  for(let y = 0; y < H; y++){
    const curv = (y + .5) / H * MAX_CURV;
    const R = curv > 1e-5 ? 1 / curv : 1e5;         // mm
    for(let x = 0; x < W; x++){
      const cosT = (x + .5) / W * 2 - 1;
      const theta = Math.acos(Math.max(-1, Math.min(1, cosT)));
      lightAcc[0] = lightAcc[1] = lightAcc[2] = 0;
      wAcc[0] = wAcc[1] = wAcc[2] = 0;
      for(let a = -Math.PI/2; a <= Math.PI/2; a += 0.0314){
        const diffuse = Math.max(0, Math.cos(theta + a));
        prof(Math.abs(2 * R * Math.sin(a * .5)), w);
        for(let k = 0; k < 3; k++){ wAcc[k] += w[k]; lightAcc[k] += diffuse * w[k]; }
      }
      const o = (y*W + x) * 4;
      for(let k = 0; k < 3; k++)
        img.data[o+k] = Math.round(255 * Math.min(1, wAcc[k] > 0 ? lightAcc[k]/wAcc[k] : 0));
      img.data[o+3] = 255;
    }
  }
  g.putImageData(img, 0, 0);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.NoColorSpace;
  t.wrapS = t.wrapT = THREE.ClampToEdgeWrapping;
  t.minFilter = t.magFilter = THREE.LinearFilter;
  t.generateMipmaps = false;
  return t;
}

/* Rewire a material's DIRECT diffuse to read the table instead of
   Lambert. The light loop lives in a chunk that has not been included
   yet when onBeforeCompile runs, so the chunk is expanded here, edited,
   and substituted for its own include -- which keeps the change on this
   material instead of on every physical material in the scene. */
export function withSkinScatter(THREE, m, lut){
  const prev = m.onBeforeCompile;
  m.onBeforeCompile = sh => {
    if(prev) prev(sh);
    sh.uniforms.skinLUT = { value: lut };
    sh.uniforms.skinScatter = { value: 1.0 };
    sh.fragmentShader = sh.fragmentShader
      .replace('#include <common>', '#include <common>\n' +
        'uniform sampler2D skinLUT; uniform float skinScatter; float skinCurv;')
      /* Curvature from the GEOMETRIC normal, before the normal maps are
         applied. Taken after them, every pore counts as a fold and the
         whole face sits at maximum curvature. */
      .replace('#include <normal_fragment_begin>', '#include <normal_fragment_begin>\n' +
        '{ vec3 gn = normal;\n' +
        '  float k = length(fwidth(gn)) / max(length(fwidth(vViewPosition)), 1e-5);\n' +
        '  skinCurv = clamp(k / ' + MM_PER_UNIT.toFixed(1) + ' / ' + MAX_CURV.toFixed(3) + ', 0.0, 1.0); }');
    const chunk = THREE.ShaderChunk.lights_physical_pars_fragment.replace(
      'reflectedLight.directDiffuse += irradiance * BRDF_Lambert( material.diffuseColor );',
      'vec3 sss = texture2D( skinLUT, vec2( dot( geometryNormal, directLight.direction ) * 0.5 + 0.5, skinCurv ) ).rgb;\n' +
      '\treflectedLight.directDiffuse += directLight.color * mix( vec3( dotNL ), sss, skinScatter ) * BRDF_Lambert( material.diffuseColor );');
    sh.fragmentShader = sh.fragmentShader
      .replace('#include <lights_physical_pars_fragment>', chunk);
  };
  const key = m.customProgramCacheKey;
  m.customProgramCacheKey = () => 'sss|' + (key ? key.call(m) : '');
  return m;
}

/* ------------------------------------------------------- hair, backlit

   Hair is not opaque. A strand is a translucent tube, and light coming
   from behind a head goes THROUGH the hair and arrives at the eye as a
   warm halo round the silhouette -- which is most of what makes hair in
   a photograph look like hair and not like a dark shape. A surface
   shader gives none of it: light from behind means the normal faces
   away, which means nothing at all, so the rim light that is pointed at
   the back of this head has been doing nothing but outline it.

   The real thing is a three-lobe scattering model. This is the lobe
   that matters: the light transmitted straight through, which peaks when
   the viewer is looking back along the beam. It costs one dot product
   and one power, and it is what the rim light was for. */
export function withTransmit(THREE, m, tint, power, strength){
  const prev = m.onBeforeCompile;
  m.onBeforeCompile = sh => {
    if(prev) prev(sh);
    sh.uniforms.ttTint = { value: new THREE.Color(tint) };
    sh.uniforms.ttPower = { value: power };
    sh.uniforms.ttStrength = { value: strength };
    sh.fragmentShader = sh.fragmentShader.replace('#include <common>',
      '#include <common>\nuniform vec3 ttTint; uniform float ttPower; uniform float ttStrength;');
    const chunk = THREE.ShaderChunk.lights_physical_pars_fragment.replace(
      'reflectedLight.directDiffuse += irradiance * BRDF_Lambert( material.diffuseColor );',
      'reflectedLight.directDiffuse += irradiance * BRDF_Lambert( material.diffuseColor );\n' +
      '\t{ float tt = max( dot( geometryViewDir, -directLight.direction ), 0.0 );\n' +
      '\t  reflectedLight.directDiffuse += directLight.color * ttTint * ttStrength\n' +
      '\t                                * pow( tt, ttPower ) * material.diffuseColor; }');
    sh.fragmentShader = sh.fragmentShader
      .replace('#include <lights_physical_pars_fragment>', chunk);
  };
  const key = m.customProgramCacheKey;
  m.customProgramCacheKey = () => 'tt' + power + '_' + strength + '|' + (key ? key.call(m) : '');
  return m;
}

/* The eye, built once and shared by the view and by anything that
   needs to render the same eye -- a harness included, so what gets
   looked at is what ships. */
export function makeEye(THREE){
  const EYE = { tex: null, hex: null };
  return function eyeball(centre, radius, hex){
    if(EYE.hex !== hex){
      if(EYE.tex) EYE.tex.dispose();
      EYE.tex = eyeTex(THREE, hex); EYE.hex = hex;
    }
    const grp = new THREE.Group();
    const R = radius * 0.96;
    const ball = new THREE.Mesh(new THREE.SphereGeometry(R, 64, 44),
      withIrisParallax(THREE, new THREE.MeshPhysicalMaterial({
        map:EYE.tex, roughness:.26, clearcoat:.9, clearcoatRoughness:.08,
        envMapIntensity:.55 }), .030));
    ball.rotation.y = -Math.PI / 2;   // (u .5, v .5) sits on +x
    /* An eyeball that takes no shadow sits in its socket like a bead
       glued on: the lid above it is the one thing that should be
       darkening it, and the lashes with it. */
    ball.receiveShadow = true;
    grp.add(ball);
    /* A full shell, front faces only: the near hemisphere is all that
       draws, so there is no second highlight on the far side to worry
       about -- and a cap cut with phiStart lands on whichever side of
       the ball the sphere's seam happens to be, which on this one was
       the back, and the eyes had no catchlight at all. */
    const cornea = new THREE.Mesh(
      new THREE.SphereGeometry(R*1.035, 40, 28),
      /* The tear film, added rather than blended.
         Two things were wrong. Roughness .012 puts the catchlight in a
         spot smaller than a pixel, so at any sane resolution the eye has
         no highlight at all -- and an eye with no catchlight is a dead
         eye. And an alpha-blended shell at opacity .16 scales its OWN
         highlight to sixteen percent along with everything else, so even
         when the highlight was wide enough to see it was not bright
         enough. Black and additive instead: the diffuse contributes
         nothing, the specular and the environment add at full strength,
         which is what a film of water on an eye actually does. */
      new THREE.MeshPhysicalMaterial({ color:0x000000, roughness:.035, metalness:0,
        reflectivity:1, clearcoat:1, clearcoatRoughness:.02,
        transparent:true, opacity:1, blending:THREE.AdditiveBlending,
        envMapIntensity:1.2,
        depthWrite:false, side:THREE.FrontSide }));
    grp.add(cornea);
    grp.position.set(centre[0], centre[1], centre[2] - radius*0.26);
    return grp;
  };
}

export async function mount(canvas, opts){
  const o = opts || {};
  const W = o.width || 360, H = o.height || 560;
  const r = new THREE.WebGLRenderer({ canvas, antialias:true, alpha:false });
  // Render above the display resolution and let the browser downscale.
  // Alpha-tested hair cards alias badly at 1:1 and a figure seen close up
  // is mostly edges; this is the cheapest sharpness there is.
  const SS = o.supersample == null ? 1.9 : o.supersample;
  r.setPixelRatio(Math.min((window.devicePixelRatio || 1) * SS, 3.2));
  r.setSize(W, H, false);
  r.outputColorSpace = THREE.SRGBColorSpace;
  r.toneMapping = THREE.ACESFilmicToneMapping;
  r.toneMappingExposure = .66;
  r.shadowMap.enabled = true;
  r.shadowMap.type = THREE.PCFSoftShadowMap;

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(o.background == null ? 0x191c22 : o.background);
  scene.environment = studioEnv(THREE, r);
  const cam = new THREE.PerspectiveCamera(22, W/H, .1, 400);

  const tl = new THREE.TextureLoader();
  const base = o.assets || './assets/avatar/';
  /* Awaited, not fired and forgotten. TextureLoader is asynchronous and
     the figure is rendered once per change: starting a render before the
     maps arrive samples an empty texture, and the face comes out with no
     eyebrows, no lip and no shading in the socket. It looks like a
     shader bug and is a race. */
  const tex = (f, srgb, o2) => new Promise((res, rej) => {
    tl.load(base + f, t => {
      t.colorSpace = srgb ? THREE.SRGBColorSpace : THREE.NoColorSpace;
      t.flipY = (o2 && o2.flipY) || false; t.anisotropy = 8;
      if(o2 && o2.crisp){
        // Fine strands are two texels wide. One mip level averages them
        // into the gaps, the averaged alpha falls under alphaTest, and
        // every card loses its strands and renders as the solid core --
        // a rectangle. The cards are drawn once per change, not per
        // frame, so there is no shimmer to trade away by not mipping.
        t.generateMipmaps = false;
        t.minFilter = THREE.LinearFilter; t.magFilter = THREE.LinearFilter;
      }
      res(t);
    }, undefined, rej);
  });
  const [mask, warm, normal, rough, STRAND, B] = await Promise.all([
    tex('skin_mask.png'), tex('skin_warm.png'), tex('skin_normal.png'),
    /* flipY, because geom() flips V for the skin atlas and the hair
       texture goes through the same path: without this the cards are
       drawn upside down -- soft tapered roots and blunt square tips,
       which is the opposite of hair and was most of why the first pass
       looked like strips of tape. */
    tex('skin_rough.png'), tex('hair_strand.png', true, { flipY:true, crisp:true }),
    loadBase(o.bundle || './assets/avatar-base-v1.bin'),
  ]);
  const SK = { mask, warm, normal, rough };
  const PORE = detailNormal(THREE, 'skin', 5);
  const SSS  = skinLUT(THREE);
  const FABRIC = detailNormal(THREE, 'cloth', 9);
  FABRIC.repeat.set(115, 115);

  let group = null, yaw = .30, pitch = 0, raf = 0, alive = true;
  let refSpan = 0;
  let only = null;   // isolation: draw one part on its own
  const eyeball = makeEye(THREE);

  const geom = d => {
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(d.position, 3));
    if(d.normal) g.setAttribute('normal', new THREE.Float32BufferAttribute(d.normal, 3));
    if(d.color)  g.setAttribute('color', new THREE.Float32BufferAttribute(d.color, 3));
    if(d.uv){
      // The textures are loaded flipY:false, so V has to be flipped here
      // instead. Without this the skin maps sample the wrong part of the
      // atlas and the face comes out with no eyebrows at all -- which is
      // subtle enough at thumbnail size to ship unnoticed.
      const uv = new Float32Array(d.uv.length);
      for(let i = 0; i < d.uv.length; i += 2){ uv[i] = d.uv[i]; uv[i+1] = 1 - d.uv[i+1]; }
      g.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
    }
    g.setIndex(Array.from(d.index));
    // Anisotropy needs a tangent frame. Without the attribute three.js
    // derives one from the UV derivatives per triangle, which is
    // discontinuous across every edge -- hair rendered as a woven basket.
    if(d.uv){ try{ g.computeTangents(); }catch(e){} }
    const m = new THREE.Mesh(g, null);
    m.castShadow = true; m.receiveShadow = true;
    return m;
  };
  const skinMat = (skinKey, browHex) => {
    const m = new THREE.MeshPhysicalMaterial({
      color:new THREE.Color().setRGB(...(SKIN[skinKey] || SKIN.s4)),
      vertexColors:true,
      roughness:1, metalness:0, roughnessMap:SK.rough, normalMap:SK.normal,
      normalScale:new THREE.Vector2(.34,.34), clearcoat:.18, clearcoatRoughness:.52,
      sheen:.5, sheenRoughness:.9, sheenColor:new THREE.Color(0xff9a80),
      envMapIntensity:.78, side:THREE.DoubleSide });
    m.onBeforeCompile = sh => {
      sh.uniforms.skinMask = { value: SK.mask };
      sh.uniforms.skinWarm = { value: SK.warm };
      sh.uniforms.browColor = { value: new THREE.Color(browHex) };
      sh.uniforms.lipColor  = { value: new THREE.Color(0xa8554f) };
      sh.fragmentShader = sh.fragmentShader
        .replace('#include <common>', '#include <common>\n' +
          'uniform sampler2D skinMask; uniform sampler2D skinWarm;\n' +
          'uniform vec3 browColor; uniform vec3 lipColor;')
        .replace('#include <color_fragment>', '#include <color_fragment>\n' +
          '{ vec4 mk = texture2D(skinMask, vNormalMapUv);\n' +
          '  vec3 wm = texture2D(skinWarm, vNormalMapUv).rgb;\n' +
          '  vec3 b = diffuseColor.rgb;\n' +
          '  b = mix(b, b * vec3(1.10,0.74,0.70), wm.r * 0.42);\n' +
          '  b = mix(b, b * vec3(0.70,0.48,0.44), wm.g * 0.75);\n' +
          '  b *= (0.66 + 0.34 * mk.a);\n' +
          '  b = mix(b, lipColor, mk.b * 0.88);\n' +
          '  b = mix(b, browColor * 0.92, mk.r * 0.42);\n' +
          '  b = mix(b, vec3(0.10,0.07,0.07), mk.g * 0.70);\n' +
          '  diffuseColor.rgb = b; }');
    };
    return withSkinScatter(THREE, withDetail(THREE, m, PORE, 34, .95), SSS);
  };
  const cardMat = hex => withTransmit(THREE, new THREE.MeshPhysicalMaterial({
    color:hex, map:STRAND, alphaTest:.40, transparent:false, vertexColors:true,
    roughness:.62, metalness:0, anisotropy:.45, anisotropyRotation:Math.PI/2,
    /* The hair's highlight is the one place it can go wrong in a hurry.
       Left at the default specular strength the anisotropic lobe under a
       strong rim light turns the crown white, and a white crown on brown
       hair reads as a bald patch. */
    sheen:0, specularIntensity:.30, envMapIntensity:.10, side:THREE.DoubleSide }),
    0xffb070, 2.8, 2.00);
  /* The brow strands. Dark, slightly cooler than the hair, barely
     reflective -- a brow that catches the environment map reads as wet
     paint. Lit mostly by the key, which is what gives the strands their
     edge. */
  const browMat = hex => new THREE.MeshPhysicalMaterial({
    color:new THREE.Color(hex).multiplyScalar(.88), vertexColors:true,
    roughness:.48, metalness:0, clearcoat:.30, clearcoatRoughness:.38,
    sheen:0, envMapIntensity:.20, side:THREE.DoubleSide });
  /* The cap under the cards is not hair, it is the absence of a gap: it
     exists so no scalp shows between the strands. At .45 of the hair
     colour it was lighter than the cards around it under a strong key,
     and it showed through as a bright tiled patch at the crown that read
     as a bald spot. It wants to be the darkest thing on the head. */
  /* Lashes are nearly black and nearly matt: a lash that catches a
     highlight reads as a stray hair on the lens. */
  const lashMat = () => new THREE.MeshPhysicalMaterial({
    color:0x15100f, vertexColors:true, roughness:.55, metalness:0,
    clearcoat:.25, clearcoatRoughness:.4, sheen:0, specularIntensity:.25,
    envMapIntensity:.14, side:THREE.DoubleSide });
  const capMat = hex => new THREE.MeshStandardMaterial({
    color:new THREE.Color(hex).multiplyScalar(.08), roughness:.95, metalness:0,
    envMapIntensity:.06, side:THREE.DoubleSide });
  /* Cloth gets the weave as its normal map. The garments carry the
     body's UVs now, so it tiles across them properly instead of sampling
     one texel for the whole shirt, which is what the roughness map had
     been doing. */
  const clothMat = (hex, rough) => new THREE.MeshPhysicalMaterial({
    color:hex, vertexColors:true,
    roughness:rough == null ? .86 : rough, metalness:0,
    roughnessMap:SK.rough, normalMap:FABRIC,
    normalScale:new THREE.Vector2(.20,.20), sheen:.7, sheenRoughness:.75,
    sheenColor:new THREE.Color(0xffffff), envMapIntensity:.85, side:THREE.DoubleSide });
  const leather = hex => new THREE.MeshPhysicalMaterial({
    color:hex == null ? 0x16161a : hex, vertexColors:true,
    roughness:.34, metalness:0, clearcoat:.85,
    clearcoatRoughness:.22, envMapIntensity:1.2, side:THREE.DoubleSide });

  function update(fig, kibbeTypeId){
    if(!alive) return 0;
    const t0 = performance.now();
    /* The Kibbe result is the starting shape; the member's own sliders
       are applied on top of it, which is the order the editor presents
       them in -- "these start from your result, move anything you like". */
    const vec = (B.head.kibbe.types || {})[kibbeTypeId] || { classic:1 };
    const mix = MACRO_NEUTRAL
      .concat(shapeFor(B, vec))
      .concat(sliderMix(B, fig.body, fig.face));
    const { body, helper } = applyMix(B, mix);
    const eL = groupCentre(B, helper, 'helper-l-eye');
    const eR = groupCentre(B, helper, 'helper-r-eye');
    const eyeY = (eL[1]+eR[1])*.5, eyeZ = (eL[2]+eR[2])*.5;
    const gap = Math.abs(eL[0]-eR[0]) || 1;
    styliseHead(body, eyeY, eyeZ, gap, 1.12);
    styliseHead(helper, eyeY, eyeZ, gap, 1.12);

    /* The eye landmarks AFTER styliseHead: the head is scaled there, so
       anything measured before it is measured on a different head. */
    const eLs = groupCentre(B, helper, 'helper-l-eye');
    const eRs = groupCentre(B, helper, 'helper-r-eye');
    const gapS = Math.abs(eLs[0] - eRs[0]) || 1;
    const headCut = (eLs[1] + eRs[1]) * .5 - 1.55 * gapS;

    const Bd = makeBody(B, body);
    armsDown(Bd, 34);
    const Bd2 = makeBody(B, body);
    Bd2.adj = Bd.adj;        // the adjacency is the same mesh; build it once
    const nrm = bodyNormals(Bd2, B.index);

    if(group){ scene.remove(group); disposeTree(group); }
    group = new THREE.Group();
    const hairHex = HAIRC[fig.hairColor] || HAIRC.h2;

    if(!only || only === 'body'){
      // One level of subdivision, head only -- see refine() for why the
      // new vertices do not go at the midpoints of the edges.
      const R = refine(body, B.index, nrm, B.uv, i => body[i*3+1] > headCut);
      /* Crease darkening, computed on the refined mesh and carried as a
         vertex colour, which the skin shader multiplies into the diffuse
         before anything else touches it. */
      const ao = obscurance(R.position, R.normal, R.index, { strength:.85, floor:.42 });
      const aoC = new Float32Array(ao.length * 3);
      for(let i = 0; i < ao.length; i++) aoC[i*3] = aoC[i*3+1] = aoC[i*3+2] = ao[i];
      const bodyMesh = geom({ position:R.position, normal:R.normal,
                              uv:R.uv, index:R.index, color:aoC });
      bodyMesh.material = skinMat(fig.skin, hairHex);
      group.add(bodyMesh);
    }

    /* Garments get the same crease darkening. Cloth is mostly where the
       folds are, and a fold with no shadow in it is a painted line. */
    const withAO = (d, st, fl) => {
      if(!d) return d;
      const ao = obscurance(d.position, d.normal, d.index,
                            { strength: st == null ? .55 : st, floor: fl == null ? .62 : fl });
      const c = new Float32Array(ao.length * 3);
      for(let i = 0; i < ao.length; i++) c[i*3] = c[i*3+1] = c[i*3+2] = ao[i];
      d.color = c;
      return d;
    };
    const add = (d, mat, tag) => { if(!d) return; if(only && tag !== only) return;
      const m = geom(d); m.material = mat; group.add(m); };
    const wear = fig.wear || {};
    const col = slot => GARMC[(wear[slot] || {}).color] == null ? 0x8a8f98 : GARMC[wear[slot].color];
    const sty = slot => (wear[slot] || {}).style;

    const bs = sty('bottom');
    if(bs && SKIRT[bs]) add(withAO(mkSkirt(Bd2, B.index, nrm, SKIRT[bs])), clothMat(col('bottom')), 'bottom');
    else if(bs && BOTTOM[bs]) add(withAO(mkBottom(Bd2, B.index, nrm, BOTTOM[bs])), clothMat(col('bottom'), .72), 'bottom');
    const ts = sty('top');
    if(ts && TOP[ts]) add(withAO(mkTop(Bd2, B.index, nrm, TOP[ts])), clothMat(col('top')), 'top');
    const ss = sty('shoes');
    add(withAO(mkShoes(Bd2, SHOE[ss] || 'flat'), .7, .5), leather(ss ? col('shoes') : 0x16161a), 'shoes');

    /* The brows, as strands on the skin rather than as a smudge in the
       texture. They are built from the same positions and normals the
       body mesh uses, so they stay seated through every slider. */
    add(buildBrows(body, nrm, eLs, eRs, 11), browMat(hairHex), 'brows');

    // the eyes, from the morphed helper spheres
    for(const g of ['helper-l-eye', 'helper-r-eye']){
      const ids = B.helpers[g];
      if(!ids || !ids.length) continue;
      const c = groupCentre(B, helper, g);
      let rad = 0;
      for(const i of ids)
        rad = Math.max(rad, Math.hypot(helper[i*3]-c[0], helper[i*3+1]-c[1], helper[i*3+2]-c[2]));
      group.add(eyeball(c, rad, 0x5d7f52));
      /* Lashes, rooted on the mesh's own lid rims. The side is whichever
         eye this is; the two strips are the lower and the upper lid. */
      const sd = g === 'helper-l-eye' ? 'l' : 'r';
      const lash = buildLashes(helper, [
        { ids: B.helpers['helper-' + sd + '-eyelashes-2'], upper:true,
          count:112, len:.30, wid:.022, up:.55, fwd:.80, sweep:.55, curl:.45 },
        { ids: B.helpers['helper-' + sd + '-eyelashes-1'], upper:false,
          count:62, len:.15, wid:.020, up:.70, fwd:.70, sweep:.35, curl:.10 },
      ], c, rad, 17, c[0]);
      add(lash, lashMat(), 'lashes');
    }

    const hs = HAIR[fig.hair];
    if(hs){
      const eL2 = groupCentre(B, helper, 'helper-l-eye');
      const eR2 = groupCentre(B, helper, 'helper-r-eye');
      const cap = scalp(body, B.index, nrm, (eL2[1]+eR2[1])*.5, { front:.76, thick:.030 });
      if(cap){
        add({ position:cap.pos, normal:cap.nrm, index:cap.tris, n:cap.pos.length/3 },
            capMat(hairHex));
        add(buildHair(cap.pos, cap.nrm, cap.tris, hs, 7), cardMat(hairHex));
      }
    }

    const bb = new THREE.Box3().setFromObject(group);
    const c0 = bb.getCenter(new THREE.Vector3());
    const span = bb.max.y - bb.min.y;
    group.position.set(-c0.x, -bb.min.y, -c0.z);
    scene.add(group);
    /* Frame on a FIXED height, not on the figure's own. Fitting the
       camera to each figure normalises height straight back out -- the
       height slider moved the mesh and the picture never changed, which
       in a body editor is the one thing it must not do. The first figure
       built sets the reference and every later one is measured against
       it. */
    if(!refSpan) refSpan = span;
    rig(refSpan);
    const frame = refSpan * 1.06;
    const dist = (frame*.56) / Math.tan(22*Math.PI/360);
    cam.position.set(0, frame*.52, dist); cam.lookAt(0, frame*.52, 0);
    render();
    return performance.now() - t0;
  }

  let rigged = 0;
  function rig(span){
    if(rigged) return;
    rigged = 1;
    const key = new THREE.DirectionalLight(0xfff1e2, 1.45);
    key.position.set(-span*.7, span*1.05, span*.85);
    key.castShadow = true; key.shadow.mapSize.set(2048, 2048);
    key.shadow.camera.left = -span*.45; key.shadow.camera.right = span*.45;
    key.shadow.camera.top = span*1.1; key.shadow.camera.bottom = -span*.1;
    key.shadow.camera.far = span*4; key.shadow.bias = -.0012;
    key.shadow.normalBias = span*.013; key.shadow.radius = 6;
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xa8c4ec, .50);
    fill.position.set(span*.9, span*.45, span*.7); scene.add(fill);
    const rimL = new THREE.DirectionalLight(0xffeedc, 1.65);
    rimL.position.set(span*.35, span*.9, -span*1.0); scene.add(rimL);
    const floor = new THREE.Mesh(new THREE.PlaneGeometry(span*6, span*6),
      new THREE.ShadowMaterial({ opacity:.46 }));
    floor.rotation.x = -Math.PI/2; floor.receiveShadow = true; scene.add(floor);
  }
  function render(){
    if(!alive) return;
    if(group){ group.rotation.y = yaw; group.rotation.x = pitch; }
    r.render(scene, cam);
  }
  function disposeTree(root){
    root.traverse(ob => {
      if(!ob.isMesh) return;
      ob.geometry.dispose();
      if(ob.material) ob.material.dispose();
    });
  }
  function spin(dx, dy){
    yaw += dx * .011;
    // a little tilt, firmly clamped: past about fifteen degrees a figure
    // standing on a shadow plane starts to look like it is falling over
    if(dy) pitch = Math.max(-0.26, Math.min(0.26, pitch + dy * .006));
    render();
  }
  function setYaw(a){ yaw = a; render(); }
  function snapshot(){ render(); return canvas.toDataURL('image/png'); }
  function dispose(){
    alive = false; cancelAnimationFrame(raf);
    if(group) disposeTree(group);
    for(const k in SK) SK[k].dispose();
    STRAND.dispose(); PORE.dispose(); FABRIC.dispose(); SSS.dispose();
    if(scene.environment) scene.environment.dispose();
    r.dispose();
  }
  function setOnly(w){ only = w; }
  return { update, spin, setYaw, setOnly, snapshot, dispose };
}
