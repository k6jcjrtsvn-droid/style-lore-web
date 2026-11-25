/* Anatomy on the client: a port of tools/avatar3d/anat.py.

   The landmark SCAN is not ported, and deliberately so. anat.reference()
   measures the neutral body once and keeps its landmarks as vertex
   indices, precisely because an extreme morph target moves the geometry
   enough that scanning again latches the armpit onto a hip. Those
   indices are the same for every member, so they are baked into the
   bundle and read here. The browser never runs the scan, which also
   means the build and the browser cannot disagree about where the
   shoulder is. */
const smooth = t => { t = t < 0 ? 0 : t > 1 ? 1 : t; return t*t*(3-2*t); };

export function makeBody(B, v){
  const n = v.length / 3;
  let top = -Infinity, bot = Infinity;
  for(let i = 0; i < n; i++){
    const y = v[i*3+1];
    if(y > top) top = y;
    if(y < bot) bot = y;
  }
  const h = top - bot;
  const R = B.head.ref;
  const sp = {};
  for(const s of ['1','-1']){
    const i = B.back[R.shoulder[s]];
    sp[s] = [v[i*3], v[i*3+1], v[i*3+2]];
  }
  const sx = (Math.abs(sp['1'][0]) + Math.abs(sp['-1'][0])) / 2;
  const sy = (sp['1'][1] + sp['-1'][1]) / 2;
  const sz = (sp['1'][2] + sp['-1'][2]) / 2;
  const axis = {};
  for(const s of ['1','-1']){
    const ti = B.back[R.tip[s]];
    const S = sp[s];
    const d = [v[ti*3]-S[0], v[ti*3+1]-S[1], v[ti*3+2]-S[2]];
    const L = Math.hypot(d[0], d[1], d[2]) || 1;
    axis[s] = { S, d: [d[0]/L, d[1]/L, d[2]/L], L };
  }
  const body = {
    v, n, top, bot, h, sx, sy, sz, axis,
    index: B.index,            // the posing relax needs the mesh's own edges
    cls: B.cls,                       // index -> [t, r] from the bundle
    neckY: bot + R.neckFrac * h,
    neck: [0, sy + 0.30, sz + 0.12],
    frac(i){ return (v[i*3+1] - bot) / h; },
    fracP(p){ return (p[1] - bot) / h; },
    neckDist(p){
      const nk = this.neck;
      return Math.hypot(p[0]-nk[0], p[1]-nk[1], (p[2]-nk[2])*0.85);
    },
    /* Posing needs a wide, soft notion of 'arm' so the deformation has no
       cliff in it; clothing needs a narrow, hard one, or a shirt rejects
       half the chest for being within two units of the arm's axis. */
    limb(i){
      const a = this.cls[i];
      if(!a) return null;
      if(a[1] > 1.05 || a[0] < 0.30) return null;
      return a;
    },
  };
  return body;
}

/* Swing each arm about its own shoulder so it hangs at the side. The
   weight falls off in BOTH t and r: a hard radius cut left a vertex just
   inside the arm rotating the whole way while its neighbour just outside
   did not move at all, and the faces spanning that line stretched into a
   funnel that showed through any clothing built on top. */
/* Swing each arm about its own shoulder so it hangs at the side.

   The weight is computed from the arm axis and then SMOOTHED OVER THE
   MESH. That second step is the one that matters. Any weight taken from
   a distance to an axis has a cliff in it somewhere -- the vertices just
   outside the arm's tube sit next to vertices just inside it, and the
   faces that span the two stretch into the pointed wedges that showed up
   at the elbows and read as triangular flaps. Narrowing the tube makes
   the cliff sharper and the wedges BIGGER, which is how I found out the
   radius was never the problem. Relaxing the weight field across the
   mesh removes the cliff wherever it happens to be. */
