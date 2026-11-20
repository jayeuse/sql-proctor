# Frontend styling checklist: &lt;screen or component&gt;

> Template. Use it for every change that adds or changes a rendered surface. It checks the rules in `AGENTS.md` "Frontend styling rules". Tailwind CSS is the mandatory styling system; the project has not installed it yet, so the first task that needs it installs it under its own approval.

| Field | Value |
|---|---|
| Screen or component | &lt;name and path&gt; |
| Spec | `SPEC-<nnn>` at `docs/specs/SPEC-<nnn>-<slug>.md` |
| Related task | TASK-&lt;nnn&gt; |
| Owner | &lt;role&gt; |
| Reviewer | &lt;role&gt; |
| Date | &lt;YYYY-MM-DD&gt; |

## Checklist

| # | Item | Done | Evidence |
|---|---|---|---|
| 1 | Tailwind used as the primary method. The markup uses Tailwind utilities, not a second styling system. | [ ] | &lt;file paths&gt; |
| 2 | Styles applied through `className`. No inline `style` except a computed value that a class cannot express. | [ ] | &lt;file paths&gt; |
| 3 | Responsive behavior checked at phone width (about 375px), tablet width (about 768px), and desktop width (1280px and wider). No horizontal scroll, overlap, or clipped text. | [ ] | &lt;screens checked and result&gt; |
| 4 | Accessibility checked. Semantic element, label, keyboard order, visible focus, contrast, and alternative text where needed. | [ ] | &lt;checks and result&gt; |
| 5 | UI states present: empty, loading, error, success, disabled, and a long or missing value. Each shows the same structure as the normal state. | [ ] | &lt;states seen&gt; |
| 6 | No unnecessary CSS file. No new `.css`, CSS Module, Sass, or framework for ordinary component styling. | [ ] | &lt;file list&gt; |
| 7 | Custom CSS justified. Each exception is one of the allowed cases and the reason is recorded next to the code or in the task. | [ ] | &lt;exception and reason&gt; |
| 8 | Component reuse evaluated. A repeated structure became a component; a one-off class string did not. | [ ] | &lt;decision&gt; |
| 9 | Consistency verified. Spacing, type scale, color, radius, and shadow match the rest of the application; design tokens live in `@theme` only when the project needs a shared token set. | [ ] | &lt;comparison result&gt; |

## Allowed CSS exceptions

Record the technical reason for each one:

1. The global Tailwind and Next.js stylesheet.
2. `@theme` design tokens.
3. Third-party styles that require a CSS import.
4. Monaco Editor styles.
5. A specialized animation or style that Tailwind cannot reasonably express.

## Verification

| Check | Command or method | Result | Evidence |
|---|---|---|---|
| Build | `cd frontend/sql-proctor-ui && npm run build` | PASS / FAIL / NOT RUN | &lt;path&gt; |
| Rendered check | Dev server at `http://localhost:3000`, desktop and phone width | PASS / FAIL / NOT RUN | &lt;note&gt; |
| Keyboard and focus | Tab through the surface; every control reachable with a visible focus ring | PASS / FAIL / NOT RUN | &lt;note&gt; |
| Contrast | Ratio meets WCAG AA for text and controls | PASS / FAIL / NOT RUN | &lt;method&gt; |

Do not take a screenshot sweep for approval. Review the surface live in the dev app. A skipped check stays `NOT RUN`.
