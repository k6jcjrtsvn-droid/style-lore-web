# -*- coding: utf-8 -*-
"""Turn the figure with a finger, and let the sliders reach it.

   The 3D view was built from the Kibbe type alone, so a member could
   move twelve body sliders and fifteen face sliders and watch nothing
   happen. It now takes the whole figure. And the drag carries both axes,
   so the figure turns all the way round and tips a little."""
import io, sys

P = sys.argv[1] if len(sys.argv) > 1 else 'index.html'
s = io.open(P, encoding='utf-8').read()
n0 = len(s); hits = []

def rep(old, new, label):
    global s
    if old not in s: raise SystemExit('MISS: ' + label)
    if s.count(old) != 1: raise SystemExit('AMBIGUOUS (%d): %s' % (s.count(old), label))
    s = s.replace(old, new); hits.append(label)

rep("""    const move = ev => { if(!dragging || !viewRef.current) return;
                         viewRef.current.spin(ev.clientX - lastX); lastX = ev.clientX; };
    const up = () => { dragging = false; };""",
"""    const move = ev => { if(!dragging || !viewRef.current) return;
                         viewRef.current.spin(ev.clientX - lastX, ev.clientY - lastY);
                         lastX = ev.clientX; lastY = ev.clientY;
                         if(ev.cancelable) ev.preventDefault(); };
    const up = () => { dragging = false; };""", 'move')

rep("""    let dragging = false, lastX = 0;
    const down = ev => { dragging = true; lastX = ev.clientX;
                         canvas.setPointerCapture && canvas.setPointerCapture(ev.pointerId); };""",
"""    let dragging = false, lastX = 0, lastY = 0;
    const down = ev => { dragging = true; lastX = ev.clientX; lastY = ev.clientY;
                         canvas.setPointerCapture && canvas.setPointerCapture(ev.pointerId); };""", 'down')

rep("""    canvas.addEventListener("pointerdown", down);
    canvas.addEventListener("pointermove", move);""",
"""    canvas.addEventListener("pointerdown", down);
    // passive:false so the drag can stop the page scrolling under it on a
    // touch screen -- without it the figure turns and the page moves too
    canvas.addEventListener("pointermove", move, { passive: false });""", 'listener')

rep("""      status === "loading" ? "Building your figure\\u2026"
      : status === "error" ? "This device could not open the 3D view. Your figure is unchanged."
      : "Drag to turn her. Built in " + ms + " ms."
    )""",
"""      status === "loading" ? "Building your figure\\u2026"
      : status === "error" ? "This device could not open the 3D view. Your figure is unchanged."
      : "Drag to turn her all the way round. Built in " + ms + " ms."
    )""", 'hint')

io.open(P, 'w', encoding='utf-8').write(s)
print('%s %d -> %d (+%d)' % (P, n0, len(s), len(s) - n0))
print('applied:', ', '.join(hits))