export function armsDown(B, degrees, opt){
  const o = opt || {};
  const a0deg = (degrees == null ? 34.0 : degrees) * Math.PI / 180;
  const v = B.v;
  const n = B.n;
  const passes = o.relaxPasses == null ? 14 : o.relaxPasses;
  const outX = o.outX == null ? 0.22 : o.outX;
  const outZ = o.outZ == null ? 0.38 : o.outZ;

  if(!B.adj) B.adj = buildAdjacency(B.index, n);
  const adj = B.adj;

  for(const s of ['1','-1']){
    const side = s === '1' ? 1 : -1;
    const { S } = B.axis[s];
    const a0 = a0deg * (-side);

    let w = new Float32Array(n);
    const along = new Float32Array(n);
    for(const key in B.cls){
      const i = +key;
      if(v[i*3] * side <= 0) continue;
      const a = B.cls[i];
      w[i] = smooth((a[0] + 0.35) / 1.15) * smooth((1.75 - a[1]) / 0.75);
      along[i] = a[0];
    }
    // only this side takes part in the relax, or the two arms bleed into
    // each other across the chest
    const mine = new Uint8Array(n);
    for(let i = 0; i < n; i++) mine[i] = v[i*3] * side > 0 ? 1 : 0;
    let next = new Float32Array(n);
    for(let p = 0; p < passes; p++){
      for(let i = 0; i < n; i++){
        if(!mine[i]){ next[i] = 0; continue; }
        const a = adj.start[i], b = adj.start[i+1];
        if(b <= a){ next[i] = w[i]; continue; }
        let sum = w[i], cnt = 1;
        for(let k = a; k < b; k++){ const j = adj.list[k]; if(!mine[j]) continue; sum += w[j]; cnt++; }
        next[i] = sum / cnt;
      }
      const t = w; w = next; next = t;
    }

    for(let i = 0; i < n; i++){
      const wi = w[i];
      if(wi <= 0.001) continue;
      const ang = a0 * wi;
      const rx = v[i*3] - S[0], ry = v[i*3+1] - S[1];
      v[i*3]   = S[0] + rx*Math.cos(ang) - ry*Math.sin(ang);
      v[i*3+1] = S[1] + rx*Math.sin(ang) + ry*Math.cos(ang);
      // Arms hang in front of the body, not inside it. Rotating in the XY
      // plane alone leaves the forearm at the torso's own depth and the
      // elbow ends up buried in the hip.
      const down = smooth((along[i] - 0.8) / 2.2);
      v[i*3]   += side * outX * wi * down;
      v[i*3+2] += outZ * wi * down;
    }
  }
}

function buildAdjacency(index, n){
  const count = new Uint32Array(n + 1);
  const bump = (a, b) => { count[a]++; count[b]++; };
  for(let f = 0; f < index.length; f += 3){
    bump(index[f], index[f+1]); bump(index[f+1], index[f+2]); bump(index[f+2], index[f]);
  }
  const start = new Uint32Array(n + 1);
  let run = 0;
  for(let i = 0; i <= n; i++){ start[i] = run; run += count[i]; }
  const list = new Uint32Array(run);
  const at = start.slice();
  const put = (a, b) => { list[at[a]++] = b; list[at[b]++] = a; };
  for(let f = 0; f < index.length; f += 3){
    put(index[f], index[f+1]); put(index[f+1], index[f+2]); put(index[f+2], index[f]);
  }
  return { start, list };
}

/* Cut a garment along a smooth isoline instead of along face boundaries.
   A port of cutter.shell(). Choosing whole faces is why every hem came
   out stepped: the edge could only follow the edges of the mesh. */
