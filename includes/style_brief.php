<?php
/**
 * The house style system, in a form a model can be handed.
 *
 * Every AI surface in this app (the photo Stylist, the outfit notes) used to
 * be told only the NAME of the person's Kibbe type — "Flamboyant Gamine" and
 * nothing else — and left to fill in the rest from whatever it had absorbed
 * about Kibbe from the internet. That is the worst possible input for this
 * particular system: Kibbe is widely written about and widely written about
 * WRONGLY, the thirteen types get conflated constantly, and the advice that
 * came back could contradict the app's own type pages word for word. A
 * person paying for a stylist should not get one answer on /types and a
 * different one from the AI.
 *
 * So the guidance below is the same editorial that generates web/types/*.html
 * (see gen_types.py), served to the model as the authority. It also carries
 * the colour season, which no AI surface had at all, and which is half of
 * what "does this suit me" means.
 *
 * Keep this file and gen_types.py's G table in step. If they drift, the type
 * pages and the stylist start disagreeing again, which is the exact thing
 * this file exists to prevent.
 */

/** id => [name, tag, works[], avoid[], mixup] — verbatim from gen_types.py. */
const KIBBE_GUIDE = [
 'dramatic' => ['Dramatic', 'Long lines, sharp edges, big statements.',
  ["Long, unbroken vertical lines: a column dress, a floor-length coat, a single-breasted suit worn as one piece.",
   "Crisp, structured fabrics with some weight - gabardine, heavy crepe, leather, dense wool - that hold a sharp edge.",
   "One large, bold detail rather than many small ones: an oversized lapel, a single statement earring, a wide belt.",
   "Sharp geometry in accessories: pointed toes, angular bags, long straight pendants."],
  ["Small, fussy details - ruffles, tiny prints, delicate jewellery - get lost on a long, sharp frame.",
   "Soft, clingy or overly draped fabrics blur the lines that are the strongest feature.",
   "Anything that chops the vertical: wide contrasting belts at the natural waist, cropped-and-boxy combinations."],
  "Soft Dramatic - both are long and striking, but Soft Dramatic carries visible curve and wants fluid fabrics; pure Dramatic wants the fabric to stand up on its own."],
 'soft-dramatic' => ['Soft Dramatic', 'Sharp lines, softened by curve.',
  ["Long lines built in liquid fabrics: bias-cut silk, matte jersey, velvet, satin that pools and drapes.",
   "Draped detail that follows the body - a cowl neck, a wrap dress, a sarong skirt, a plunging neckline.",
   "Glamour at scale: large jewellery, a dramatic sleeve, a bold print that is big rather than busy.",
   "Waist emphasis with softness - a sash or a soft belt rather than a rigid one."],
  ["Stiff, boxy tailoring with no waist reads heavy on a curvy, elongated frame.",
   "Tiny, dainty details disappear; a delicate chain does nothing here.",
   "Very short hemlines and cropped jackets that cut the long line."],
  "Theatrical Romantic - both are curvy and glamorous, but Theatrical Romantic is petite and compact; Soft Dramatic is long and needs scale."],
 'flamboyant-natural' => ['Flamboyant Natural', 'Broad-boned, elongated, easy in scale.',
  ["Relaxed, unconstructed tailoring: an oversized blazer, a long trench, a boyfriend coat.",
   "Natural textures with body - linen, suede, chunky knit, raw denim, tweed.",
   "Long, easy layers that drape rather than cinch; asymmetry and undone details.",
   "Chunky, organic accessories: a wide leather cuff, a big slouchy bag, a flat wide-brim hat."],
  ["Anything fitted through the shoulder and rigid - a sharp, tight jacket looks borrowed.",
   "Symmetry and precision: matching sets, tiny neat details, delicate jewellery.",
   "Short, fussy hemlines and anything that reads 'dainty'."],
  "Dramatic - both are long, but Dramatic wants sharp and structured; Flamboyant Natural wants relaxed and broad."],
 'natural' => ['Natural', 'Blunt, broad, unfussy.',
  ["Clean, easy shapes with a relaxed fit: a straight coat, a boxy jacket, a simple shift.",
   "Matte, textured fabrics - cotton, wool, linen, suede - in earthy or muted tones.",
   "Minimal ornamentation; let the texture and the cut do the talking.",
   "Practical, mid-scale accessories: a leather satchel, a plain belt, a wooden bangle."],
  ["Stiff, sharp tailoring with exaggerated shoulders.",
   "Ornate detail - ruffles, sequins, lace - reads fussy against blunt, broad lines.",
   "Very fitted, body-conscious silhouettes that fight the width of the frame."],
  "Soft Natural - the softer sibling adds curve and wants gentle waist definition; pure Natural keeps it straighter."],
 'soft-natural' => ['Soft Natural', 'Broad frame, softened edges.',
  ["Relaxed shapes with a gently defined waist: a wrap dress in a soft fabric, a belted cardigan.",
   "Soft, slightly textured fabrics - brushed cotton, jersey, fine knit, washed silk.",
   "Rounded, unfussy details: a scoop neck, a soft blouse, a slightly gathered skirt.",
   "Natural, mid-scale accessories with a little softness - a rounded bag, a hoop earring."],
  ["Rigid tailoring and sharp shoulders overwhelm the softness.",
   "Heavy embellishment and very ornate prints.",
   "Anything that hides the waist entirely on a frame that has one."],
  "Flamboyant Natural - both are broad and relaxed, but Flamboyant Natural is longer and wants no waist emphasis; Soft Natural wants a little."],
 'dramatic-classic' => ['Dramatic Classic', 'Balanced lines, sharpened.',
  ["Precise tailoring with a slight edge: a sharp-lapel blazer, a pencil skirt, a tailored shirt dress.",
   "Smooth, medium-weight fabrics with structure - crepe, fine wool, cotton sateen.",
   "Symmetrical, clean lines and a little contrast: a crisp collar, a defined shoulder.",
   "Polished, geometric accessories: a structured bag, a pointed flat, a simple chain."],
  ["Slouchy, unstructured pieces look untidy rather than relaxed.",
   "Heavy ornamentation and romantic detail.",
   "Anything oversized - scale is moderate and the frame is balanced."],
  "Classic - pure Classic is fully balanced; Dramatic Classic carries a little sharpness and can take a bolder shoulder or line."],
 'classic' => ['Classic', 'Balanced, clean, symmetrical.',
  ["Balanced, symmetrical, moderate everything: a well-cut blazer, a knee-length skirt, a simple sheath.",
   "Smooth, refined fabrics - fine wool, silk blends, quality cotton - in clean solids or subtle patterns.",
   "Timeless details: a notched collar, a straight hem, a neat cuff.",
   "Understated, medium-scale accessories: pearl studs, a leather loafer, a classic tote."],
  ["Anything extreme - oversized, ultra-fitted, very ornate or very undone.",
   "Loud prints and heavy texture that disrupt the smoothness.",
   "Trend-driven pieces with exaggerated proportions."],
  "Soft Classic adds a little curve and softness; Dramatic Classic adds a little edge. Pure Classic sits right in the middle."],
 'soft-classic' => ['Soft Classic', 'Balanced lines, softened.',
  ["Balanced shapes softened at the edges: a rounded lapel, a slightly gathered sleeve, a soft sheath.",
   "Smooth fabrics with a little drape - crepe de chine, soft wool, matte jersey.",
   "Gentle waist definition and rounded necklines.",
   "Refined, slightly delicate accessories: a small pendant, a rounded-toe pump, a soft leather bag."],
  ["Sharp, angular tailoring and hard geometric details.",
   "Anything heavy, chunky or oversized.",
   "Overly ornate or frilly pieces - soft, not fussy."],
  "Soft Natural - both are soft, but Soft Natural is broader and more relaxed; Soft Classic stays polished and symmetrical."],
 'flamboyant-gamine' => ['Flamboyant Gamine', 'Compact frame, sharp contrast.',
  ["Sharp, compact tailoring: a cropped jacket, a boxy blazer, a straight mini skirt.",
   "Crisp fabrics with graphic contrast - bold stripes, colourblocking, geometric prints.",
   "Broken-up lines and mixed pieces rather than one long silhouette.",
   "Playful, angular accessories: a chunky sneaker, a geometric earring, a small structured bag."],
  ["Long, unbroken flowing lines that swallow a compact frame.",
   "Soft, draped, romantic detail.",
   "Oversized pieces with no shape."],
  "Gamine - pure Gamine is more mixed and playful; Flamboyant Gamine leans sharper and a touch longer in line."],
 'gamine' => ['Gamine', 'Compact, mixed, playful.',
  ["Compact, mixed shapes: a fitted top with a straight skirt, a short jacket over a slim dress.",
   "Crisp fabrics and lively details - contrast trim, buttons, small graphic prints.",
   "Broken lines and a bit of contrast in every outfit; a mix of sharp and soft.",
   "Small-to-medium, playful accessories: a beret, a small crossbody, a stud earring."],
  ["Long, heavy, flowing pieces that overwhelm a small frame.",
   "Very plain, monochrome, unbroken looks that read flat.",
   "Large-scale accessories and big prints."],
  "Soft Gamine adds roundness and wants softer fabrics; pure Gamine is crisper and more mixed."],
 'soft-gamine' => ['Soft Gamine', 'Compact frame, curve-leaning.',
  ["Compact shapes with a little curve: a fitted cardigan, a rounded-collar blouse, a flared mini skirt.",
   "Soft, slightly textured fabrics - fine knit, cotton with stretch, lightweight tweed.",
   "Playful, rounded detail: a peter pan collar, a puff sleeve, a bow at small scale.",
   "Small, sweet accessories: a rounded bag, a ballet flat, a small hoop."],
  ["Long, straight, severe lines and hard geometry.",
   "Oversized, heavy or very structured pieces.",
   "Large-scale prints and chunky jewellery."],
  "Theatrical Romantic - both are petite and curvy, but Theatrical Romantic is fully rounded and glamorous; Soft Gamine keeps some crispness and contrast."],
 'theatrical-romantic' => ['Theatrical Romantic', 'Curved lines, sharpened.',
  ["Fitted, curve-following shapes with a sharp accent: a corseted top, a wrap dress with a deep V, a pencil skirt.",
   "Luxurious, fluid fabrics - silk, satin, velvet, fine lace - that hug rather than hang.",
   "Waist emphasis always, plus one sharp detail: a pointed shoe, a defined shoulder, a bold earring.",
   "Small-scale, glamorous accessories: delicate but sparkly, never chunky."],
  ["Boxy, loose or heavy pieces that hide the curve.",
   "Rough, matte, oversized textures.",
   "Anything very long and unbroken - the frame is compact."],
  "Romantic - pure Romantic is fully soft and rounded; Theatrical Romantic carries a sharp edge and can take a pointed toe or a defined shoulder."],
 'romantic' => ['Romantic', 'Curved, soft, rounded.',
  ["Soft, curve-following silhouettes: a draped dress, a rounded neckline, a gathered waist.",
   "Plush, fluid fabrics - velvet, chiffon, jersey, cashmere, soft lace.",
   "Rounded detail everywhere: ruffles at small scale, soft bows, a scoop or sweetheart neckline.",
   "Delicate, rounded, slightly ornate accessories: a pearl drop, a soft satchel, a rounded-toe heel."],
  ["Sharp tailoring, straight boxy lines and hard geometry.",
   "Rough or stiff textures - heavy denim, canvas, leather with a hard edge.",
   "Oversized, long, unbroken pieces."],
  "Soft Classic - both are soft, but Soft Classic is balanced and polished; Romantic is all curve and needs the fabric to move."],
];

