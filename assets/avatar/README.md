# The avatar runtime

A port of the Python pipeline to the browser, so a member's avatar can be
rebuilt from **about 300 bytes** instead of being shipped as 565 KB of baked
mesh.

| file | what it is |
|---|---|
| `avatar.js` | loads `avatar-base-v1.bin`, applies morph weights, stylises the head |
| `body.js` | `anat.py` and `cutter.py`: measurements, posing, isoline garment cutting |
| `wardrobe.js` | `wardrobe2.py` and `footwear.py`: tops, bottoms, skirts, eight shoe styles |
| `hairgen.js` | `hairgen.py`: the scalp cut and the hair cards |

## What is deliberately NOT ported

The landmark scan. `anat.reference()` measures the body once and keeps its
landmarks as **vertex indices**, precisely because an extreme morph target
moves the geometry enough that scanning again latches the armpit onto a hip.
Those indices are the same for every member, so they are baked into the bundle
and read here. The browser never runs the scan — which also means the build and
the browser cannot disagree about where the shoulder is.

They did disagree, at first. The bundle measured the raw base mesh; `build()`
measures a neutral ethnic blend through `ensure_reference()`. The raw mesh is
an androgynous mannequin and its armpit sits somewhere else entirely — 4,745
arm vertices against 5,080 — so the browser's arms did not swing as far and the
sleeve was cut in the wrong place. The bundle now calls the same function.

## Determinism

Hair is random, and the same hair has to come out of the build and out of the
browser: the member's own device renders the cached portrait everyone else
sees, so hair that differs between the two is hair that changes when you look
at it. Python's Mersenne Twister and the browser's `Math.random` agree about
nothing, so both sides use the explicit xorshift in `hairgen.py` / `hairgen.js`.
They produce identical streams.

## Verified against the build

`tools/verify.html` builds a figure in the browser and the same spec in Python:

| part | Python | browser |
|---|---|---|
| body | 13,380 v / 26,756 t | 13,380 / 26,756 |
| bottom | 1,518 / 2,867 | identical |
| shoes | 1,084 / 2,160 | identical |
| top | 2,270 / 4,116 | 2,265 / 4,110 |
| hair | 16,770 / 15,262 | 16,844 / 15,336 |

Bottoms and shoes are vertex-for-vertex identical. The top differs by five
vertices and the hair by 0.4%, both because 16-bit position quantisation nudges
a few vertices across a cut threshold. Body positions agree to 0.0034 units on
a figure 16 units tall.

**124 ms** to build a whole avatar in the browser: morph 13, pose 9, clothes 70,
hair 33.
