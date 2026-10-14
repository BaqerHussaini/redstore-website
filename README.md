# RedStore

A responsive static storefront built with semantic HTML, modern CSS, and vanilla JavaScript. No build step or package installation is required.

## Run it

1. Extract the project folder.
2. Open `index.html` in a browser, or serve the folder with a local static server (recommended for development).

For example, if Python is installed:

```bash
npx serve .
# or
python3 -m http.server 8000
```

Then visit `http://localhost:8000` from the project directory.

## Structure

```
redstore-website/
├── index.html
├── assets/
│   ├── css/main.css      # tokens → base → layout → components → sections
│   ├── js/main.js        # mobile menu toggle, footer year
│   └── images/           # WebP photos; PNG logos, icons and badges
│       ├── brands/  ├── categories/  ├── hero/  ├── icons/
│       ├── logo/    ├── badges/      ├── products/  └── users/
└── README.md
```

## Notes

- Product names, prices and testimonials are placeholder content.
- Links with `href="#"` are placeholders to replace with real pages.
- Images are WebP with explicit `width`/`height` and lazy loading (the hero loads eagerly).
- Star icons are an inline SVG sprite, so no icon-font or Font Awesome download is needed.