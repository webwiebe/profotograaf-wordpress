# Agents

Read `CONTRIBUTING.md` for the layout and `docs/quality.md` for the gates before changing code.

## Specs And Constitution

This repo has the minimal spec layer: the documents in `docs/`, indexed in `docs/README.md` with a Drift table. It has no per-area `spec.md` files and no constitution.

1. A PR that changes behaviour updates the document in `docs/` that describes it in the same PR, including its row in the Drift table of `docs/README.md`.
2. When a document in `docs/` and the code disagree, the code is the fact. Add a Drift row, then fix the document that is wrong, or open an issue when the code is what is wrong.

The proof contract of a change lives in the issue that asks for it, under the heading `## Proof contract`, as checkboxes that each state one observable outcome.
