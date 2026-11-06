(() => {
  "use strict";

  const slugify = (value) =>
    value
      .normalize("NFKD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 180);

  const initSidebar = () => {
    const sidebar = document.getElementById("admin-sidebar");
    const toggle = document.querySelector("[data-sidebar-toggle]");
    const backdrop = document.querySelector("[data-sidebar-close]");

    if (!sidebar || !toggle) {
      return;
    }

    const setOpen = (open) => {
      sidebar.classList.toggle("is-open", open);
      document.body.classList.toggle("admin-nav-open", open);
      toggle.setAttribute("aria-expanded", String(open));
      if (backdrop) {
        backdrop.hidden = !open;
      }
    };

    toggle.addEventListener("click", () => {
      setOpen(!sidebar.classList.contains("is-open"));
    });

    if (backdrop) {
      backdrop.addEventListener("click", () => setOpen(false));
    }

    sidebar.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => setOpen(false));
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        setOpen(false);
      }
    });

    const desktop = window.matchMedia("(min-width: 900px)");
    const sync = () => {
      if (desktop.matches) {
        setOpen(false);
      }
    };

    if (typeof desktop.addEventListener === "function") {
      desktop.addEventListener("change", sync);
    } else {
      desktop.addListener(sync);
    }
  };

  const initConfirmations = () => {
    document.addEventListener("submit", (event) => {
      const form = event.target.closest("form[data-confirm]");
      if (!form) {
        return;
      }

      const message = form.getAttribute("data-confirm");
      if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    });
  };

  const initSlugs = () => {
    document.querySelectorAll("[data-slug-target]").forEach((target) => {
      const nameId = target.getAttribute("data-slug-name");
      const source = nameId
        ? document.getElementById(nameId)
        : document.querySelector("[data-slug-source]");

      if (!source) {
        return;
      }

      let auto = target.value === "" || target.value === slugify(source.value);

      target.addEventListener("input", () => {
        auto = target.value === "";
      });

      source.addEventListener("input", () => {
        if (!auto) {
          return;
        }
        target.value = slugify(source.value);
      });
    });
  };

  const initVariants = () => {
    const body = document.querySelector("[data-variant-body]");
    const addButton = document.querySelector("[data-add-variant]");
    const template = document.getElementById("variant-row-template");

    if (!body || !addButton || !template) {
      return;
    }

    const nextIndex = () => {
      const current = Number.parseInt(body.getAttribute("data-next-variant-index") || "0", 10);
      body.setAttribute("data-next-variant-index", String(current + 1));
      return current;
    };

    const addRow = () => {
      const index = nextIndex();
      const html = template.innerHTML.replaceAll("__INDEX__", String(index));
      body.insertAdjacentHTML("beforeend", html);
      const row = body.lastElementChild;
      if (row) {
        const firstInput = row.querySelector('input:not([type="hidden"])');
        if (firstInput) {
          firstInput.focus();
        }
        row.scrollIntoView({ block: "nearest", behavior: "smooth" });
      }
    };

    addButton.addEventListener("click", addRow);

    body.addEventListener("click", (event) => {
      const removeButton = event.target.closest("[data-remove-variant]");
      if (!removeButton) {
        return;
      }

      const row = removeButton.closest("tr");
      if (!row) {
        return;
      }

      const rows = body.querySelectorAll("tr");
      if (rows.length <= 1) {
        row.querySelectorAll("input").forEach((input) => {
          input.value = input.name.endsWith("[sort_order]") ? "0" : "";
        });
        return;
      }

      row.remove();
    });
  };

  const initImagePreviews = () => {
    const input = document.querySelector("[data-image-input]");
    const previews = document.querySelector("[data-image-previews]");
    const primaryWrap = document.getElementById("new-primary-wrap");
    const primarySelect = document.getElementById("new_primary_index");

    if (!input || !previews || !primaryWrap || !primarySelect) {
      return;
    }

    let objectUrls = [];

    const reset = () => {
      objectUrls.forEach((url) => URL.revokeObjectURL(url));
      objectUrls = [];
      previews.replaceChildren();
      primarySelect.length = 1;
      primaryWrap.hidden = true;
    };

    input.addEventListener("change", () => {
      reset();

      const files = Array.from(input.files || []).filter((file) =>
        file.type.startsWith("image/")
      );

      if (files.length === 0) {
        return;
      }

      primaryWrap.hidden = false;

      files.forEach((file, index) => {
        const url = URL.createObjectURL(file);
        objectUrls.push(url);

        const card = document.createElement("figure");
        card.className = "admin-image-preview";

        const image = document.createElement("img");
        image.src = url;
        image.alt = "";
        image.loading = "lazy";

        const label = document.createElement("span");
        label.textContent = file.name;

        card.append(image, label);
        previews.append(card);

        const option = document.createElement("option");
        option.value = String(index);
        option.textContent = file.name;
        primarySelect.append(option);
      });

      if (!previews.hasChildNodes()) {
        primaryWrap.hidden = true;
      }
    });
  };

  const initImageReorder = () => {
    document.addEventListener("click", (event) => {
      const button = event.target.closest("[data-image-move]");
      if (!button) {
        return;
      }

      const card = button.closest(".admin-image-card");
      const parent = card ? card.parentElement : null;
      if (!card || !parent) {
        return;
      }

      const direction = button.getAttribute("data-image-move") === "up" ? -1 : 1;
      const sibling =
        direction < 0 ? card.previousElementSibling : card.nextElementSibling;
      if (!sibling || !sibling.classList.contains("admin-image-card")) {
        return;
      }

      const sortInput = (element) =>
        element.querySelector('input[name*="[sort_order]"]');
      const current = sortInput(card);
      const other = sortInput(sibling);
      if (current && other) {
        const value = current.value;
        current.value = other.value;
        other.value = value;
      }

      if (direction < 0) {
        parent.insertBefore(card, sibling);
      } else {
        parent.insertBefore(sibling, card);
      }
    });
  };

  const init = () => {
    initSidebar();
    initConfirmations();
    initSlugs();
    initVariants();
    initImagePreviews();
    initImageReorder();
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
