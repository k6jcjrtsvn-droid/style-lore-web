/* The 3D figure, mounted on a canvas.

   Everything heavy lives behind this module: three.js, the base bundle
   and the skin maps are about 3.7 MB together, and none of it is fetched
   until someone actually opens the 3D view. The app's own 2D figure is
   untouched and is still what the feed, the profile and Lorey use. */
import * as THREE from '../three.module.js';
import { loadBase, applyMix, groupCentre, styliseHead, shapeFor, MACRO_NEUTRAL }
  from './avatar.js';
import { makeBody, armsDown, bodyNormals } from './body.js';
import { top as mkTop, bottoms as mkBottom, skirt as mkSkirt, shoes as mkShoes }
  from './wardrobe.js';
import { scalp, buildHair } from './hairgen.js';
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
  const N = 256, c = document.createElement('canvas'); c.width = c.height = N;
  const g = c.getContext('2d');
  const col = new THREE.Color(hex);
  const hx = '#' + col.getHexString();
  const dark = '#' + col.clone().multiplyScalar(.34).getHexString();
  const lit  = '#' + col.clone().lerp(new THREE.Color(0xffffff), .42).getHexString();
  g.fillStyle = '#d8d0c8'; g.fillRect(0, 0, N, N);
  const warm = g.createLinearGradient(0, 0, N, 0);
  warm.addColorStop(0, 'rgba(198,142,128,.55)');
  warm.addColorStop(.5, 'rgba(255,255,255,0)');
  warm.addColorStop(1, 'rgba(198,142,128,.55)');
  g.fillStyle = warm; g.fillRect(0, 0, N, N);
  const cx = N/2, cy = N/2, IR = N*0.175;
  const rg = g.createRadialGradient(cx, cy, IR*.22, cx, cy, IR);
  rg.addColorStop(0, dark); rg.addColorStop(.40, hx);
  rg.addColorStop(.84, lit); rg.addColorStop(1, dark);
  g.fillStyle = rg; g.beginPath(); g.arc(cx, cy, IR, 0, 7); g.fill();
  g.strokeStyle = 'rgba(0,0,0,.28)'; g.lineWidth = 1.0;
  for(let i = 0; i < 120; i++){
    const a = i / 120 * Math.PI * 2 + (i * 2654435761 % 97) / 97 * .06;
    const r0 = IR * .32, r1 = IR * (.74 + (i % 7) * .03);
    g.beginPath();
    g.moveTo(cx + Math.cos(a)*r0, cy + Math.sin(a)*r0);
    g.lineTo(cx + Math.cos(a)*r1, cy + Math.sin(a)*r1);
    g.stroke();
  }
  g.lineWidth = IR*.13; g.strokeStyle = 'rgba(8,4,12,.80)';
  g.beginPath(); g.arc(cx, cy, IR*.95, 0, 7); g.stroke();
  g.fillStyle = '#05030a';
  g.beginPath(); g.arc(cx, cy, IR*.36, 0, 7); g.fill();
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}

