// A studio environment, generated rather than downloaded: a dark room with
// one big soft key panel, a cool fill panel and a bright strip behind. This
// is the single biggest difference between a figure that reads as a 2005
// game asset and one that reads as a photograph -- not the mesh.
export function studioEnv(THREE, renderer, opts){
  const o = opts || {};
  const W = 512, H = 256;
  /* A FLOAT environment, not a canvas one.

     A canvas is eight bits a channel, so the brightest thing that can
     possibly be in it is white -- the same value as a sheet of paper.
     A real softbox is twenty to fifty times brighter than the wall
     behind it, and that ratio is the whole of what a specular highlight
     is: it is a picture of the light source. Clamped to 1.0 there is
     nothing for a shiny surface to reflect that is any brighter than
     the room, so skin got no highlight worth the name, hair had no
     sheen, and an eye -- which is a mirrored ball and should show the
     lights as crisp little suns -- had no catchlight at all. Every
     attempt to fix the eye at the eye failed for this reason: there was
     nothing in the world for it to reflect.

     So the panels are written at their real intensities into a float
     buffer and PMREM takes it from there. */
  const data = new Float32Array(W * H * 4);
  /* The room itself is nearly black. It used to be a bright blue-grey
     sky, which lit the whole figure from above with cool light and took
     the colour out of the skin -- a tanned face came out porcelain. In
     a studio the walls are black on purpose; all the light comes from
     the panels, and that is what keeps skin looking like skin. */
  const SKY   = o.top     || [0.038, 0.044, 0.060];
  const MID   = o.mid     || [0.018, 0.020, 0.025];
  const HORIZ = o.horizon || [0.009, 0.009, 0.011];
  const FLOOR = o.floor   || [0.005, 0.005, 0.006];
  const ramp = (v) => {
    if(v < 0.46) return mix3(SKY, MID, v / 0.46);
    if(v < 0.52) return mix3(MID, HORIZ, (v - 0.46) / 0.06);
    return mix3(HORIZ, FLOOR, (v - 0.52) / 0.48);
  };
  function mix3(a, b, t){
    t = t < 0 ? 0 : t > 1 ? 1 : t;
    return [a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t];
  }
  /* Everything below is authored with v = 0 at the ZENITH, the way the
     canvas version was, and written into the buffer upside down.

     A DataTexture ignores flipY -- WebGL's unpack flip does not apply to
     one -- so row 0 of the array is v = 0, and three.js's equirect
     lookup puts v = 0 at straight DOWN. Writing the sky into row 0 puts
     the sky under the figure's feet and the key light below the
     horizon, which is exactly what happened: the whole scene went pale
     and flat and lit from nowhere. */
  const row = y => H - 1 - y;
  for(let y = 0; y < H; y++){
    const col = ramp((y + .5) / H);
    for(let x = 0; x < W; x++){
      const i = (row(y)*W + x) * 4;
      data[i] = col[0]; data[i+1] = col[1]; data[i+2] = col[2]; data[i+3] = 1;
    }
  }
  /* u is azimuth: 0 behind, .25 right, .5 front, .75 left. A panel has a
     flat core and a short shoulder -- a softbox has an edge, and it is
     the edge that makes a highlight look like a shape rather than a
     smudge. */
  const panel = (u, v, rw, rh, col, I) => {
    const cxp = u * W, cyp = v * H;
    const x0 = Math.floor(cxp - rw), x1 = Math.ceil(cxp + rw);
    for(let y = Math.max(0, Math.floor(cyp - rh)); y < Math.min(H, Math.ceil(cyp + rh)); y++){
      for(let xx = x0; xx < x1; xx++){
        const x = ((xx % W) + W) % W;               // wrap in azimuth
        const dx = (xx - cxp) / rw, dy = (y - cyp) / rh;
        const d = Math.sqrt(dx*dx + dy*dy);
        if(d >= 1) continue;
        const t = 1 - smoothstep(0.62, 1.0, d);     // flat core, short shoulder
        const i = (row(y)*W + x) * 4;
        data[i]   += col[0] * I * t;
        data[i+1] += col[1] * I * t;
        data[i+2] += col[2] * I * t;
      }
    }
  };
  function smoothstep(e0, e1, x){
    const t = Math.max(0, Math.min(1, (x - e0) / (e1 - e0)));
    return t * t * (3 - 2 * t);
  }
  /* Small and bright, not big and bright. A panel's contribution to the
     DIFFUSE light in the room goes as its intensity times the solid
     angle it covers, but its contribution to a highlight goes as its
     intensity alone. The first attempt kept the old panels' size and
     merely raised their intensity, which blew the whole scene out: the
     sclera went to paper white and the skin with it. Shrunk to about
     fifteen degrees across -- the size of a real softbox at arm's
     length -- they put the same amount of light into the room as the
     old clamped ones and a highlight thirty times brighter. */
  /* And each panel sits where its directional light already is, worked
     back from the rig's own positions. A highlight that comes from one
     direction while the shading comes from another is the thing that
     makes a render look composited: the eye catches it instantly even
     when it cannot say what is wrong. */
  panel(0.860, 0.258, 26, 19, o.key  || [1.00, 0.95, 0.88], o.keyI  == null ? 30 : o.keyI);
  panel(0.605, 0.381, 22, 16, o.fill || [0.62, 0.76, 1.00], o.fillI == null ?  9 : o.fillI);
  panel(0.304, 0.278, 18, 13, o.rim  || [1.00, 0.98, 0.95], o.rimI  == null ? 22 : o.rimI);
  const tex = new THREE.DataTexture(data, W, H, THREE.RGBAFormat, THREE.FloatType);
  tex.mapping = THREE.EquirectangularReflectionMapping;
  tex.colorSpace = THREE.NoColorSpace;          // the data is already linear
  tex.needsUpdate = true;
  const pmrem = new THREE.PMREMGenerator(renderer);
  pmrem.compileEquirectangularShader();
  const env = pmrem.fromEquirectangular(tex).texture;
  pmrem.dispose(); tex.dispose();
  return env;
}

