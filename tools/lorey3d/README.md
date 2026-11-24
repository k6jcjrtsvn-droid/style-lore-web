# Lorey's head — how it is built

`web/assets/lorey3d-v1.bin` is not hand-modelled. It is generated from
MakeHuman's base mesh and morph targets, all of which were explicitly
released as CC0 in September 2020 by Data Collection AB, Joel Palmius and
Jonas Hauquier. Every downloaded file states that licence in its own
header; nothing here carries a licence we cannot show.

    sh fetch_assets.sh     # base.obj + 291 CC0 .target files (~6 MB)
    python3 build_head.py  # shape, paint, grow hair, bake morphs  -> build/headmesh.json
    python3 pack.py        # quantise                               -> build/assets/lorey3d.bin

Then copy the .bin to `web/assets/lorey3d-vN.bin` **under a new version
number** — the file is served immutable for a year, so the name is the
cache key — and update the two references in `web/assets/lorey3d.js` and
`web/index.html`, and mirror both into `mobile/www/assets/`.

## What each script does

- **build_head.py** — applies the macro targets (she is 55% caucasian,
  25% african, 20% asian, female, young) plus a dozen face targets that
  make her one particular person rather than the average of everybody.
  Cuts the mesh at y > 4.25 (head, neck and shoulders), paints vertex
  colours using the target files themselves as region masks (the lip
  vertices are the ones `mouth-upperlip-volume-incr` moves), adds a cheap
  cavity-based ambient occlusion, pulls the eyelashes, teeth and tongue
  out of the base mesh's helper groups, grows hair off the scalp, bakes
  the eyebrows onto the surface, and builds fourteen expression morphs.
- **hair.py** — grows a hair cap off the real scalp: selects the
  vertices above a hairline that dips at the temples and the nape,
  offsets them along their normals, finds the boundary loop and extrudes
  it into a fall. Three styles are generated; only `bob` ships.
- **morphs.py** — the three morphs that are not just a MakeHuman target:
  the jaw swinging about a hinge below the ear, the eyelid sweeping down
  to the lower rim, and the lips parting at the inner rim.
- **pack.py** — positions to Uint16 over the bounding box, colours to
  sRGB bytes, indices to Uint16, morph deltas to Int16 with a per-morph
  scale. 1.6 MB of JSON becomes 190 KB, 108 KB over the wire.

## Reading it back

`web/assets/lorey3d.js` is the whole renderer: ~18 KB, no three.js, no
CDN. It parses the binary, recomputes normals on the CPU whenever the
expression changes, and draws seven meshes with one shader.