export function shell(B, index, nrm, field, thick, opt){
  const o = opt || {};
  const smoothN = o.smooth == null ? 2 : o.smooth;
  const relax = o.relax || 0;
  const v = B.v;
  const fv = new Map();
  const F = i => {
    let x = fv.get(i);
    if(x === undefined){ x = field(i); fv.set(i, x); }
    return x;
  };
  const P = [], N = [], T = [];
  const base = new Map(), cut = new Map();
  const put = (p, nn) => { P.push(p[0],p[1],p[2]); N.push(nn[0],nn[1],nn[2]); return P.length/3 - 1; };
  const at = i => {
    let x = base.get(i);
    if(x === undefined){
      x = put([v[i*3], v[i*3+1], v[i*3+2]], [nrm[i*3], nrm[i*3+1], nrm[i*3+2]]);
      base.set(i, x);
    }
    return x;
  };
  const onEdge = (i, j) => {
    const key = i < j ? i + ':' + j : j + ':' + i;
    let x = cut.get(key);
    if(x !== undefined) return x;
    const a = F(i), b = F(j);
    let t = (a - b) ? a / (a - b) : 0.5;
    // Keep the cut off the ends of the edge. A crossing at t=0 or t=1
    // puts the new vertex exactly on an existing one and the triangle it
    // makes has no area: those zero-area slivers are the row of bright
    // specks that ran along every hem.
    t = t < 0.06 ? 0.06 : t > 0.94 ? 0.94 : t;
    const pos = [], nn = [];
    for(let k = 0; k < 3; k++){
      pos.push(v[i*3+k] + (v[j*3+k] - v[i*3+k]) * t);
      nn.push(nrm[i*3+k] + (nrm[j*3+k] - nrm[i*3+k]) * t);
    }
    const L = Math.hypot(nn[0],nn[1],nn[2]) || 1;
    x = put(pos, [nn[0]/L, nn[1]/L, nn[2]/L]);
    cut.set(key, x);
    return x;
  };
  for(let f = 0; f < index.length; f += 3){
    const i = index[f], j = index[f+1], k = index[f+2];
    const a = F(i), b = F(j), c = F(k);
    const inside = [];
    if(a > 0) inside.push(i);
    if(b > 0) inside.push(j);
    if(c > 0) inside.push(k);
    if(inside.length === 3){
      T.push(at(i), at(j), at(k));
    } else if(inside.length === 1){
      const p = inside[0];
      const rest = [i,j,k].filter(x => x !== p);
      T.push(at(p), onEdge(p, rest[0]), onEdge(p, rest[1]));
    } else if(inside.length === 2){
      const p = [i,j,k].find(x => inside.indexOf(x) < 0);
      const [q, r] = inside;
      const eq = onEdge(p, q), er = onEdge(p, r);
      T.push(at(q), at(r), er, at(q), er, eq);
    }
  }
  if(!T.length) return null;
  /* The cut edge needs more clearance than the middle of the cloth. A
     boundary vertex sits exactly on the isoline, where the garment and
     the skin are the same surface, so at a uniform offset the skin
     punches through along every hem as a bright ragged rim. Lifting the
     boundary a little further tucks it under instead. */
  const edge = new Map();
  for(let f = 0; f < T.length; f += 3){
    const a=T[f], b=T[f+1], c=T[f+2];
    for(const [i,j] of [[a,b],[b,c],[c,a]]){
      const k = i < j ? i + ':' + j : j + ':' + i;
      edge.set(k, (edge.get(k) || 0) + 1);
    }
  }
  const onEdgeV = new Uint8Array(P.length/3);
  for(const [k, n] of edge){
    if(n !== 1) continue;
    const [i, j] = k.split(':');
    onEdgeV[+i] = 1; onEdgeV[+j] = 1;
  }
  for(let i = 0; i < P.length; i += 3){
    const v3 = i/3;
    let t = typeof thick === 'function' ? thick([P[i], P[i+1], P[i+2]]) : thick;
    if(onEdgeV[v3]) t *= 1.75;
    P[i] += N[i]*t; P[i+1] += N[i+1]*t; P[i+2] += N[i+2]*t;
  }
  if(relax > 0){
    const adj = new Map();
    const link = (a,b) => { if(!adj.has(a)) adj.set(a, new Set()); adj.get(a).add(b); };
    for(let f = 0; f < T.length; f += 3){
      const a=T[f],b=T[f+1],c=T[f+2];
      link(a,b); link(a,c); link(b,a); link(b,c); link(c,a); link(c,b);
    }
    for(let pass = 0; pass < smoothN; pass++){
      const Q = P.slice();
      for(const [i, nb] of adj){
        if(!nb.size) continue;
        let ax=0, ay=0, az=0;
        for(const j of nb){ ax+=P[j*3]; ay+=P[j*3+1]; az+=P[j*3+2]; }
        ax/=nb.size; ay/=nb.size; az/=nb.size;
        Q[i*3]   = P[i*3]  *(1-relax) + ax*relax;
        Q[i*3+1] = P[i*3+1]*(1-relax) + ay*relax;
        Q[i*3+2] = P[i*3+2]*(1-relax) + az*relax;
      }
      for(let i = 0; i < P.length; i++) P[i] = Q[i];
    }
  }
  /* Shade the cloth with the BODY's normals, not with its own.
     A cut leaves long thin triangles along the hem, and averaging their
     face normals points the edge sideways -- so every hem picked up a row
     of bright specks that look like z-fighting and are not. The body's
     normal at the same point is smooth, and a garment lying on a body
     should catch the light the way the body does. The boundary takes it
     almost entirely; the middle of the cloth keeps most of its own. */
  const FN = faceNormals(P, T);
  const out = new Float32Array(P.length);
  for(let i = 0; i < P.length; i += 3){
    const w = onEdgeV[i/3] ? 0.88 : 0.30;
    let x = FN[i]*(1-w) + N[i]*w;
    let y = FN[i+1]*(1-w) + N[i+1]*w;
    let z = FN[i+2]*(1-w) + N[i+2]*w;
    const L = Math.hypot(x,y,z) || 1;
    out[i]=x/L; out[i+1]=y/L; out[i+2]=z/L;
  }
  return { position: P, normal: Array.from(out), index: T, n: P.length/3 };
}