/** id => [name, blurb, best, fights, swatches[]] — names/blurbs/swatches match
 *  COLOR_SEASONS in index.html; "best" and "fights" restate that season's own
 *  undertone/depth/chroma/contrast targets in words a stylist would use. */
const SEASON_GUIDE = [
 'light-spring' => ['Light Spring', 'Warm and light, with soft rather than intense colouring.',
  'Warm and light - peach, coral, soft aqua, buttery yellow. Keep values close together; nothing very deep.',
  'Black, charcoal, heavy jewel tones, and anything cool and icy.',
  ['#FFD1B0','#FFA98F','#A9E5C5','#FCE79A','#AFD8E8','#F0C6DE']],
 'true-spring' => ['True Spring', 'Warm and clear, with medium depth and a bright, golden glow.',
  'Warm and clear at full clarity - golden yellow, coral, turquoise, grass green.',
  'Dusty or greyed-off shades, and cool blue-based darks.',
  ['#FFB27A','#FF8C69','#FFC845','#7CB518','#FF9F45','#2FBFB0']],
 'bright-spring' => ['Bright Spring', 'Warm and vivid - the brightest, clearest colouring in the spring family.',
  'Hot, clear brights - poppy, turquoise, bright pink, golden yellow.',
  'Muted, dusty or earthy shades; they go flat.',
  ['#FF6F59','#E63946','#00C2C7','#2E86FF','#FF3D8A','#FFD23F']],
 'light-summer' => ['Light Summer', 'Cool and light, with soft, powdery colouring.',
  'Cool and powdery - soft blue, lilac, rose, sage. Keep the contrast low.',
  'Black, hot brights, and orange-based warms.',
  ['#B7D3E3','#CBB9DE','#E8B6C0','#B7CBB0','#C6C4C6','#A9B4E0']],
 'true-summer' => ['True Summer', 'Cool and muted, with medium depth and a gentle contrast.',
  'Cool and gently greyed - rose, teal, soft navy, plum.',
  'Orange, warm golds, neon, and stark black-against-white.',
  ['#C98C93','#4E9E9B','#8A6459','#A8425E','#7C93A6','#9C7A8C']],
 'soft-summer' => ['Soft Summer', 'Cool, with the softest, most muted colouring of the four seasons.',
  'Muted and cool with values kept close - sage, dusty blue, mauve, soft taupe, rose-brown.',
  'Pure black next to white, hot brights, and any orange or golden warmth.',
  ['#94A88D','#7691A8','#8B6B84','#B4A79B','#C08A8F','#6E7A82']],
 'soft-autumn' => ['Soft Autumn', 'Warm and muted, with earthy, gently blended colouring.',
  'Warm and softened - camel, terracotta, olive, soft teal.',
  'Icy cool pastels and hard black.',
  ['#C69C6D','#A98E71','#C68A78','#5C8C82','#808B4A','#C1714A']],
 'true-autumn' => ['True Autumn', 'Warm and rich, with medium-deep, earthy colouring.',
  'Warm and earthy at full depth - rust, olive, mustard, brick, deep teal.',
  'Cool pastels, pure white, anything icy.',
  ['#CC5B26','#6E7A2E','#B0472C','#D4A017','#7A4B2A','#2E7C6E']],
 'deep-autumn' => ['Deep Autumn', 'Warm and deep, with rich, high-contrast colouring.',
  'Deep warm darks - chocolate, forest, burgundy, bronze - with real contrast between pieces.',
  'Light dusty pastels, which wash out against this depth.',
  ['#4A2E1E','#4B4A20','#6E1F2C','#A0522D','#1F4C4A','#8C6B2E']],
 'deep-winter' => ['Deep Winter', 'Cool and deep, with dramatic, high-contrast colouring.',
  'Cool darks with a clear bright beside them - black, true red, emerald, deep navy, icy pink.',
  'Muted earth tones, camel, warm beige.',
  ['#14151A','#C2172A','#0E6B4F','#12213D','#F0C6D6','#4B2258']],
 'true-winter' => ['True Winter', 'Cool and clear, with striking, high-contrast colouring.',
  'Pure cool colours at full clarity - true red, royal blue, magenta, emerald, stark white and black.',
  'Anything dusty, muted or warm-golden.',
  ['#F3D0DE','#1A5DBF','#C7268C','#12805F','#DCEAF5','#F5E11B']],
 'bright-winter' => ['Bright Winter', 'Cool and the most vivid of all - icy, jewel-bright colouring.',
  'Icy and jewel-bright - electric blue, hot pink, turquoise, true red.',
  'Soft muted tones and warm earths.',
  ['#1E6DF0','#FF2D87','#00C2D1','#E8112D','#8A2BE2','#A6D608']],
];

