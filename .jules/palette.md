## 2026-10-09 - Dynamic accessible names for draft controls

When a draft input control is rendered alongside an icon-only button (such as a pictogram swatch button for a new category), bind `aria-label` and `title` dynamically to `draftLabel.trim()` so screen readers state the name as typed rather than a generic fallback.