export function faceNormals(P, T){
  const N = new Float32Array(P.length);
  for(let f = 0; f < T.length; f += 3){
    const a=T[f]*3, b=T[f+1]*3, c=T[f+2]*3;
    const ux=P[b]-P[a], uy=P[b+1]-P[a+1], uz=P[b+2]-P[a+2];
    const wx=P[c]-P[a], wy=P[c+1]-P[a+1], wz=P[c+2]-P[a+2];
    const nx=uy*wz-uz*wy, ny=uz*wx-ux*wz, nz=ux*wy-uy*wx;
    N[a]+=nx; N[a+1]+=ny; N[a+2]+=nz;
    N[b]+=nx; N[b+1]+=ny; N[b+2]+=nz;
    N[c]+=nx; N[c+1]+=ny; N[c+2]+=nz;
  }
  for(let i = 0; i < N.length; i += 3){
    const L = Math.hypot(N[i],N[i+1],N[i+2]) || 1;
    N[i]/=L; N[i+1]/=L; N[i+2]/=L;
  }
  return Array.from(N);
}

export function bodyNormals(B, index){
  const N = new Float32Array(B.v.length);
  const v = B.v;
  for(let f = 0; f < index.length; f += 3){
    const a=index[f]*3, b=index[f+1]*3, c=index[f+2]*3;
    const ux=v[b]-v[a], uy=v[b+1]-v[a+1], uz=v[b+2]-v[a+2];
    const wx=v[c]-v[a], wy=v[c+1]-v[a+1], wz=v[c+2]-v[a+2];
    const nx=uy*wz-uz*wy, ny=uz*wx-ux*wz, nz=ux*wy-uy*wx;
    N[a]+=nx; N[a+1]+=ny; N[a+2]+=nz;
    N[b]+=nx; N[b+1]+=ny; N[b+2]+=nz;
    N[c]+=nx; N[c+1]+=ny; N[c+2]+=nz;
  }
  for(let i = 0; i < N.length; i += 3){
    const L = Math.hypot(N[i],N[i+1],N[i+2]) || 1;
    N[i]/=L; N[i+1]/=L; N[i+2]/=L;
  }
  return N;
}