/** "Flamboyant Gamine" and "flamboyant-gamine" both have to work: the client
 *  sends the display name, the stored quiz result carries the id. */
function style_slug(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z]+/', '-', $s);
    return trim((string)$s, '-');
}

function kibbe_brief(string $typeIdOrName): ?array {
    $k = style_slug($typeIdOrName);
    if ($k === '' || !isset(KIBBE_GUIDE[$k])) return null;
    [$name, $tag, $works, $avoid, $mixup] = KIBBE_GUIDE[$k];
    return compact('name', 'tag', 'works', 'avoid', 'mixup') + ['id' => $k];
}

function season_brief(string $seasonIdOrName): ?array {
    $k = style_slug($seasonIdOrName);
    if ($k === '' || !isset(SEASON_GUIDE[$k])) return null;
    [$name, $blurb, $best, $fights, $swatches] = SEASON_GUIDE[$k];
    return compact('name', 'blurb', 'best', 'fights', 'swatches') + ['id' => $k];
}

/**
 * Everything the app already knows about this person's style, read from the
 * profiles row rather than from the request.
 *
 * Reading it server-side is the point: the apps already in people's hands
 * send only a type name, and some send nothing at all. Pulling it from the
 * row means an upgrade to the stylist reaches every build that is already
 * shipped, with no new release.
 *
 * Returns ['typeId','typeName','seasonId','styleWords'] with '' / [] where
 * nothing is on file. Never throws - a stylist call must not fail because a
 * profile column is missing.
 */
