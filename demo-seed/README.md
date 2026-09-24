# demo-seed

Artwork for the App Review demo account (`review@style-lore.com`), used to
fill the closet and the Community feed so the App Store screenshots show the
app doing something instead of an empty state.

Every file here is our own drawing, generated from
`mobile/scripts/demo-seed/` in the same visual language as `img/kibbe/*.svg`
— flat shapes, `#2A2622` outline, soft tinted card. No photographs and no
third-party imagery, so there is nothing to license and nothing to clear.

- `<client_id>.jpg` — one garment per closet item; the filename is the
  item's `client_id` in `closet_items`, which is what the seeding script
  matches on.
- `post-0N.jpg` — the three Community posts.

Served publicly because the seeding runs in the browser as the demo account
and fetches these by URL. They are inert: nothing in the app links here.
