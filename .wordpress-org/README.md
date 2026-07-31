# WordPress.org assets

These files are **placeholders** — replace them with real artwork before/after
publishing. They are uploaded to the plugin page's SVN `assets/` folder by the
`deploy-wporg.yml` workflow (`ASSETS_DIR: .wordpress-org`). They are **not**
shipped inside the plugin zip (excluded via `.distignore`).

Required dimensions (WordPress.org):

| File                    | Size        | Purpose                          |
|-------------------------|-------------|----------------------------------|
| `icon-128x128.png`      | 128 × 128   | Plugin icon (search results)     |
| `icon-256x256.png`      | 256 × 256   | Plugin icon (retina)             |
| `banner-772x250.png`    | 772 × 250   | Header banner                    |
| `banner-1544x500.png`   | 1544 × 500  | Header banner (retina)           |
| `screenshot-1.png`      | any (keep ratio consistent) | Maps to readme "Screenshots" #1 |
| `screenshot-2.png`      | any         | Maps to readme "Screenshots" #2 |

Notes:
- `screenshot-N.png` filenames must match the order of the `== Screenshots ==`
  entries in `readme.txt`.
- An `icon.svg` is also accepted by WordPress.org in place of the PNG icons.
