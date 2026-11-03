# RedStore

A responsive static storefront built with semantic HTML, modern CSS, and vanilla JavaScript. No build step, package installation, or frameworks are required.

## Pages

- `index.html` — homepage with hero, categories, featured and latest products, offer, testimonials, and brands.
- `pages/products.html` — full product listing with sorting controls and pagination.

## Run it

1. Open `index.html` in a browser, or serve the project folder with a local static server (recommended for development).
2. From the project directory, run one of:

```bash
npx serve .
# or
python3 -m http.server 8000
```

Then visit `http://localhost:8000`.

## Structure

```
redstore-website/
├── index.html
├── pages/
│   └── products.html       # products listing page
├── assets/
│   ├── css/
│   │   ├── main.css        # imports the shared layers below
│   │   ├── base.css        # tokens, element defaults, utilities
│   │   ├── layout.css      # containers, section rhythm, grids
│   │   ├── components.css  # buttons, titles, ratings, cards, header, footer
│   │   └── pages/
│   │       ├── home.css    # homepage-only styles
│   │       └── products.css # products-page-only styles
│   ├── js/
│   │   ├── navigation.js   # mobile menu toggle, footer year
│   │   └── account-auth.js # login/register tab switching
│   └── images/
│       ├── brands/  ├── categories/  ├── hero/  ├── icons/
│       ├── logo/    ├── badges/      ├── products/  └── users/
└── README.md
```

## CSS architecture

Styles are split by responsibility so shared code stays reusable and page-specific code stays small:

- **`base.css`** — design tokens (`:root` custom properties), resets, typography defaults, focus styles, reduced-motion support, and utility classes.
- **`layout.css`** — page containers, vertical section rhythm, and reusable grid layouts.
- **`components.css`** — shared UI: buttons, section titles, ratings, product cards, site header, and footer.
- **`pages/*.css`** — styles used by a single page only, loaded after `main.css` so they can override shared rules.
- **`main.css`** — imports the shared layers in cascade order: base → layout → components.

To change the brand color, spacing, fonts, or shadows, edit the tokens at the top of `assets/css/base.css`.

## Notes

- Product names, prices, and testimonials are placeholder content.
- Links with `href="#"` are placeholders to replace with real pages.
- Images are WebP with explicit `width`/`height`; non-hero images use lazy loading.
- Star icons are an inline SVG sprite, so no icon-font or Font Awesome download is needed.
- The layout is mobile-first and includes a skip link, visible focus styles, and reduced-motion support.