// Fine skin detail. Pores and a little colour variation at 512px cost
// nothing and stop the skin reading as painted plastic.
export function skinMaps(THREE){
  const N = 512;
  const c = document.createElement('canvas'); c.width = c.height = N;
  const g = c.getContext('2d');
  const img = g.createImageData(N, N);
  // value noise, two octaves, so pores have clusters rather than static
  const rnd = (x, y, s) => {
    const n = Math.sin(x * 127.1 + y * 311.7 + s * 74.7) * 43758.5453;
    return n - Math.floor(n);
  };
  const oct = (x, y, f, s) => {
    const xi = Math.floor(x * f), yi = Math.floor(y * f);
    const xf = x * f - xi, yf = y * f - yi;
    const u = xf * xf * (3 - 2 * xf), v = yf * yf * (3 - 2 * yf);
    const a = rnd(xi, yi, s), b = rnd(xi + 1, yi, s);
    const cc = rnd(xi, yi + 1, s), d = rnd(xi + 1, yi + 1, s);
    return (a + (b - a) * u) + ((cc - a) + (d - cc) * u - (b - a) * u) * v;
  };
  for(let y = 0; y < N; y++) for(let x = 0; x < N; x++){
    const u = x / N, v = y / N;
    let n = oct(u, v, 160, 1) * .55 + oct(u, v, 46, 2) * .30 + oct(u, v, 11, 3) * .15;
    const k = Math.round(120 + n * 135);
    const i = (y * N + x) * 4;
    img.data[i] = img.data[i+1] = img.data[i+2] = k; img.data[i+3] = 255;
  }
  g.putImageData(img, 0, 0);
  const rough = new THREE.CanvasTexture(c);
  rough.wrapS = rough.wrapT = THREE.RepeatWrapping;
  rough.repeat.set(7, 7);
  return { rough };
}
