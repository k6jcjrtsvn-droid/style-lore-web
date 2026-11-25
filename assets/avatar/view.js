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

export async function mount(canvas, opts){
  const o = opts || {};
  const W = o.width || 360, H = o.height || 560;
  const r = new THREE.WebGLRenderer({ canvas, antialias:true, alpha:false });
  r.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
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
  const tex = (f, srgb) => { const t = tl.load(base + f);
    t.colorSpace = srgb ? THREE.SRGBColorSpace : THREE.NoColorSpace;
    t.flipY = false; t.anisotropy = 8; return t; };
  const SK = { mask:tex('skin_mask.png'), warm:tex('skin_warm.png'),
               normal:tex('skin_normal.png'), rough:tex('skin_rough.png') };
  const STRAND = tex('hair_strand.png', true);

  const B = await loadBase(o.bundle || './assets/avatar-base-v1.bin');

  let group = null, yaw = .30, raf = 0, alive = true;

  const geom = d => {
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(d.position, 3));
    if(d.normal) g.setAttribute('normal', new THREE.Float32BufferAttribute(d.normal, 3));
    if(d.color)  g.setAttribute('color', new THREE.Float32BufferAttribute(d.color, 3));
    if(d.uv){
      g.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array(d.uv), 2));
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
    const nrm = bodyNormals(Bd2, B.index);

    if(group){ scene.remove(group); disposeTree(group); }
    group = new THREE.Group();
    const hairHex = HAIRC[fig.hairColor] || HAIRC.h2;

    const bodyMesh = geom({ position:body, normal:nrm, uv:B.uv, index:B.index });
    bodyMesh.material = skinMat(fig.skin, hairHex);
    group.add(bodyMesh);

    const add = (d, mat) => { if(!d) return; const m = geom(d); m.material = mat; group.add(m); };
    const wear = fig.wear || {};
    const col = slot => GARMC[(wear[slot] || {}).color] == null ? 0x8a8f98 : GARMC[wear[slot].color];
    const sty = slot => (wear[slot] || {}).style;

    const bs = sty('bottom');
    if(bs && SKIRT[bs]) add(mkSkirt(Bd2, B.index, nrm, SKIRT[bs]), clothMat(col('bottom')));
    else if(bs && BOTTOM[bs]) add(mkBottom(Bd2, B.index, nrm, BOTTOM[bs]), clothMat(col('bottom'), .72));
    const ts = sty('top');
    if(ts && TOP[ts]) add(mkTop(Bd2, B.index, nrm, TOP[ts]), clothMat(col('top')));
    const ss = sty('shoes');
    add(mkShoes(Bd2, SHOE[ss] || 'flat'), leather(ss ? col('shoes') : 0x16161a));

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
    key.castShadow = true; key.shadow.mapSize.set(1024, 1024);
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
  function snapshot(){ render(); return canvas.toDataURL('image/png'); }
  function dispose(){
    alive = false; cancelAnimationFrame(raf);
    if(group) disposeTree(group);
    for(const k in SK) SK[k].dispose();
    STRAND.dispose();
    if(scene.environment) scene.environment.dispose();
    r.dispose();
  }
  return { update, spin, snapshot, dispose };
}
