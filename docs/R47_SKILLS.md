# R-47 design skills record

Global owner rule: all UI work goes through the installed design skills. Each agent appends a section: which skills it invoked, what it applied, which were unavailable.

## academic agent (wave 1, functional views only)

- **impeccable**: invoked before writing the learner profile, locale switcher and operator views. `impeccable context` ran (no PRODUCT.md/DESIGN.md yet: the ds agent owns them). Applied from `craft-floor.md`, `clarify.md` and the i18n part of `harden.md`: persistent labels (placeholders only as examples), controls that name their action ("Save my learning profile", "Use this language"), errors that say what to fix, why birth month is asked, flexible widths for translated text (no fixed-width buttons or labels), `lang` attributes on language names, honest empty states, no eyebrow labels. `impeccable detect --json app/Domain/Academic/resources/views` returned no findings. Browser check at 390 px and 1440 px on the local server (profile form, locale switcher, operator levels screen).
- Not applied: no visual direction work (wave 2 restyles on the ds design system). `ui-ux-pro-max` and `emil-design-eng` were not used (dangling symlinks).
