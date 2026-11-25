// A studio environment, generated rather than downloaded: a dark room with
// one big soft key panel, a cool fill panel and a bright strip behind. This
// is the single biggest difference between a figure that reads as a 2005
// game asset and one that reads as a photograph -- not the mesh.
export function studioEnv(THREE, renderer, opts){
  const o = opts || {};
  const W = 512, H = 256;
  const c = document.createElement('canvas'); c.width = W; c.height = H;
  const g = c.getContext('2d');
  // sky -> floor gradient
  const grd = g.createLinearGradient(0, 0, 0, H);
  grd.addColorStop(0.00, o.top    || '#8fa6c8');
  grd.addColorStop(0.46, o.mid    || '#53606f');
  grd.addColorStop(0.52, o.horizon|| '#2b2f36');
  grd.addColorStop(1.00, o.floor  || '#15171b');
  g.fillStyle = grd; g.fillRect(0, 0, W, H);
  // soft panels. u is azimuth: 0 behind, .25 right, .5 front, .75 left
  const panel = (u, v, rw, rh, col, a) => {
    const x = u * W, y = v * H;
    const rg = g.createRadialGradient(x, y, 0, x, y, Math.max(rw, rh));
    rg.addColorStop(0, col); rg.addColorStop(1, 'rgba(0,0,0,0)');
    g.globalAlpha = a == null ? 1 : a;
    g.save(); g.translate(x, y); g.scale(1, rh / rw); g.translate(-x, -y);
    g.fillStyle = rg; g.beginPath(); g.arc(x, y, rw, 0, 7); g.fill();
    g.restore(); g.globalAlpha = 1;
  };
  panel(0.62, 0.30, 150, 110, o.key  || '#fff4e2', 1);    // key, front-left high
  panel(0.30, 0.44, 120,  90, o.fill || '#9fc0ff', .55);  // fill, front-right
  panel(0.95, 0.26,  95,  70, o.rim  || '#ffffff', .85);  // rim, behind
  const tex = new THREE.CanvasTexture(c);
  tex.mapping = THREE.EquirectangularReflectionMapping;
  tex.colorSpace = THREE.SRGBColorSpace;
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
