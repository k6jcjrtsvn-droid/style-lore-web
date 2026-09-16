# Background removal — vendored runtime and model

Self-hosted so closet photo cutouts run entirely on the device, with no
third-party host and no CSP change (`connect-src 'self'` already covers
these; `script-src` already has `'wasm-unsafe-eval'` for MediaPipe).

| file | size | what it is |
|---|---|---|
| `u2netp.onnx` | 4.4 MB | U²-Netp, the lightweight U²-Net salient-object model, from the rembg release assets |
| `ort-wasm-simd-threaded.wasm` | 12 MB | ONNX Runtime Web 1.23.0 WASM build |
| `ort-wasm-simd-threaded.mjs` | 20 KB | its loader glue |
| `ort.wasm.min.mjs` | 49 KB | ONNX Runtime Web JS API (wasm-only build) |

Nothing here is fetched until someone taps "Clean up background" for the
first time — see `removeBackground()` in `index.html`.

## Why this model and not the usual one

The common choice (`@imgly/background-removal`) ships 168 MB / 84 MB / 42 MB
variants, and its speed comes from **WebGPU — which Capacitor's WebView does
not support** (ionic-team/capacitor#8044, closed as not planned), so the app
would have no GPU path at all. The CPU fallback needs multi-threaded WASM,
which needs `SharedArrayBuffer`, which needs COOP/COEP headers — and those
would break AdSense, Stripe Checkout and the YouTube embeds. Single-threaded
on that model is ~53 s on an M3 Max, i.e. unusable on a phone.

U²-Netp benchmarks within ~5% of the full 176 MB U²-Net, and where it loses
is fine detail (hair, foliage) rather than garment silhouettes.

## Measured, not assumed

Headless Chromium, single-threaded WASM, `crossOriginIsolated === false` —
the same conditions as the live site:

- session init **0.7 s**, inference **1.9 s** at 320×320
- the browser mask matched a Python/onnxruntime run of the same image to
  within 0.4% of foreground coverage

Expect roughly 4–8 s on a mid-range phone. That is why the UI shows a
spinner and does the work on an explicit tap, never automatically.

## The failure mode this has to guard against

On a close-up of fabric texture the model finds no garment-sized subject and
latches onto whatever is most salient — on a denim detail shot it kept two
buttons and erased the jacket. So `removeBackground()` refuses any result
whose mask covers less than 12% or more than 97% of the frame, and the UI
always shows the cutout as a preview with Keep / Use original. A background
remover that silently destroys someone's photo is worse than not having one.

## Updating

Model: the rembg release assets. Runtime: `onnxruntime-web@<version>/dist/`
on a CDN — take `ort.wasm.min.mjs`, `ort-wasm-simd-threaded.mjs` and
`ort-wasm-simd-threaded.wasm` together, they are version-matched. Do **not**
take the `.jsep` build (22 MB, WebGPU support we cannot use here).