function style_profile(PDO $pdo, string $accountId): array {
    $out = ['typeId' => '', 'typeName' => '', 'seasonId' => '', 'styleWords' => []];
    if ($accountId === '') return $out;
    try {
        $st = $pdo->prepare('SELECT kibbe_type_name, style_words, kibbe_result_json, color_result_json,
                                    style_blend_json
                               FROM profiles WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $row = $st->fetch();
        if (!$row) return $out;

        $out['typeName'] = trim((string)($row['kibbe_type_name'] ?? ''));
        $kr = json_decode((string)($row['kibbe_result_json'] ?? ''), true);
        if (is_array($kr) && !empty($kr['kibbeTypeId'])) $out['typeId'] = (string)$kr['kibbeTypeId'];
        if ($out['typeId'] === '' && $out['typeName'] !== '') $out['typeId'] = style_slug($out['typeName']);

        $cr = json_decode((string)($row['color_result_json'] ?? ''), true);
        if (is_array($cr) && !empty($cr['seasonId'])) $out['seasonId'] = (string)$cr['seasonId'];

        $sw = json_decode((string)($row['style_words'] ?? ''), true);
        if (is_array($sw)) {
            foreach (array_slice($sw, 0, 5) as $w) {
                $w = trim((string)$w);
                if ($w !== '') $out['styleWords'][] = $w;
            }
        }
        // The blend is the ranked source; style_words is a snapshot that can
        // be stale or unordered. Prefer the blend when it has real weights.
        $sb = json_decode((string)($row['style_blend_json'] ?? ''), true);
        if (is_array($sb) && $sb) {
            arsort($sb);
            $top = [];
            foreach ($sb as $word => $score) {
                if ((float)$score <= 0) break;
                $top[] = (string)$word;
                if (count($top) >= 4) break;
            }
            if (count($top) >= 3) $out['styleWords'] = $top;
        }
    } catch (Throwable $e) {
        error_log('style_profile: ' . $e->getMessage());
    }
    return $out;
}

/**
 * The block every AI surface puts in front of the model.
 *
 * The last paragraph is not boilerplate. Kibbe is written about constantly
 * and often wrongly, and a model left to its own recollection will happily
 * contradict the app's own type pages. Telling it which source wins is what
 * keeps the paid stylist and the free /types guide saying the same thing.
 */
function style_brief_text(array $ctx, string $wardrobe = ''): string {
    $type   = isset($ctx['typeId']) && $ctx['typeId'] !== '' ? kibbe_brief($ctx['typeId'])
            : (isset($ctx['typeName']) ? kibbe_brief((string)$ctx['typeName']) : null);
    $season = isset($ctx['seasonId']) && $ctx['seasonId'] !== '' ? season_brief((string)$ctx['seasonId']) : null;
    $words  = array_values(array_filter(array_map('strval', $ctx['styleWords'] ?? [])));

    $out = [];
    if ($type) {
        $out[] = "KIBBE TYPE: {$type['name']} - {$type['tag']}";
        $out[] = "What suits these lines:";
        foreach ($type['works'] as $w) $out[] = "  - {$w}";
        $out[] = "What fights them:";
        foreach ($type['avoid'] as $a) $out[] = "  - {$a}";
        $out[] = "Most often confused with: {$type['mixup']}";
    } else {
        $out[] = "KIBBE TYPE: not on file. Judge on general fit, proportion and colour instead, and do not guess a type.";
    }

    if ($season) {
        $out[] = "";
        $out[] = "COLOUR SEASON: {$season['name']} - {$season['blurb']}";
        $out[] = "  Wears best: {$season['best']}";
        $out[] = "  Fights: {$season['fights']}";
        $out[] = "  Anchor shades: " . implode(' ', $season['swatches']);
    }

    if ($words) {
        $out[] = "";
        $out[] = "THEIR OWN STYLE WORDS, strongest first: " . implode(', ', array_slice($words, 0, 4))
               . ". These are how they want to look; the type is how the clothes should be cut. Both have to hold.";
    }

    if ($wardrobe === 'men') {
        $out[] = "";
        $out[] = "WARDROBE: menswear only. Every suggestion must be a menswear piece - shirts, knits, trousers, "
               . "tailoring, outerwear, men's shoes and accessories. Never suggest a dress, skirt, heels or a blouse. "
               . "Describe fit in menswear terms: drop, rise, break, collar, shoulder line.";
    } elseif ($wardrobe === 'women') {
        $out[] = "";
        $out[] = "WARDROBE: womenswear.";
    } elseif ($wardrobe === 'both') {
        $out[] = "";
        $out[] = "WARDROBE: both menswear and womenswear - suggest from either, and do not assume a gender.";
    }

    $out[] = "";
    $out[] = "The guidance above is this app's own system and is what the person reads on our type pages. "
           . "Where your own recollection of Kibbe differs from it, follow what is written above. Do not "
           . "introduce yin/yang vocabulary, type letters, or rules that are not here.";

    return implode("\n", $out);
}

/**
 * A compact list of what this person actually owns, for the stylist to
 * suggest from. Descriptions only - never a photo, never a price.
 *
 * This is what turns "add a pop of colour" into "the red patent ankle boots
 * you already have". A suggestion someone can act on tonight, with a
 * garment already in their wardrobe, is worth more than a shopping list.
 */
function closet_lines(PDO $pdo, string $accountId, int $limit = 40): array {
    if ($accountId === '') return [];
    try {
        $st = $pdo->prepare(
            'SELECT description FROM closet_items
              WHERE account_id = ? AND deleted_at IS NULL AND description <> ""
              ORDER BY created_at DESC LIMIT ' . max(1, min(80, $limit))
        );
        $st->execute([$accountId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $d = trim((string)$r['description']);
            if ($d !== '') $out[] = mb_substr($d, 0, 90);
        }
        return $out;
    } catch (Throwable $e) {
        error_log('closet_lines: ' . $e->getMessage());
        return [];
    }
}
