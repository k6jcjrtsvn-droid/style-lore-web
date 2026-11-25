# -*- coding: utf-8 -*-
"""The figure in 3D.

   A second view of the same figure, rendered from the shared base bundle
   rather than shipped per member. Everything heavy -- three.js, the
   bundle, the skin maps, about 3.7 MB together -- sits behind a dynamic
   import and is not fetched until somebody opens the view. The 2D figure
   is untouched and is still what the feed, the profile and Lorey use."""
import io, sys

P = sys.argv[1] if len(sys.argv) > 1 else 'index.html'
s = io.open(P, encoding='utf-8').read()
n0 = len(s); hits = []

def rep(old, new, label):
    global s
    if old not in s: raise SystemExit('MISS: ' + label)
    if s.count(old) != 1: raise SystemExit('AMBIGUOUS (%d): %s' % (s.count(old), label))
    s = s.replace(old, new); hits.append(label)

# ---- styles -----------------------------------------------------------
rep("""  .fig-edit-preview{ flex:0 0 auto;""",
"""  .fig3d-backdrop{ position:fixed; inset:0; z-index:90; background:#14161a;
    display:flex; flex-direction:column; }
  .fig3d-head{ display:flex; align-items:center; justify-content:space-between;
    padding:12px 14px; color:#eae6e0; }
  .fig3d-head h3{ margin:0; font-size:15px; font-weight:650; }
  .fig3d-stage{ flex:1; display:flex; align-items:center; justify-content:center;
    background:radial-gradient(120% 90% at 50% 18%, #222733 0%, #14161a 72%); }
  .fig3d-stage canvas{ display:block; touch-action:none; max-width:100%; height:auto; }
  .fig3d-note{ color:#9aa0a9; font-size:12px; text-align:center; padding:0 18px 16px; }
  .fig3d-btn{ font:inherit; font-size:13px; color:#eae6e0; background:#232832;
    border:1px solid #2e333c; border-radius:8px; padding:7px 12px; cursor:pointer; }
  .fig-edit-preview{ flex:0 0 auto;""", 'css')

# ---- the component ----------------------------------------------------
rep("""function FigureEditor({ figure, kibbeTypeId, palette, pieces, onChange, onSave, onClose, busy, error }){""",
"""/* The same figure, in 3D. Nothing here is downloaded until the view is
   opened: three.js, the base bundle and the skin maps come to about
   3.7 MB, and most people will never ask for them. */
function Figure3D({ figure, kibbeTypeId, onClose }){
  const hostRef = useRef(null);
  const viewRef = useRef(null);
  const [status, setStatus] = useState("loading");
  const [ms, setMs] = useState(0);
  useBackClose(onClose);

  useEffect(() => {
    let dead = false;
    const host = hostRef.current;
    if(!host) return;
    const canvas = document.createElement("canvas");
    const w = Math.min(window.innerWidth - 24, 420);
    const h = Math.min(Math.round(window.innerHeight * 0.62), 720);
    canvas.style.width = w + "px"; canvas.style.height = h + "px";
    host.appendChild(canvas);
    import("/assets/avatar/view.js").then(mod => {
      if(dead) return;
      return mod.mount(canvas, { width:w, height:h,
        assets:"/assets/avatar/", bundle:"/assets/avatar-base-v1.bin" });
    }).then(v => {
      if(dead || !v){ if(v) v.dispose(); return; }
      viewRef.current = v;
      setMs(Math.round(v.update(figure, kibbeTypeId)));
      setStatus("ok");
    }).catch(err => {
      if(dead) return;
      setStatus("error");
      // the 2D figure is still there; this view is the extra, not the floor
      try{ track("figure_3d_failed", { message: String(err && err.message || err).slice(0, 120) }); }catch(e){}
    });
    let dragging = false, lastX = 0;
    const down = ev => { dragging = true; lastX = ev.clientX;
                         canvas.setPointerCapture && canvas.setPointerCapture(ev.pointerId); };
    const move = ev => { if(!dragging || !viewRef.current) return;
                         viewRef.current.spin(ev.clientX - lastX); lastX = ev.clientX; };
    const up = () => { dragging = false; };
    canvas.addEventListener("pointerdown", down);
    canvas.addEventListener("pointermove", move);
    canvas.addEventListener("pointerup", up);
    canvas.addEventListener("pointercancel", up);
    return () => {
      dead = true;
      canvas.removeEventListener("pointerdown", down);
      canvas.removeEventListener("pointermove", move);
      canvas.removeEventListener("pointerup", up);
      canvas.removeEventListener("pointercancel", up);
      if(viewRef.current){ viewRef.current.dispose(); viewRef.current = null; }
      if(canvas.parentNode) canvas.parentNode.removeChild(canvas);
    };
  }, []);

  // rebuilding is ~150ms, so it can follow the sliders directly
  useEffect(() => {
    if(status !== "ok" || !viewRef.current) return;
    setMs(Math.round(viewRef.current.update(figure, kibbeTypeId)));
  }, [figure, kibbeTypeId, status]);

  return e("div", { className:"fig3d-backdrop", role:"dialog", "aria-modal":"true" },
    e("div", { className:"fig3d-head" },
      e("h3", null, "Your figure in 3D"),
      e("button", { type:"button", className:"fig3d-btn", onClick:onClose }, "Done")
    ),
    e("div", { className:"fig3d-stage", ref:hostRef }),
    e("p", { className:"fig3d-note" },
      status === "loading" ? "Building your figure\\u2026"
      : status === "error" ? "This device could not open the 3D view. Your figure is unchanged."
      : "Drag to turn her. Built in " + ms + " ms."
    )
  );
}

function FigureEditor({ figure, kibbeTypeId, palette, pieces, onChange, onSave, onClose, busy, error }){""", 'component')

# ---- the way in -------------------------------------------------------
rep("""  const [tab, setTab] = useState("skin");
  const fig = figure;""",
"""  const [tab, setTab] = useState("skin");
  const [show3d, setShow3d] = useState(false);
  const fig = figure;""", 'state')

rep("""        e("div", { className:"fig-edit-preview" },
          e(StyleFigure, { figure:fig, pieces:pieces, palette:palette, size:132,
                           label:"Your figure" })
        ),""",
"""        e("div", { className:"fig-edit-preview" },
          e(StyleFigure, { figure:fig, pieces:pieces, palette:palette, size:132,
                           label:"Your figure" }),
          e("button", { type:"button", className:"btn-text",
                        style:{ padding:"6px 0 0", width:"100%" },
                        onClick:() => setShow3d(true) }, "See her in 3D")
        ),""", 'button')

rep("""      error ? e("p", { className:"footnote", style:{ color:"var(--danger)" } }, error) : null,
      e("button", { className:"btn btn-accent btn-block", onClick:onSave, disabled:busy },
        busy ? "Saving\\u2026" : "Save figure")
    )
  );
}""",
"""      error ? e("p", { className:"footnote", style:{ color:"var(--danger)" } }, error) : null,
      e("button", { className:"btn btn-accent btn-block", onClick:onSave, disabled:busy },
        busy ? "Saving\\u2026" : "Save figure"),
      show3d ? e(Figure3D, { figure:fig, kibbeTypeId:kibbeTypeId,
                             onClose:() => setShow3d(false) }) : null
    )
  );
}""", 'mount')

io.open(P, 'w', encoding='utf-8').write(s)
print('%s %d -> %d (+%d)' % (P, n0, len(s), len(s) - n0))
print('applied:', ', '.join(hits))
