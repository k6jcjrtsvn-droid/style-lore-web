# Member avatars in 3D — the pipeline

Same CC0 MakeHuman base mesh and morph targets as Lorey's head
(`web/tools/lorey3d/`), extended to a whole dressed body. Run
`web/tools/lorey3d/fetch_assets.sh` first for `base.obj`, then also fetch the
body groups (torso, hip, stomach, buttocks, pelvis, armslegs, breast) the same
way — 469 targets in all, every one of them CC0.

    python3 make_avatars.py     # five test figures -> build/avatars.json

## The pieces

- **anat.py** — the landmarks everything else is expressed in. Raw coordinates
  are useless here because every morph target moves them, so each one is
  *measured* on the figure in front of you:
  - the **armpit** is the biggest gap in the |x| distribution at shoulder
    height, scanned over a range of heights rather than assumed at one;
  - the **fingertip** is the farthest vertex from the shoulder *above the
    hip* — a knee is farther from the shoulder than a hand is, and when the
    search found one the arm axis pointed at the floor and nothing rotated;
  - the **neck** is the narrowest slice between shoulder and jaw, bounded, or
    it finds the top of the skull and the collar climbs over her mouth.
- **wardrobe.py** — clothes as shells grown off the body's own surface. A
  fitted garment *is* the body surface held a few millimetres out, so a wide
  hip pushes the skirt out because the skirt is the hip, moved. Garments cost
  a face list to store and fit any body automatically. Boots are the
  exception: a shoe is a last, not a foot with a coat of paint.
- **build_avatar.py** — assembles body, skin colouring (lips, blush, lash
  line, cavity AO, dark scalp under the hair), eyes, lashes, hair, clothes.
- **hair.py** — shared with Lorey.

## Hard-won

1. **Posing and dressing need different definitions of "arm".** Posing wants a
   wide, soft one so the deformation has no cliff in it. Clothing wants a
   narrow, hard one, or a shirt rejects half the chest for being within two
   units of the arm's axis. Same joint, two questions.
2. **A deformation field must be continuous in every direction.** A hard
   radius cut meant a vertex just inside the arm rotated fully while its
   neighbour just outside did not move at all; the faces spanning that line
   stretched into a funnel that showed through any clothing built on top.
   49 stretched faces posed, 0 unposed — that comparison found it.
3. **Cut sleeves in posed space.** Distance along the arm is measured in the
   rest pose, where the arm points down and forward at fifty degrees. Once it
   swings to the side, a surface of constant distance is no longer a ring
   round the arm but a diagonal slash across the chest. With the arm vertical,
   the cuff is simply a height.
4. **Never normalise a vector by a substituted length.** Clamping the arm
   length to a plausible value, then dividing the raw direction by the clamp,
   produced a non-unit "axis" pointing straight down.
5. **Judge a garment face by its centroid, not by all its vertices.** The
   all-vertex rule sounds safer and is not: a face bridging chest and sleeve
   has vertices on both sides of every boundary, so it gets dropped, and the
   garment ends up notched at exactly the seam a real shirt is made of.

## Kibbe drives the starting shape

`kibbe.py`. The app already models a Kibbe result as a blend over five
families plus a yin/yang modifier (`KIBBE_TARGETS` in index.html), so the body
is built the same way rather than as thirteen hand-made presets: every type is
covered, blends between them behave sensibly, and a new type costs nothing.
The avatar cannot disagree with the quiz, because it is reading the quiz's own
numbers.

Measured across the thirteen, the system behaves: Dramatic is tallest (16.9)
with the narrowest hip; Gamine is shortest (15.5); Natural has the widest
shoulder; Romantic the fullest hip; Classic sits in the middle of all of them.

**Render every avatar at one fixed scale.** Framing each figure to fill its
canvas hides the single most important thing the read is telling you — how
long the vertical line is — by normalising height away.

## Landmarks are vertex indices, not measurements

This is the thing that took four separate bugs to learn. Every landmark used
to be re-measured on each morphed body, and an extreme target would move the
geometry enough that the armpit scan latched onto a hip, or the fingertip
search onto a knee, and then the arm axis pointed at the floor and the figure
tore itself apart.

The armpit is the *same vertex* on every body — only its position moves. So
`anat.reference()` measures the neutral mesh once and stores vertex indices;
every other body looks them up. That removed the whole class of failure at a
stroke, and all thirteen Kibbe types went from one-in-thirteen catastrophic to
thirteen-for-thirteen clean.

## Not done

- Ragged hems and a small shoulder notch: boundaries still follow face
  centroids, so they are stepped. They want a proper cut-and-cap.
- Hair is a helmet with flat side panels. It needs strands or at least shells.
- Shoes read as buckets; the last needs a heel and a shaped topline.
- Skirts are cones; they want a waistband and a softer fall.
- **Delivery.** 13,380 verts per body is far too much to ship per member. One
  shared base mesh plus per-member morph weights (a few dozen floats), with
  garments derived at runtime from face lists, and a cached PNG anywhere the
  avatar is only scrolled past.