export async function mount(canvas, opts){
  const o = opts || {};
  const W = o.width || 360, H = o.height || 560;
  const r = new THREE.WebGLRenderer({ canvas, antialias:true, alpha:false });
  // Render above the display resolution and let the browser downscale.
  // Alpha-tested hair cards alias badly at 1:1 and a figure seen close up
  // is mostly edges; this is the cheapest sharpness there is.
  const SS = o.supersample == null ? 1.6 : o.supersample;
  r.setPixelRatio(Math.min((window.devicePixelRatio || 1) * SS, 3.2));
  r.setSize(W, H, false);
  r.outputColorSpace = THREE.SRGBColorSpace;
  r.toneMapping = THREE.ACESFilmicToneMapping;
  r.toneMappingExposure = .95;
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
  const tex = (f, srgb) => new Promise((res, rej) => {
    tl.load(base + f, t => {
      t.colorSpace = srgb ? THREE.SRGBColorSpace : THREE.NoColorSpace;
      t.flipY = false; t.anisotropy = 8; res(t);
    }, undefined, rej);
  });
  const [mask, warm, normal, rough, STRAND, B] = await Promise.all([
    tex('skin_mask.png'), tex('skin_warm.png'), tex('skin_normal.png'),
    tex('skin_rough.png'), tex('hair_strand.png', true),
    loadBase(o.bundle || './assets/avatar-base-v1.bin'),
  ]);
  const SK = { mask, warm, normal, rough };

  let group = null, yaw = .30, raf = 0, alive = true;
  let only = null;   // isolation: draw one part on its own
  const EYE = { tex: null, hex: null };
  function eyeball(centre, radius, hex){
    if(EYE.hex !== hex){
      if(EYE.tex) EYE.tex.dispose();
      EYE.tex = eyeTex(THREE, hex); EYE.hex = hex;
    }
    const grp = new THREE.Group();
    const R = radius * 0.96;
    const ball = new THREE.Mesh(new THREE.SphereGeometry(R, 40, 28),
      new THREE.MeshPhysicalMaterial({ map:EYE.tex, roughness:.30, clearcoat:.85,
        clearcoatRoughness:.10, envMapIntensity:.55 }));
    ball.rotation.y = -Math.PI / 2;   // (u .5, v .5) sits on +x
    grp.add(ball);
    const cornea = new THREE.Mesh(new THREE.SphereGeometry(R*1.035, 32, 24),
      new THREE.MeshPhysicalMaterial({ color:0xffffff, roughness:.015, metalness:0,
        clearcoat:1, clearcoatRoughness:.01, transparent:true, opacity:.08,
        envMapIntensity:1.4, depthWrite:false }));
    grp.add(cornea);
    grp.position.set(centre[0], centre[1], centre[2] - radius*0.26);
    return grp;
  }

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
      roughness:1, metalness:0, roughnessMap:SK.rough, normalMap:SK.normal,
      normalScale:new THREE.Vector2(.30,.30), clearcoat:.18, clearcoatRoughness:.52,
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
          '  b = mix(b, browColor * 0.92, mk.r * 0.82);\n' +
          '  b = mix(b, vec3(0.10,0.07,0.07), mk.g * 0.70);\n' +
          '  diffuseColor.rgb = b; }');
    };
    return m;
  };
  const cardMat = hex => new THREE.MeshPhysicalMaterial({
    color:hex, map:STRAND, alphaTest:.40, transparent:false, vertexColors:true,
    roughness:.42, metalness:0, anisotropy:.70, anisotropyRotation:Math.PI/2,
    sheen:0, envMapIntensity:.16, side:THREE.DoubleSide });
  const capMat = hex => new THREE.MeshStandardMaterial({
    color:new THREE.Color(hex).multiplyScalar(.45), roughness:.95, metalness:0,
    envMapIntensity:.06, side:THREE.DoubleSide });
  const clothMat = (hex, rough) => new THREE.MeshPhysicalMaterial({
    color:hex, roughness:rough == null ? .86 : rough, metalness:0,
    roughnessMap:SK.rough, sheen:.7, sheenRoughness:.75,
    sheenColor:new THREE.Color(0xffffff), envMapIntensity:.85, side:THREE.DoubleSide });
  const leather = hex => new THREE.MeshPhysicalMaterial({
    color:hex == null ? 0x16161a : hex, roughness:.34, metalness:0, clearcoat:.85,
    clearcoatRoughness:.22, envMapIntensity:1.2, side:THREE.DoubleSide });

  function update(fig, kibbeTypeId){
    if(!alive) return 0;
    const t0 = performance.now();
    const vec = (B.head.kibbe.types || {})[kibbeTypeId] || { classic:1 };
    const mix = MACRO_NEUTRAL.concat(shapeFor(B, vec));
    const { body, helper } = applyMix(B, mix);
    const eL = groupCentre(B, helper, 'helper-l-eye');
    const eR = groupCentre(B, helper, 'helper-r-eye');
    const eyeY = (eL[1]+eR[1])*.5, eyeZ = (eL[2]+eR[2])*.5;
    const gap = Math.abs(eL[0]-eR[0]) || 1;
    styliseHead(body, eyeY, eyeZ, gap, 1.12);
    styliseHead(helper, eyeY, eyeZ, gap, 1.12);

    const Bd = makeBody(B, body);
    armsDown(Bd, 34);
    const Bd2 = makeBody(B, body);
    Bd2.adj = Bd.adj;        // the adjacency is the same mesh; build it once
    const nrm = bodyNormals(Bd2, B.index);

    if(group){ scene.remove(group); disposeTree(group); }
    group = new THREE.Group();
    const hairHex = HAIRC[fig.hairColor] || HAIRC.h2;

    if(!only || only === 'body'){
      const bodyMesh = geom({ position:body, normal:nrm, uv:B.uv, index:B.index });
      bodyMesh.material = skinMat(fig.skin, hairHex);
      group.add(bodyMesh);
    }

    const add = (d, mat, tag) => { if(!d) return; if(only && tag !== only) return;
      const m = geom(d); m.material = mat; group.add(m); };
    const wear = fig.wear || {};
    const col = slot => GARMC[(wear[slot] || {}).color] == null ? 0x8a8f98 : GARMC[wear[slot].color];
    const sty = slot => (wear[slot] || {}).style;

    const bs = sty('bottom');
    if(bs && SKIRT[bs]) add(mkSkirt(Bd2, B.index, nrm, SKIRT[bs]), clothMat(col('bottom')), 'bottom');
    else if(bs && BOTTOM[bs]) add(mkBottom(Bd2, B.index, nrm, BOTTOM[bs]), clothMat(col('bottom'), .72), 'bottom');
    const ts = sty('top');
    if(ts && TOP[ts]) add(mkTop(Bd2, B.index, nrm, TOP[ts]), clothMat(col('top')), 'top');
    const ss = sty('shoes');
    add(mkShoes(Bd2, SHOE[ss] || 'flat'), leather(ss ? col('shoes') : 0x16161a), 'shoes');

    // the eyes, from the morphed helper spheres
    for(const g of ['helper-l-eye', 'helper-r-eye']){
      const ids = B.helpers[g];
      if(!ids || !ids.length) continue;
      const c = groupCentre(B, helper, g);
      let rad = 0;
      for(const i of ids)
        rad = Math.max(rad, Math.hypot(helper[i*3]-c[0], helper[i*3+1]-c[1], helper[i*3+2]-c[2]));
      group.add(eyeball(c, rad, 0x5d7f52));
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
    rig(span);
    const dist = (span*.56) / Math.tan(22*Math.PI/360);
    cam.position.set(0, span*.52, dist); cam.lookAt(0, span*.52, 0);
    render();
    return performance.now() - t0;
  }

  let rigged = 0;
  function rig(span){
    if(rigged) return;
    rigged = 1;
    const key = new THREE.DirectionalLight(0xfff1e2, 2.1);
    key.position.set(-span*.7, span*1.05, span*.85);
    key.castShadow = true; key.shadow.mapSize.set(2048, 2048);
    key.shadow.camera.left = -span*.45; key.shadow.camera.right = span*.45;
    key.shadow.camera.top = span*1.1; key.shadow.camera.bottom = -span*.1;
    key.shadow.camera.far = span*4; key.shadow.bias = -.0012;
    key.shadow.normalBias = span*.004; key.shadow.radius = 6;
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xa8c4ec, .85);
    fill.position.set(span*.9, span*.45, span*.7); scene.add(fill);
    const rimL = new THREE.DirectionalLight(0xffeedc, 2.4);
    rimL.position.set(span*.35, span*.9, -span*1.0); scene.add(rimL);
    const floor = new THREE.Mesh(new THREE.PlaneGeometry(span*6, span*6),
      new THREE.ShadowMaterial({ opacity:.46 }));
    floor.rotation.x = -Math.PI/2; floor.receiveShadow = true; scene.add(floor);
  }
  function render(){ if(!alive) return; if(group) group.rotation.y = yaw; r.render(scene, cam); }
  function disposeTree(root){
    root.traverse(ob => {
      if(!ob.isMesh) return;
      ob.geometry.dispose();
      if(ob.material) ob.material.dispose();
    });
  }
  function spin(dx){ yaw += dx * .01; render(); }
  function setYaw(a){ yaw = a; render(); }
  function snapshot(){ render(); return canvas.toDataURL('image/png'); }
  function dispose(){
    alive = false; cancelAnimationFrame(raf);
    if(group) disposeTree(group);
    for(const k in SK) SK[k].dispose();
    STRAND.dispose();
    if(scene.environment) scene.environment.dispose();
    r.dispose();
  }
  function setOnly(w){ only = w; }
  return { update, spin, setYaw, setOnly, snapshot, dispose };
}
