# The render spike

Not shipped. This is the test rig that was used to work out what in the
avatar is a lighting problem and what is an asset problem, by rendering
the same mesh three ways.

- `env.js` — a studio environment generated in the page rather than
  downloaded: a dark room, one big soft key panel, a cool fill and a
  bright strip behind, put through `PMREMGenerator`. Image-based lighting
  is the single biggest difference between a figure that reads as a 2005
  game asset and one that reads as a photograph. It costs nothing and
  needs no asset.
- `cine.html` — three modes on the same mesh:
  - `flat` — the lighting the pipeline shipped with: a hemisphere light
    and two directionals, matte everything.
  - `cine` — IBL, PBR materials, a three-point rig with a real shadow,
    ACES tone mapping, a portrait lens, and the baked skin maps.
  - `styl` — the same with the head scaled up 11% and the eyes 34%.

## What it established

1. Lighting and materials alone give a large lift and are days of work.
2. They cannot get past the asset ceiling. The face had **no eyebrows,
   no lash line and no lip colour** because the body is vertex-coloured
   and carries no texture at all.
3. The base mesh has had a full UV layout all along and the exporter was
   dropping it. That is what `skinmaps.py` paints into.
4. Hair is the remaining tell. A solid cap reads as a helmet whatever you
   do to its material — pushing the environment, clearcoat and sheen up
   just turned brown hair into chrome. It needs to be geometry.

## Running it

    python3 ../skinmaps.py 2048       # bakes skin_mask/warm/normal/rough
    python3 -m http.server 8101       # from this directory
    node go4.js                       # or open cine.html

`avatars.json` comes from `make_avatars.py` and must be built by a
`build_avatar.py` new enough to emit `uv`.
