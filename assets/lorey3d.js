/* ==================================================================
   LOREY 3D  —  a small WebGL renderer for one head.

   The mesh is MakeHuman's CC0 base mesh, morphed and painted at build
   time; this file only draws it and moves her face. No dependencies.

   Lorey3D.create(canvas, { url }) -> Promise<stage>
     stage.setExpr({ blink, mouth, brow, gazeX, gazeY, tilt, lean, smile })
     stage.render()            draw one frame
     stage.dispose()
   ================================================================== */
(function(){
"use strict";

/* ---- 4x4 maths, column-major, the way WebGL wants it ---- */
function m4(){ return new Float32Array([1,0,0,0, 0,1,0,0, 0,0,1,0, 0,0,0,1]); }
function mul(a,b,o){
  o=o||m4();
  for(var c=0;c<4;c++) for(var r=0;r<4;r++){
    var s=0; for(var k=0;k<4;k++) s+=a[k*4+r]*b[c*4+k];
    o[c*4+r]=s;
  }
  return o;
}
function perspective(fovy,aspect,near,far){
  var f=1/Math.tan(fovy/2), o=m4();
  o[0]=f/aspect; o[5]=f; o[10]=(far+near)/(near-far); o[11]=-1;
  o[14]=2*far*near/(near-far); o[15]=0; return o;
}
function translate(x,y,z){ var o=m4(); o[12]=x; o[13]=y; o[14]=z; return o; }
function rotX(a){ var o=m4(),c=Math.cos(a),s=Math.sin(a); o[5]=c;o[6]=s;o[9]=-s;o[10]=c; return o; }
function rotY(a){ var o=m4(),c=Math.cos(a),s=Math.sin(a); o[0]=c;o[2]=-s;o[8]=s;o[10]=c; return o; }
function rotZ(a){ var o=m4(),c=Math.cos(a),s=Math.sin(a); o[0]=c;o[1]=s;o[4]=-s;o[5]=c; return o; }
function mat3of(m){ return new Float32Array([m[0],m[1],m[2], m[4],m[5],m[6], m[8],m[9],m[10]]); }

/* ---- shaders ---- */
var VS = [
"attribute vec3 aPos; attribute vec3 aNrm; attribute vec3 aCol; attribute vec2 aUV;",
"uniform mat4 uMVP, uModel; uniform mat3 uNrmMat;",
"varying vec3 vN, vW, vC; varying vec2 vUV;",
"void main(){",
"  vN = normalize(uNrmMat * aNrm);",
"  vW = (uModel * vec4(aPos,1.0)).xyz;",
"  vC = aCol; vUV = aUV;",
"  gl_Position = uMVP * vec4(aPos,1.0);",
"}"].join("\n");

var FS = [
"precision highp float;",
"varying vec3 vN, vW, vC; varying vec2 vUV;",
"uniform vec3 uBase, uEye, uSheen;",
"uniform float uUseVCol, uUseTex, uUnlit, uShine, uSpec, uRim;",
"uniform sampler2D uTex;",
"const vec3 L1=vec3(-0.55,0.52,0.65), L2=vec3(0.72,0.05,0.44), L3=vec3(0.26,0.42,-0.63);",
"const vec3 C1=vec3(1.00,0.94,0.87), C2=vec3(0.70,0.80,1.00), C3=vec3(1.00,0.98,0.96);",
"void main(){",
"  vec3 base = uBase;",
"  if(uUseVCol > 0.5) base = vC;",
"  if(uUseTex  > 0.5) base = pow(texture2D(uTex, vUV).rgb, vec3(2.2));",
"  if(uUnlit   > 0.5){ gl_FragColor = vec4(base,1.0); return; }",
"  vec3 N = normalize(vN); vec3 V = normalize(uEye - vW);",
"  if(!gl_FrontFacing) N = -N;",
"  vec3 d1=normalize(L1), d2=normalize(L2), d3=normalize(L3);",
"  float w1 = max(dot(N,d1),0.0);",
"  float w2 = max(dot(N,d2),0.0);",
"  float w3 = max(dot(N,d3),0.0);",
"  vec3 sky = mix(vec3(0.13,0.10,0.09), vec3(0.42,0.46,0.58), N.y*0.5+0.5);",
"  vec3 lit = base * (sky*0.42 + C1*w1*1.05 + C2*w2*0.30 + C3*w3*0.34);",
"  float sp = 0.0;",
"  sp += pow(max(dot(N, normalize(d1+V)),0.0), uShine);",
"  sp += 0.35*pow(max(dot(N, normalize(d3+V)),0.0), uShine);",
"  lit += uSpec * sp * vec3(1.0,0.97,0.94);",
"  float fres = pow(1.0 - max(dot(N,V),0.0), 3.0);",
"  lit += uRim * fres * uSheen;",
"  lit = lit / (lit + vec3(0.92)) * 1.66;",            // a gentle filmic shoulder
"  gl_FragColor = vec4(pow(clamp(lit,0.0,1.0), vec3(1.0/2.2)), 1.0);",
"}"].join("\n");

function compile(gl,src,type){
  var s=gl.createShader(type); gl.shaderSource(s,src); gl.compileShader(s);
  if(!gl.getShaderParameter(s,gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(s));
  return s;
}

/* ---- the packed mesh ---- */
function parse(buf){
  var dv=new DataView(buf);
  if(String.fromCharCode(dv.getUint8(0),dv.getUint8(1),dv.getUint8(2),dv.getUint8(3))!=="LOR3")
    throw new Error("not a lorey3d file");
  var hl=dv.getUint32(4,true);
  var head=JSON.parse(new TextDecoder().decode(new Uint8Array(buf,8,hl)));
  var base=8+hl;
  head._blob=buf; head._base=base;
  return head;
}
function unpackPos(H,e){
  var q=new Uint16Array(H._blob, H._base+e.pos[0], e.n*3);
  var out=new Float32Array(e.n*3);
  for(var i=0;i<e.n;i++) for(var k=0;k<3;k++)
    out[i*3+k] = e.min[k] + q[i*3+k]/65535*e.scale[k];
  return out;
}
function unpackIdx(H,e){
  return e.isz===2 ? new Uint16Array(H._blob, H._base+e.idx[0], e.ni)
                   : new Uint32Array(H._blob, H._base+e.idx[0], e.ni);
}
function unpackCol(H,e){
  var b=new Uint8Array(H._blob, H._base+e.col[0], e.n*3);
  var out=new Float32Array(e.n*3);
  for(var i=0;i<out.length;i++){ var u=b[i]/255;
    out[i] = u<=0.04045 ? u/12.92 : Math.pow((u+0.055)/1.055, 2.4); }
  return out;
}
function unpackMorph(H,m){
  return { i:new Uint16Array(H._blob, H._base+m.i[0], m.n),
           d:new Int16Array(H._blob, H._base+m.d[0], m.n*3), s:m.s, n:m.n };
}

/* ---- normals from scratch ---- */
function normals(pos,idx,out){
  out=out||new Float32Array(pos.length);
  out.fill(0);
  for(var t=0;t<idx.length;t+=3){
    var a=idx[t]*3,b=idx[t+1]*3,c=idx[t+2]*3;
    var ux=pos[b]-pos[a], uy=pos[b+1]-pos[a+1], uz=pos[b+2]-pos[a+2];
    var vx=pos[c]-pos[a], vy=pos[c+1]-pos[a+1], vz=pos[c+2]-pos[a+2];
    var nx=uy*vz-uz*vy, ny=uz*vx-ux*vz, nz=ux*vy-uy*vx;
    out[a]+=nx; out[a+1]+=ny; out[a+2]+=nz;
    out[b]+=nx; out[b+1]+=ny; out[b+2]+=nz;
    out[c]+=nx; out[c+1]+=ny; out[c+2]+=nz;
  }
  for(var i=0;i<out.length;i+=3){
    var L=Math.sqrt(out[i]*out[i]+out[i+1]*out[i+1]+out[i+2]*out[i+2])||1;
    out[i]/=L; out[i+1]/=L; out[i+2]/=L;
  }
  return out;
}

/* ---- a UV sphere, for the eyeballs and the dark of the mouth ---- */
function sphere(r,seg,ring){
  var pos=[],nrm=[],uv=[],idx=[];
  for(var y=0;y<=ring;y++){
    var v=y/ring, th=v*Math.PI, sy=Math.cos(th), sr=Math.sin(th);
    for(var x=0;x<=seg;x++){
      var u=x/seg, ph=u*Math.PI*2;
      var px=-Math.cos(ph)*sr, py=sy, pz=Math.sin(ph)*sr;
      pos.push(px*r,py*r,pz*r); nrm.push(px,py,pz); uv.push(u,1-v);
    }
  }
  for(var y2=0;y2<ring;y2++) for(var x2=0;x2<seg;x2++){
    var a=y2*(seg+1)+x2, b=a+seg+1;
    idx.push(a,b,a+1, b,b+1,a+1);
  }
  return { position:new Float32Array(pos), normal:new Float32Array(nrm),
           uv:new Float32Array(uv), index:new Uint16Array(idx), n:pos.length/3 };
}

/* ---- the iris, drawn once onto a canvas ---- */
function irisTexture(c0,c1,c2){
  var c=document.createElement("canvas"); c.width=c.height=512;
  var x=c.getContext("2d"), i;
  x.fillStyle="#f6f3f0"; x.fillRect(0,0,512,512);
  x.strokeStyle="rgba(190,115,105,.24)"; x.lineWidth=1.5;
  for(i=0;i<34;i++){ var a=Math.random()*6.283, r0=150+Math.random()*60;
    x.beginPath(); x.moveTo(128+Math.cos(a)*r0,256+Math.sin(a)*r0);
    x.lineTo(128+Math.cos(a+.15)*(r0+120),256+Math.sin(a+.15)*(r0+120)); x.stroke(); }
  var cx=128, cy=256, R=78;
  var g=x.createRadialGradient(cx,cy,4,cx,cy,R);
  g.addColorStop(0,"#000"); g.addColorStop(.30,"#000");
  g.addColorStop(.345,c0); g.addColorStop(.72,c1); g.addColorStop(1,c2);
  x.fillStyle=g; x.beginPath(); x.arc(cx,cy,R,0,7); x.fill();
  x.save(); x.beginPath(); x.arc(cx,cy,R,0,7); x.clip();
  for(i=0;i<240;i++){ var b=Math.random()*6.283;
    x.strokeStyle="rgba(255,255,255,"+(.03+Math.random()*.12)+")"; x.lineWidth=.8+Math.random();
    x.beginPath(); x.moveTo(cx+Math.cos(b)*R*.34,cy+Math.sin(b)*R*.34);
    x.lineTo(cx+Math.cos(b)*R*(.74+Math.random()*.26),cy+Math.sin(b)*R*(.74+Math.random()*.26)); x.stroke(); }
  x.restore();
  x.strokeStyle="rgba(26,14,10,.6)"; x.lineWidth=6; x.beginPath(); x.arc(cx,cy,R-2,0,7); x.stroke();
  return c;
}

window.LoreyMesh = {
  available: function(){
    try {
      var c=document.createElement("canvas");
      return !!(window.WebGLRenderingContext &&
                (c.getContext("webgl")||c.getContext("experimental-webgl")));
    } catch(e){ return false; }
  },
  create: function(canvas, opts){
    opts=opts||{};
    return fetch(opts.url || "assets/lorey3d-v1.bin", { cache:"force-cache" })
      .then(function(r){ if(!r.ok) throw new Error("mesh "+r.status); return r.arrayBuffer(); })
      .then(function(buf){ return build(canvas, parse(buf), opts); });
  }
};

function build(canvas, H, opts){
  var gl = canvas.getContext("webgl",{alpha:true,antialias:true,premultipliedAlpha:false})
        || canvas.getContext("experimental-webgl",{alpha:true,antialias:true});
  if(!gl) throw new Error("no webgl");

  var prog=gl.createProgram();
  gl.attachShader(prog,compile(gl,VS,gl.VERTEX_SHADER));
  gl.attachShader(prog,compile(gl,FS,gl.FRAGMENT_SHADER));
  gl.linkProgram(prog);
  if(!gl.getProgramParameter(prog,gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(prog));
  gl.useProgram(prog);

  var A={}, U={};
  ["aPos","aNrm","aCol","aUV"].forEach(function(k){ A[k]=gl.getAttribLocation(prog,k); });
  ["uMVP","uModel","uNrmMat","uBase","uEye","uSheen","uUseVCol","uUseTex","uUnlit",
   "uShine","uSpec","uRim","uTex"].forEach(function(k){ U[k]=gl.getUniformLocation(prog,k); });

  function buffers(d){
    var o={ n:d.n, ni:d.index.length };
    o.pos=gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER,o.pos);
    gl.bufferData(gl.ARRAY_BUFFER,d.position,d.dynamic?gl.DYNAMIC_DRAW:gl.STATIC_DRAW);
    o.nrm=gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER,o.nrm);
    gl.bufferData(gl.ARRAY_BUFFER,d.normal,d.dynamic?gl.DYNAMIC_DRAW:gl.STATIC_DRAW);
    if(d.color){ o.col=gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER,o.col);
      gl.bufferData(gl.ARRAY_BUFFER,d.color,gl.STATIC_DRAW); }
    if(d.uv){ o.uv=gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER,o.uv);
      gl.bufferData(gl.ARRAY_BUFFER,d.uv,gl.STATIC_DRAW); }
    o.idx=gl.createBuffer(); gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER,o.idx);
    gl.bufferData(gl.ELEMENT_ARRAY_BUFFER,d.index,gl.STATIC_DRAW);
    o.u32 = d.index instanceof Uint32Array;
    return o;
  }

  function part(name,color){
    var e=H.parts[name]; if(!e) return null;
    var d={ n:e.n, position:unpackPos(H,e), index:unpackIdx(H,e) };
    d.color = (color && e.col) ? unpackCol(H,e) : null;
    d.normal = normals(d.position,d.index);
    return d;
  }

  var head=part("head",true); head.dynamic=true;
  var HEAD_BASE=new Float32Array(head.position);
  var HEAD_NRM =new Float32Array(head.normal.length);
  var MESH={ head:head };
  ["lashes","teethU","teethL","tongue","hair","browL","browR"].forEach(function(k){
    var p=part(k,false); if(p) MESH[k]=p;
  });
  var BUF={}; for(var k in MESH) BUF[k]=buffers(MESH[k]);

  var MORPH={}; for(var mk in H.morphs) MORPH[mk]=unpackMorph(H,H.morphs[mk]);

  /* eyes */
  var eyeGeo=sphere(1,40,30);
  var eyeBuf=buffers(eyeGeo);
  var tex=gl.createTexture();
  gl.bindTexture(gl.TEXTURE_2D,tex);
  gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL,true);
  gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,gl.RGBA,gl.UNSIGNED_BYTE,
                irisTexture(opts.iris0||"#3f5f4c",opts.iris1||"#76906a",opts.iris2||"#b9a97e"));
  gl.generateMipmap(gl.TEXTURE_2D);
  gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.LINEAR_MIPMAP_LINEAR);
  gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_S,gl.CLAMP_TO_EDGE);
  gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_T,gl.CLAMP_TO_EDGE);

  /* the dark of the mouth */
  var cavGeo=sphere(1,18,14), cavBuf=buffers(cavGeo);

  /* framing: the head above the chin fills the canvas */
  var P=HEAD_BASE, yTop=H.bounds.yTop, chin=yTop-2.55;
  var mn=[1e9,1e9,1e9], mx=[-1e9,-1e9,-1e9];
  for(var i=0;i<P.length;i+=3){ if(P[i+1]<chin) continue;
    for(var c3=0;c3<3;c3++){ var v=P[i+c3]; if(v<mn[c3])mn[c3]=v; if(v>mx[c3])mx[c3]=v; } }
  var centre=[(mn[0]+mx[0])/2,(mn[1]+mx[1])/2,(mn[2]+mx[2])/2], faceH=mx[1]-mn[1];
  var FOV=26*Math.PI/180;
  var DIST=(faceH*0.60)/Math.tan(FOV/2)*1.02;
  var recentre=translate(-centre[0],-centre[1]+faceH*0.055,-centre[2]);

  var EXPR={}, dirty=true;
  function applyMorphs(){
    var pos=head.position; pos.set(HEAD_BASE);
    for(var k in MORPH){
      var a=EXPR[k]; if(!a) continue;
      var m=MORPH[k], I=m.i, D=m.d, s=m.s*a;
      for(var n=0;n<m.n;n++){
        var o=I[n]*3, q=n*3;
        pos[o]  += D[q]  *s;
        pos[o+1]+= D[q+1]*s;
        pos[o+2]+= D[q+2]*s;
      }
    }
    normals(pos,head.index,HEAD_NRM);
    gl.bindBuffer(gl.ARRAY_BUFFER,BUF.head.pos); gl.bufferSubData(gl.ARRAY_BUFFER,0,pos);
    gl.bindBuffer(gl.ARRAY_BUFFER,BUF.head.nrm); gl.bufferSubData(gl.ARRAY_BUFFER,0,HEAD_NRM);
  }

  function bind(b,hasCol,hasUV){
    gl.bindBuffer(gl.ARRAY_BUFFER,b.pos);
    gl.enableVertexAttribArray(A.aPos); gl.vertexAttribPointer(A.aPos,3,gl.FLOAT,false,0,0);
    gl.bindBuffer(gl.ARRAY_BUFFER,b.nrm);
    gl.enableVertexAttribArray(A.aNrm); gl.vertexAttribPointer(A.aNrm,3,gl.FLOAT,false,0,0);
    if(hasCol && b.col){ gl.bindBuffer(gl.ARRAY_BUFFER,b.col);
      gl.enableVertexAttribArray(A.aCol); gl.vertexAttribPointer(A.aCol,3,gl.FLOAT,false,0,0); }
    else { gl.disableVertexAttribArray(A.aCol); gl.vertexAttrib3f(A.aCol,1,1,1); }
    if(hasUV && b.uv){ gl.bindBuffer(gl.ARRAY_BUFFER,b.uv);
      gl.enableVertexAttribArray(A.aUV); gl.vertexAttribPointer(A.aUV,2,gl.FLOAT,false,0,0); }
    else { gl.disableVertexAttribArray(A.aUV); gl.vertexAttrib2f(A.aUV,0,0); }
    gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER,b.idx);
  }

  var MAT={
    skin  : { vcol:1, shine:34,  spec:0.075, rim:0.050, sheen:[1.00,0.48,0.38] },
    hair  : { base:[0.040,0.024,0.019], shine:140, spec:0.13, rim:0.045, sheen:[0.60,0.36,0.19] },
    lash  : { base:[0.012,0.008,0.007], shine:18, spec:0.05, rim:0.02, sheen:[0.4,0.3,0.3] },
    brow  : { base:[0.040,0.023,0.016], shine:28, spec:0.02, rim:0.012, sheen:[0.4,0.3,0.3] },
    tooth : { base:[0.800,0.770,0.720], shine:60, spec:0.20, rim:0.04, sheen:[1,1,1] },
    tongue: { base:[0.210,0.072,0.078], shine:22, spec:0.14, rim:0.04, sheen:[0.8,0.4,0.4] },
    eye   : { tex:1,  shine:90, spec:0.55, rim:0.07, sheen:[1,1,1] }
  };
  function setMat(m){
    gl.uniform1f(U.uUseVCol, m.vcol?1:0);
    gl.uniform1f(U.uUseTex,  m.tex?1:0);
    gl.uniform1f(U.uUnlit,   m.unlit?1:0);
    gl.uniform3fv(U.uBase,   m.base||[1,1,1]);
    gl.uniform3fv(U.uSheen,  m.sheen||[1,1,1]);
    gl.uniform1f(U.uShine,   m.shine||20);
    gl.uniform1f(U.uSpec,    m.spec||0);
    gl.uniform1f(U.uRim,     m.rim||0);
  }

  var stage={
    canvas:canvas, gl:gl,
    setExpr:function(e){
      e=e||{};
      if(!dirty){                       /* only re-morph when the face changed */
        for(var k in MORPH){
          if(Math.abs((e[k]||0)-(EXPR[k]||0)) > 0.002){ dirty=true; break; }
        }
      }
      EXPR=e;
    },
    resize:function(w,h,dpr){
      dpr=dpr||Math.min(2,window.devicePixelRatio||1);
      canvas.width=Math.round(w*dpr); canvas.height=Math.round(h*dpr);
      canvas.style.width=w+"px"; canvas.style.height=h+"px";
    },
    render:function(){
      if(dirty){ applyMorphs(); dirty=false; }
      var w=canvas.width,h=canvas.height;
      gl.viewport(0,0,w,h);
      gl.enable(gl.DEPTH_TEST); gl.depthFunc(gl.LEQUAL);
      gl.enable(gl.CULL_FACE); gl.cullFace(gl.BACK);
      gl.clearColor(0,0,0,0); gl.clear(gl.COLOR_BUFFER_BIT|gl.DEPTH_BUFFER_BIT);

      var proj=perspective(FOV,w/h,0.1,60);
      var view=translate(0,0,-DIST);
      var yaw=(EXPR.gazeX||0)*0.10 + (EXPR.lean||0)*0.12 + (EXPR.yaw||0);
      var pit=(EXPR.gazeY||0)*0.05 + (EXPR.pitch||0);
      var til=(EXPR.tilt||0)*0.16;
      var spin=mul(mul(rotY(yaw),rotX(pit)),rotZ(til));
      var model=mul(spin,recentre);
      var vp=mul(proj,view);
      var mvp=mul(vp,model);
      gl.uniformMatrix4fv(U.uMVP,false,mvp);
      gl.uniformMatrix4fv(U.uModel,false,model);
      gl.uniformMatrix3fv(U.uNrmMat,false,mat3of(model));
      gl.uniform3f(U.uEye,0,0,DIST);

      /* the dark of the mouth, drawn from the inside */
      setMat({ unlit:1, base:[0.050,0.016,0.019] });
      var cm=mul(model, mul(translate(0,6.14,0.88), (function(){ var s=m4(); s[0]=0.41;s[5]=0.34;s[10]=0.31; return s; })()));
      gl.uniformMatrix4fv(U.uMVP,false,mul(vp,cm));
      gl.uniformMatrix4fv(U.uModel,false,cm);
      gl.cullFace(gl.FRONT);
      bind(cavBuf,false,false);
      gl.drawElements(gl.TRIANGLES,cavGeo.index.length,gl.UNSIGNED_SHORT,0);
      gl.cullFace(gl.BACK);

      /* the jaw parts swing with the chin */
      var jo=EXPR.jawOpen||0, HG=H.hinge;
      var jaw=mul(model, mul(translate(HG[0],HG[1],HG[2]),
                mul(rotX(0.26*jo), translate(-HG[0],-HG[1],-HG[2]))));

      function draw(name,mat,mat4m,count){
        var b=BUF[name]; if(!b) return;
        var mm=mat4m||model;
        gl.uniformMatrix4fv(U.uMVP,false,mul(vp,mm));
        gl.uniformMatrix4fv(U.uModel,false,mm);
        gl.uniformMatrix3fv(U.uNrmMat,false,mat3of(mm));
        setMat(mat);
        bind(b,!!mat.vcol,false);
        gl.drawElements(gl.TRIANGLES,b.ni,b.u32?gl.UNSIGNED_INT:gl.UNSIGNED_SHORT,0);
      }

      draw("teethU",MAT.tooth);
      draw("teethL",MAT.tooth,jaw);
      draw("tongue",MAT.tongue,jaw);

      /* eyeballs */
      gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D,tex);
      gl.uniform1i(U.uTex,0);
      setMat(MAT.eye);
      bind(eyeBuf,false,true);
      [H.eyeL,H.eyeR].forEach(function(E){
        var s=m4(); s[0]=s[5]=s[10]=E.r*0.96;
        var em=mul(model, mul(translate(E.c[0],E.c[1],E.c[2]-E.r*0.26),
                  mul(mul(rotY((EXPR.gazeX||0)*0.42), rotX(-(EXPR.gazeY||0)*0.34)), s)));
        gl.uniformMatrix4fv(U.uMVP,false,mul(vp,em));
        gl.uniformMatrix4fv(U.uModel,false,em);
        gl.uniformMatrix3fv(U.uNrmMat,false,mat3of(em));
        gl.drawElements(gl.TRIANGLES,eyeGeo.index.length,gl.UNSIGNED_SHORT,0);
      });

      /* skin, then the hair and the small dark bits on top */
      gl.disable(gl.CULL_FACE);
      draw("head",MAT.skin);
      draw("lashes",MAT.lash);
      var bu=(EXPR.browUp||0)-(EXPR.browDown||0), ba=EXPR.browAngle||0;
      draw("browL",MAT.brow, mul(model,translate(0,bu*0.085,0)));
      draw("browR",MAT.brow, mul(model,translate(0,bu*0.085,0)));
      draw("hair",MAT.hair);
      gl.enable(gl.CULL_FACE);
    },
    dispose:function(){
      var e=gl.getExtension("WEBGL_lose_context"); if(e) e.loseContext();
    }
  };
  stage.setExpr({});
  return stage;
}
})();
