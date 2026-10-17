# Documents

The documents in this folder describe how the plugin behaves and how it is checked. They are the spec layer of this repo (the minimal tier of the estate spec standard): an index, a Drift table and, per change, a proof contract in the issue.

All documents were last verified against the tree on 2026-10-17 at bd2bf0e.

The code is the fact. Where a document and the code disagree, a row goes into the Drift table below, then the document that is wrong gets fixed, or an issue is opened when the code is what is wrong. A PR that changes behaviour updates the document it touches and its Drift rows in the same PR.

## Index

| Document | Status | What it covers |
|---|---|---|
| [backup-and-pwa.md](backup-and-pwa.md) | Shipped | Copying Media Library files to private Profotograaf galleries, and linking clients to installable share pages. |
| [directory-submission.md](directory-submission.md) | Partial | The wordpress.org plugin directory submission checklist, guideline status and release steps. |
| [full-page-gallery.md](full-page-gallery.md) | Shipped | The full-page gallery block: presets, section vocabulary, sequencer rules, REST preview, markup contract and view beacon. |
| [gallery-display-matrix.md](gallery-display-matrix.md) | Shipped | Every gallery block and shortcode display option, the layer that applies it, states and E2E coverage. |
| [media-lifecycle.md](media-lifecycle.md) | Shipped | Syncing imported photo copies with Profotograaf: removals, changes, conflicts, change-feed polling and opt-in upload. |
| [media-source.md](media-source.md) | Historical | The design note for offering Profotograaf photos as a WordPress media source. |
| [quality.md](quality.md) | Shipped | Every CI quality gate, its threshold, baseline and refresh command. |
| [telemetry.md](telemetry.md) | Shipped | Opt-in usage and error telemetry: data, destinations, batching, consent and retention. |
| [virtual-attachments.md](virtual-attachments.md) | Shipped | Virtual attachments: what was built, the WordPress compatibility matrix, limits, conversion and removal. |

`review/` holds screenshots taken for reviews. They are results, not specs.

## Drift

No known drift. The documents were corrected under [#309](https://github.com/webwiebe/profotograaf-wordpress/issues/309), and the code fixes that came out of the audit are tracked in [#308](https://github.com/webwiebe/profotograaf-wordpress/issues/308). When a document and the tree disagree, add a row here with the claim, the document line and what the tree says, and fix it in the same change where you can. Where the tree cannot answer, write `not established`.

| Claim | Source | Tree |
|---|---|---|

## Related

- [CONTRIBUTING.md](../CONTRIBUTING.md) for the layout, the extension points and CI.
- [AGENTS.md](../AGENTS.md) for the rule agents follow when they change behaviour.
